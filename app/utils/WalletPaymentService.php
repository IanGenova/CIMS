<?php

namespace App\utils;

use App\utils\Wallet\FakeGateway;
use App\utils\Wallet\WalletGateway;
use App\utils\Wallet\XenditGateway;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Taking a wallet payment, and deciding whether it actually happened.
 *
 * THE ONE RULE
 *
 * Nothing is treated as paid unless the provider, asked directly, says it was
 * paid. Not because the browser said so, not because a callback said so. A
 * callback is a prompt to go and ask; its body is a claim from the open internet
 * and is never the evidence. Everything below follows from that.
 *
 * WHY A CALLBACK IS NOT ENOUGH ON ITS OWN
 *
 * A till on a shop's own network usually cannot receive one at all -- it sits
 * behind a router with no inbound route -- so polling is the primary mechanism
 * and callbacks are an optimisation for hosted deployments. Building it the
 * other way round produces a system that works in a demo and never settles a
 * payment in an actual shop.
 *
 * WHAT ELSE HAS TO BE CHECKED
 *
 * That the amount the provider reports matches what was asked for. A charge that
 * settles for less is not a payment, it is a discrepancy, and handing over goods
 * for it is the mistake this guards against.
 */
class WalletPaymentService
{
    /** Overridden in tests; null means build from config. */
    private static $gateway = null;

    public static function useGateway(WalletGateway $gateway = null)
    {
        self::$gateway = $gateway;
    }

    public static function gateway()
    {
        if (self::$gateway) {
            return self::$gateway;
        }

        return config('wallet.driver') === 'fake' ? new FakeGateway : new XenditGateway;
    }

    /** The wallets this shop can take, for the till to offer. */
    public static function channels()
    {
        $out = [];
        foreach ((array) config('wallet.channels') as $key => $spec) {
            $out[] = ['channel' => $key, 'label' => $spec['label'] ?? $key];
        }

        return $out;
    }

    public static function isConfigured()
    {
        // The key saved on System Settings → Payment Gateway, or the older .env one.
        return config('wallet.driver') === 'fake'
            || (string) Wallet\XenditGateway::secretKey() !== '';
    }

