<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\utils\TillService;
use App\utils\WalletPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Wallet payments at the till.
 *
 * Three of these are for the cashier and one is for the payment provider. The
 * provider's one is the only route in this application that is deliberately
 * unauthenticated, which is why it does the least: it verifies the token, then
 * hands off to the service, which ignores everything in the body except which
 * payment to go and ask about.
 */
class WalletPaymentController extends Controller
{
    /** What the till can offer, and whether it is set up at all. */
    public function channels(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        return response()->json([
            'configured' => WalletPaymentService::isConfigured(),
            'channels' => WalletPaymentService::channels(),
            'poll_seconds' => (int) config('wallet.poll_seconds', 3),
        ]);
    }

    /**
     * Ask the provider for a charge, and hand the till something to show.
     */
    public function start(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $request->validate([
            'channel' => 'required|string|max:32',
            'amount' => 'required|numeric|gt:0',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'sale_id' => 'nullable|integer|exists:sales,id',
        ]);

        if (! WalletPaymentService::isConfigured()) {
            return response()->json([
                'message' => 'Wallet payments are not set up. Add the provider keys to the environment first.',
            ], 422);
        }

        $session = TillService::currentFor(Auth::user()->id, $request->warehouse_id);

        $result = WalletPaymentService::start([
            'channel' => $request->channel,
            'amount' => $request->amount,
            'warehouse_id' => $request->warehouse_id,
            'sale_id' => $request->sale_id,
            'till_session_id' => $session ? $session->id : null,
        ]);

        if (isset($result['error'])) {
            $messages = [
                'unknown_channel' => 'That wallet is not one this till can take.',
                'bad_amount' => 'The amount has to be more than zero.',
                'provider_refused' => $result['message'] ?? 'The payment provider refused the charge.',
            ];

            return response()->json(
                ['message' => $messages[$result['error']] ?? 'The payment could not be started.'],
                422
            );
        }

        return response()->json([
            'payment' => $this->present($result['payment']),
            'action' => $result['action'],
        ]);
    }

    /**
     * Has the money arrived?
     *
     * The till asks this on a timer while the customer is paying. It always goes
     * to the provider rather than answering from what is stored, because what is
     * stored is only ever as fresh as the last time somebody asked.
     */
    public function status(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $result = WalletPaymentService::refresh($id);

        if (isset($result['error'])) {
            return response()->json(['message' => 'No such payment.'], 404);
        }

        return response()->json([
            'payment' => $this->present($result['payment']),
            'settled' => WalletPaymentService::isSettled($id),
            'warning' => $result['warning'] ?? null,
        ]);
    }

    /** Put a settled payment against a sale. */
    public function attach(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $request->validate(['sale_id' => 'required|integer|exists:sales,id']);

        if (! WalletPaymentService::attachToSale($id, $request->sale_id)) {
            return response()->json([
                'message' => 'That payment is not settled, or is already on a sale.',
            ], 422);
        }

        return response()->json(['success' => true]);
    }

    /**
     * The provider calling us.
     *
     * Unauthenticated by necessity and therefore assumed hostile: the token is
     * checked first, and the body is used for nothing but the reference it names.
     * Whether the payment succeeded is decided by asking the provider, never by
     * reading this request.
     *
     * Always answers 200 once the token is good, including for a reference we do
     * not recognise -- the provider retries anything that is not a 2xx for up to
     * a day, and there is nothing to gain from it retrying something we will
     * never know about.
     */
    public function callback(Request $request)
    {
        $header = (string) config('wallet.callback_header', 'x-callback-token');
        $token = (string) $request->header($header, '');

        $result = WalletPaymentService::handleCallback($token, (array) $request->all());

        if ($result === 'bad_token') {
            return response()->json(['message' => 'unauthorised'], 401);
        }

        return response()->json(['received' => true, 'result' => $result], 200);
    }

    /**
     * What the till is allowed to see. The stored provider response is
     * deliberately not included: it can carry customer details, and the till has
     * no use for it.
     */
    private function present($payment)
    {
        return [
            'id' => (int) $payment->id,
            'channel' => $payment->channel,
            'label' => config('wallet.channels')[$payment->channel]['label'] ?? $payment->channel,
            'reference_id' => $payment->reference_id,
            'amount' => round((float) $payment->amount, 2),
            'currency' => $payment->currency,
            'status' => $payment->status,
            'action_type' => $payment->action_type,
            'action_value' => $payment->action_value,
            // Drawn here so the till has nothing to render and no library to
            // load. Null when there is nothing to show yet, or the code could
            // not be drawn -- the till says so rather than showing a gap.
            'qr' => WalletPaymentService::qrFor($payment->action_value),
            'settled_at' => $payment->settled_at,
            'expires_at' => $payment->expires_at,
            'failure_reason' => $payment->failure_reason,
        ];
    }
}
