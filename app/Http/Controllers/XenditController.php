<?php

namespace App\Http\Controllers;

use App\Models\PaymentSale;
use App\Models\Role;
use App\Models\Sale;
use App\utils\XenditCheckout;
use App\utils\XenditException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Taking a payment through Xendit (see App\utils\XenditCheckout). */
class XenditController extends Controller
{
    /** A sale this person may take payment on: their branch, and their own unless record_view. */
    private function saleFor(Request $request, $saleId)
    {
        $sale = Sale::whereNull('deleted_at')->findOrFail($saleId);
        $role = Auth::user()->roles()->first();
        if (! Role::findOrFail($role->id)->inRole('record_view')) {
            $this->authorizeForUser($request->user('api'), 'check_record', $sale);
        }

        return $sale;
    }

    private function checkoutFor(Request $request, $id)
    {
        $checkout = DB::table('xendit_checkouts')->where('id', $id)->first();
        abort_unless($checkout, 404);
        $this->saleFor($request, $checkout->sale_id);

        return $checkout;
    }

    private static function failed(XenditException $e)
    {
        return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], 422);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', PaymentSale::class);
        $request->validate([
            'sale_id' => 'required|integer',
            'amount' => 'required|numeric|gt:0',
        ]);

        try {
            $checkout = XenditCheckout::start($this->saleFor($request, $request->sale_id), $request->amount, Auth::id());
        } catch (XenditException $e) {
            return self::failed($e);
        }

        return response()->json(['checkout' => XenditCheckout::present($checkout)]);
    }

    /** What the waiting screen asks every few seconds. */
    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'create', PaymentSale::class);
        $checkout = $this->checkoutFor($request, $id);

        try {
            $checkout = XenditCheckout::refresh($checkout);
        } catch (XenditException $e) {
            // Xendit unreachable for a moment: report what is known; the screen asks again.
        }

        return response()->json(['checkout' => XenditCheckout::present($checkout)]);
    }

    public function cancel(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'create', PaymentSale::class);
        $checkout = $this->checkoutFor($request, $id);

        try {
            $checkout = XenditCheckout::cancel($checkout);
        } catch (XenditException $e) {
            return self::failed($e);
        }

        return response()->json(['checkout' => XenditCheckout::present($checkout)]);
    }

    /**
     * Xendit's webhook. Public, so it proves itself with the token from the
     * Xendit dashboard; and even then it only prompts a direct check.
     */
    public function webhook(Request $request)
    {
        $expected = XenditCheckout::webhookToken();
        $given = (string) $request->header('x-callback-token');
        if (! $expected || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthorised'], 401);
        }

        try {
            XenditCheckout::handleWebhook($request->all());
            $shopSession = $request->input('data.payment_session_id');
            if ($shopSession) \App\utils\ShopPaymentService::handleWebhook($shopSession);
            // One address for the whole account: GCash/Maya charges made at
            // the till (App\utils\WalletPaymentService) are settled from here too.
            if (empty($request->input('data.payment_session_id'))) {
                \App\utils\WalletPaymentService::handleCallback($given, (array) $request->all());
            }
        } catch (\Throwable $e) {
            // Answer 200 regardless: the cashier's screen and Xendit's retries
            // will settle it, and a failure here must not loop the webhook.
            \Log::warning('Xendit webhook not processed: ' . $e->getMessage());
        }

        return response()->json(['received' => true]);
    }
}