    /**
     * Ask the provider for a charge and give the till something to show.
     */
    public static function start(array $params)
    {
        $channel = strtoupper((string) ($params['channel'] ?? ''));
        $amount = round((float) ($params['amount'] ?? 0), 2);

        if (! isset(config('wallet.channels')[$channel])) {
            return ['error' => 'unknown_channel'];
        }
        if ($amount <= 0) {
            return ['error' => 'bad_amount'];
        }

        // Ours, and unguessable: a callback identifies a payment by this alone,
        // so a sequential reference would let anyone enumerate other people's.
        $reference = 'CIMS-' . bin2hex(random_bytes(12));

        $id = DB::table('wallet_payments')->insertGetId([
            'provider' => config('wallet.driver') === 'fake' ? 'fake' : 'xendit',
            'channel' => $channel,
            'reference_id' => $reference,
            'amount' => $amount,
            'currency' => config('wallet.currency', 'PHP'),
            'status' => 'pending',
            'sale_id' => $params['sale_id'] ?? null,
            'till_session_id' => $params['till_session_id'] ?? null,
            'warehouse_id' => $params['warehouse_id'] ?? null,
            'user_id' => Auth::check() ? Auth::user()->id : null,
            'expires_at' => now()->addMinutes((int) config('wallet.expires_after_minutes', 15)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = self::gateway()->createCharge([
            'reference_id' => $reference,
            'channel' => $channel,
            'amount' => $amount,
        ]);

        if (! $result['ok']) {
            DB::table('wallet_payments')->where('id', $id)->update([
                'status' => 'failed',
                'failure_reason' => substr((string) $result['error'], 0, 255),
                'last_response' => json_encode($result['raw']),
                'updated_at' => now(),
            ]);

            return ['error' => 'provider_refused', 'message' => $result['error']];
        }

        DB::table('wallet_payments')->where('id', $id)->update([
            'provider_charge_id' => $result['charge_id'],
            'action_type' => $result['action']['type'],
            'action_value' => $result['action']['value'],
            'last_response' => json_encode($result['raw']),
            'updated_at' => now(),
        ]);

        return [
            'payment' => DB::table('wallet_payments')->where('id', $id)->first(),
            'action' => $result['action'],
        ];
    }

    /**
     * Ask the provider what became of a charge, and record the answer.
     *
     * This is the only path by which a payment becomes paid.
     */
    public static function refresh($paymentId)
    {
        $payment = DB::table('wallet_payments')->where('id', $paymentId)->first();
        if (! $payment) {
            return ['error' => 'not_found'];
        }

        // Already decided, one way or the other.
        if (in_array($payment->status, ['paid', 'failed', 'cancelled', 'mismatch'], true)) {
            return ['payment' => $payment];
        }

        // Stop offering a charge the customer has walked away from, so it cannot
        // be attached to a later sale by a cashier who did not notice.
        if ($payment->expires_at && now()->greaterThan($payment->expires_at)) {
            DB::table('wallet_payments')->where('id', $paymentId)
                ->update(['status' => 'expired', 'updated_at' => now()]);

            return ['payment' => DB::table('wallet_payments')->where('id', $paymentId)->first()];
        }

        $result = self::gateway()->fetchCharge($payment->reference_id, $payment->provider_charge_id);

        if (! $result['ok']) {
            // A provider we cannot reach is not a provider saying no. Leave the
            // payment pending and let the till ask again.
            return ['payment' => $payment, 'warning' => $result['error']];
        }

        $status = $result['status'];
        $update = ['last_response' => json_encode($result['raw']), 'updated_at' => now()];

        if ($status === 'paid') {
            $reported = $result['amount'];

            // The amount has to match. A charge that settled for less than was
            // asked is a discrepancy for a person to look at, not a payment.
            if ($reported !== null && abs((float) $reported - (float) $payment->amount) > 0.005) {
                $update['status'] = 'mismatch';
                $update['failure_reason'] = sprintf(
                    'asked for %s, the provider settled %s',
                    number_format((float) $payment->amount, 2),
                    number_format((float) $reported, 2)
                );
            } else {
                $update['status'] = 'paid';
                // Only ever set once, so a second confirmation does not move it.
                if (! $payment->settled_at) {
                    $update['settled_at'] = now();
                }
            }
        } elseif (in_array($status, ['failed', 'expired', 'cancelled'], true)) {
            $update['status'] = $status;
        }

        DB::table('wallet_payments')->where('id', $paymentId)->update($update);

        return ['payment' => DB::table('wallet_payments')->where('id', $paymentId)->first()];
    }

    public static function isSettled($paymentId)
    {
        $payment = DB::table('wallet_payments')->where('id', $paymentId)->first();

        return (bool) ($payment && $payment->status === 'paid' && $payment->settled_at);
    }

    /**
     * A callback arrived. Check it is really from the provider, then go and ask
     * the provider what actually happened -- the body is never the evidence.
     *
     * Returns a short reason rather than throwing, because the answer is written
     * straight back to the provider as an HTTP status and it retries on anything
     * that is not a 2xx.
     */
    public static function handleCallback($token, array $body)
    {
        if (! self::gateway()->verifyCallback((string) $token)) {
            return 'bad_token';
        }

        $reference = $body['data']['reference_id']
            ?? ($body['reference_id'] ?? ($body['data']['id'] ?? null));

        if (! $reference) {
            return 'no_reference';
        }

        $payment = DB::table('wallet_payments')->where('reference_id', $reference)->first();
        if (! $payment) {
            return 'unknown';
        }

        self::refresh($payment->id);

        return 'ok';
    }

    /**
     * Put a settled payment against a sale.
     *
     * Refuses anything that is not settled, and refuses to do it twice -- a
     * wallet payment belongs to exactly one sale, and attaching it again would
     * show the sale as paid twice over.
     */
    public static function attachToSale($paymentId, $saleId)
    {
        return DB::transaction(function () use ($paymentId, $saleId) {
            $payment = DB::table('wallet_payments')->where('id', $paymentId)
                ->lockForUpdate()->first();

            if (! $payment || $payment->status !== 'paid' || ! $payment->settled_at) {
                return false;
            }
            if ($payment->sale_id && (int) $payment->sale_id !== (int) $saleId) {
                return false;                      // already belongs elsewhere
            }

            // Locked, so two attaches (or a cash payment) cannot both see it unpaid (K11).
            $sale = DB::table('sales')->where('id', $saleId)->lockForUpdate()->first();
            if (! $sale || $sale->deleted_at !== null) {
                return false;
            }
            // Only onto a sale in one of the user's warehouses (DATATEST K14).
            WarehouseAccess::assertAllows($sale->warehouse_id);
            // No money on a voided sale or one from a counted-down shift (DATATEST K15).
            SaleState::assertChangeable($sale, 'paid');
            // Never more than is still due (DATATEST K4): a 1,000.00 GCash payment
            // left a 265.00 sale paid 1,000.00. It stays unattached for a refund.
            $status = PaymentCap::status($sale, round((float) $sale->paid_amount + (float) $payment->amount, 2));

            // Already recorded against this sale.
            $existing = DB::table('payment_sales')
                ->where('sale_id', $saleId)
                ->where('notes', 'LIKE', '%' . $payment->reference_id . '%')
                ->whereNull('deleted_at')
                ->exists();

            if ($existing) {
                return false;
            }

            $label = config('wallet.channels')[$payment->channel]['label'] ?? $payment->channel;

            DB::table('payment_sales')->insert([
                'sale_id' => $saleId,
                'Ref' => app('App\Http\Controllers\PaymentSalesController')->getNumberOrder(),
                'date' => now()->toDateString(),
                'Reglement' => $label,
                'montant' => $payment->amount,
                'change' => 0,
                // The provider's reference is what ties this row back to the
                // charge, and is what a reconciliation is done against.
                'notes' => 'Wallet ' . $payment->reference_id,
                'user_id' => $payment->user_id ?: (Auth::check() ? Auth::user()->id : null),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $paid = round((float) $sale->paid_amount + (float) $payment->amount, 2);
            $due = round((float) $sale->GrandTotal - $paid, 2);

            DB::table('sales')->where('id', $saleId)->update([
                'paid_amount' => $paid,
                'payment_statut' => $status,
                'updated_at' => now(),
            ]);

            DB::table('wallet_payments')->where('id', $paymentId)
                ->update(['sale_id' => $saleId, 'updated_at' => now()]);

            return true;
        });
    }

    /**
     * The thing the customer actually points their phone at.
     *
     * Rendered on the server as an SVG rather than in the browser, so the till
     * needs no QR library, works with the page it already has, and keeps working
     * when the shop's connection is poor -- the image arrives with the charge
     * rather than being assembled afterwards.
     *
     * A provider that returns a checkout URL instead of a QR string is handled
     * the same way: a QR of the URL is how in-person wallet payment is normally
     * done, because the customer scans it and their own phone opens the page.
     */
    public static function qrFor($value)
    {
        $value = (string) $value;
        if ($value === '') {
            return null;
        }

        try {
            $renderer = new \BaconQrCode\Renderer\ImageRenderer(
                new \BaconQrCode\Renderer\RendererStyle\RendererStyle(320, 1),
                new \BaconQrCode\Renderer\Image\SvgImageBackEnd
            );
            $svg = (new \BaconQrCode\Writer($renderer))->writeString($value);

            return 'data:image/svg+xml;base64,' . base64_encode($svg);
        } catch (\Throwable $e) {
            // A till that cannot draw the code should say so plainly rather than
            // show a broken image and leave the cashier guessing.
            \Illuminate\Support\Facades\Log::warning('Could not render a payment QR: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Wallet money taken on a shift, for the drawer reading.
     *
     * Kept apart from cash on purpose: a wallet payment is revenue but it never
     * reaches the drawer, so counting it would leave every till short by the
     * day's wallet takings.
     */
    public static function takingsForSession($tillSessionId)
    {
        $rows = DB::table('wallet_payments')
            ->whereNull('deleted_at')
            ->where('till_session_id', $tillSessionId)
            ->where('status', 'paid')
            ->select('channel', DB::raw('COUNT(*) AS n'), DB::raw('SUM(amount) AS total'))
            ->groupBy('channel')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'channel' => $row->channel,
                'label' => config('wallet.channels')[$row->channel]['label'] ?? $row->channel,
                'count' => (int) $row->n,
                'amount' => round((float) $row->total, 2),
            ];
        }

        return $out;
    }
}
