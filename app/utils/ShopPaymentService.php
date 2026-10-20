<?php

namespace App\utils;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Models\PaymentSale;
use App\Models\Sale;

/** Hosted Xendit payment for a web order; stock remains reserved until staff fulfill it. */
class ShopPaymentService
{
    private static function fail($message)
    {
        throw ValidationException::withMessages(['payment' => [$message]]);
    }

    public static function latest($orderId)
    {
        return DB::table('shop_payment_sessions')->where('customer_order_id', $orderId)
            ->orderByDesc('id')->first();
    }

    public static function start($orderId, $orderUrl)
    {
        if (! XenditCheckout::configured()) self::fail('Online payment is not configured yet.');
        $prior = self::latest($orderId);
        if ($prior && $prior->status === 'pending' && $prior->expires_at <= now()) {
            self::refresh($prior);
        }
        [$order, $payment, $created] = DB::transaction(function () use ($orderId) {
            $order = DB::table('customer_orders')->where('id', $orderId)->lockForUpdate()->first();
            if (! $order || $order->status !== 'reserved' || $order->checkout_payment_method !== 'xendit') {
                self::fail('Online payment is available only for a fully reserved Xendit order.');
            }
            $latest = DB::table('shop_payment_sessions')->where('customer_order_id', $orderId)
                ->orderByDesc('id')->lockForUpdate()->first();
            if ($latest && ($latest->status === 'paid' || $latest->status === 'pending')) {
                return [$order, $latest, false];
            }
            if ($latest && in_array($latest->status, ['creating', 'review'], true)) {
                self::fail('This payment is being checked. Contact the store before starting another charge.');
            }
            $amount = round((float) $order->quoted_total, 2);
            if ($amount <= 0) self::fail('Online payment requires a positive order amount.');
            $reference = 'CIMS-WEB-' . $order->id . '-' . bin2hex(random_bytes(5));
            $id = DB::table('shop_payment_sessions')->insertGetId([
                'customer_order_id' => $orderId, 'reference_id' => $reference,
                'amount' => $amount, 'status' => 'creating',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return [$order, DB::table('shop_payment_sessions')->find($id), true];
        }, 5);
        if (! $created) return $payment;
        $id = $payment->id;
        $reference = $payment->reference_id;
        $amount = (float) $payment->amount;
        try {
            [$status, $body] = XenditCheckout::request('POST', '/sessions', [
                'reference_id' => $reference, 'session_type' => 'PAY',
                'mode' => 'PAYMENT_LINK', 'currency' => 'PHP', 'country' => 'PH',
                'amount' => $amount, 'description' => 'CIMS online order ' . $order->Ref,
                'success_return_url' => $orderUrl, 'cancel_return_url' => $orderUrl,
                'expires_at' => now()->addMinutes(30)->utc()->format('Y-m-d\TH:i:s\Z'),
                'metadata' => ['customer_order_id' => (string) $orderId],
            ]);
            if ($status < 200 || $status >= 300 || empty($body['payment_session_id'])
                || empty($body['payment_link_url'])) {
                self::fail('Xendit could not create the payment page. Please try again.');
            }
            DB::table('shop_payment_sessions')->where('id', $id)->update([
                'session_id' => $body['payment_session_id'],
                'payment_link_url' => $body['payment_link_url'],
                'expires_at' => isset($body['expires_at']) ? \Carbon\Carbon::parse($body['expires_at']) : now()->addMinutes(30),
                'status' => 'pending', 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            DB::table('shop_payment_sessions')->where('id', $id)->update([
                // An interrupted request may have created a live provider session.
                // Hold it for reconciliation instead of risking a second charge.
                'status' => 'review', 'updated_at' => now(),
            ]);
            throw $e;
        }
        return DB::table('shop_payment_sessions')->find($id);
    }

    /** Provider status is fetched server-side; redirects and webhook bodies are never proof of payment. */
    public static function refresh($payment, $force = false)
    {
        if (! $payment || ! in_array($payment->status, ['pending', 'review'], true)
            || ! $payment->session_id) return $payment;
        if (! $force && $payment->last_checked_at
            && \Carbon\Carbon::parse($payment->last_checked_at)->diffInSeconds(now()) < 5) return $payment;
        [$status, $body] = XenditCheckout::request('GET', '/sessions/' . rawurlencode($payment->session_id));
        if ($status < 200 || $status >= 300) return $payment;
        $state = strtoupper((string) ($body['status'] ?? ''));
        $next = in_array($state, ['EXPIRED', 'CANCELED', 'CANCELLED', 'FAILED'], true)
            ? 'failed' : $payment->status;
        if ($state === 'COMPLETED' && ! empty($body['payment_id'])) {
            $next = 'review';
            [$paymentStatus, $charge] = XenditCheckout::request('GET',
                '/v3/payments/' . rawurlencode($body['payment_id']));
            $captured = array_sum(array_map(function ($capture) {
                return (float) ($capture['capture_amount'] ?? 0);
            }, (array) ($charge['captures'] ?? [])));
            if ($paymentStatus >= 200 && $paymentStatus < 300
                && strtoupper((string) ($charge['status'] ?? '')) === 'SUCCEEDED'
                && ($charge['currency'] ?? '') === 'PHP'
                && $captured + 0.001 >= (float) $payment->amount) {
                $next = 'paid';
                DB::table('shop_payment_sessions')->where('id', $payment->id)->update([
                    'payment_id' => $body['payment_id'],
                    'payment_request_id' => $body['payment_request_id'] ?? null,
                ]);
            }
        } elseif ($state === 'COMPLETED') {
            $next = 'review';
        }
        DB::table('shop_payment_sessions')->where('id', $payment->id)
            ->whereIn('status', ['pending', 'review'])
            ->update(['status' => $next, 'last_checked_at' => now(), 'updated_at' => now()]);
        return DB::table('shop_payment_sessions')->find($payment->id);
    }

    public static function handleWebhook($sessionId)
    {
        $payment = DB::table('shop_payment_sessions')->where('session_id', $sessionId)->first();
        if ($payment) self::refresh($payment);
    }

    public static function forPayment($paymentSaleId)
    {
        return DB::table('shop_payment_sessions')->where('payment_sale_id', $paymentSaleId)->first();
    }

    public static function refund($payment, $outside = false)
    {
        if ($payment->refund_status) return;
        if ($outside) {
            DB::table('shop_payment_sessions')->where('id', $payment->id)->update([
                'refund_status' => 'OUTSIDE_XENDIT', 'updated_at' => now(),
            ]);
            return;
        }
        if (! $payment->payment_request_id) {
            self::fail('Xendit cannot identify this payment for an automatic refund. Record a manual refund first.');
        }
        [$status, $body] = XenditCheckout::request('POST', '/refunds', [
            'payment_request_id' => $payment->payment_request_id,
            'reason' => 'REQUESTED_BY_CUSTOMER',
            'reference_id' => 'CIMS-WEB-RF-' . $payment->id,
            'amount' => (float) $payment->amount, 'currency' => 'PHP',
        ]);
        if ($status < 200 || $status >= 300 || ($body['status'] ?? '') === 'FAILED') {
            self::fail('Xendit did not accept the refund. The payment was kept.');
        }
        DB::table('shop_payment_sessions')->where('id', $payment->id)->update([
            'refund_id' => $body['id'] ?? null,
            'refund_status' => $body['status'] ?? 'PENDING', 'updated_at' => now(),
        ]);
    }

    /** Carry a verified advance payment into the invoice once stock is issued. */
    public static function applyToSale($orderId, Sale $sale, $userId)
    {
        $payment = DB::table('shop_payment_sessions')->where('customer_order_id', $orderId)
            ->where('status', 'paid')->lockForUpdate()->first();
        if (! $payment) return;
        if ($payment->payment_sale_id) return;
        if (abs((float) $sale->GrandTotal - (float) $payment->amount) > 0.01) {
            self::fail('The final sale total differs from the online amount already paid. Reconcile this before fulfillment.');
        }
        $record = PaymentSale::create([
            'sale_id' => $sale->id,
            'Ref' => app(\App\Http\Controllers\PaymentSalesController::class)->getNumberOrder(),
            'date' => now(), 'Reglement' => 'Xendit',
            'montant' => $payment->amount, 'change' => 0,
            'user_id' => $userId, 'notes' => 'Online order payment · ' . $payment->session_id,
        ]);
        $sale->update(['paid_amount' => $payment->amount, 'payment_statut' => 'paid']);
        DB::table('shop_payment_sessions')->where('id', $payment->id)->update([
            'payment_sale_id' => $record->id, 'updated_at' => now(),
        ]);
    }
}
