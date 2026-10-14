<?php

namespace App\utils;

use App\Http\Controllers\PaymentSalesController;
use App\Models\PaymentSale;
use App\Models\Sale;
use App\Models\Setting;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Xendit, the payment gateway: GCash, Maya, cards, QR Ph, GrabPay,
 * ShopeePay, online banking and over-the-counter, in pesos.
 *
 * A checkout is a request for an amount on one sale, made as a Xendit
 * Payment Session (session_type PAY, mode PAYMENT_LINK): Xendit hosts the
 * page, the customer scans its QR code and pays there. A payment row is
 * written only once Xendit says the session COMPLETED -- found either by
 * asking (the cashier's screen polls, so it works without a public address)
 * or by Xendit's webhook. A webhook is never believed on its own: it only
 * prompts the server to ask Xendit directly.
 *
 * Replaces Stripe, which charged pesos as US dollars and does not serve
 * Philippine businesses. tests/Feature/XenditCheckoutTest.php.
 */
class XenditCheckout
{
    const REGLEMENT = 'Xendit';

    /** "Xendit" however it was typed: "xendit " got past the guard (DATATEST K59). */
    public static function isXendit($method)
    {
        return strcasecmp(trim((string) $method), self::REGLEMENT) === 0;
    }

    const MINUTES_TO_PAY = 30;
    /** Not more often than this does a waiting screen make the server ask Xendit. */
    const ASK_EVERY_SECONDS = 2;
    const PAYMENTS_API_VERSION = '2024-11-11';

    private static $http;

    /** Tests swap in a stand-in Xendit. */
    public static function useHttp($client)
    {
        self::$http = $client;
    }

    // ------------------------------------------------------------- keys

    private static function settings()
    {
        return Setting::whereNull('deleted_at')->first();
    }

    public static function secretKey()
    {
        $s = self::settings();

        return $s && $s->xendit_secret_key ? $s->xendit_secret_key : null;
    }

    public static function webhookToken()
    {
        $s = self::settings();

        return $s && $s->xendit_webhook_token ? $s->xendit_webhook_token : null;
    }

    public static function configured()
    {
        return self::secretKey() !== null;
    }

    /** "test" or "live" from the key Xendit issued; null when there is none. */
    public static function mode()
    {
        $key = self::secretKey();
        if (! $key) {
            return null;
        }

        return strpos($key, 'xnd_production_') === 0 ? 'live' : 'test';
    }

    /** What System Settings shows: never the keys themselves. */
    public static function describe()
    {
        $key = self::secretKey();

        return [
            'provider' => 'xendit',
            'has_secret_key' => $key !== null,
            'secret_key_hint' => $key ? substr($key, -4) : '',
            'mode' => self::mode(),
            'has_webhook_token' => self::webhookToken() !== null,
            'webhook_url' => url('/api/xendit/webhook'),
        ];
    }

    /** A blank field keeps what is saved; $remove forgets both. */
    public static function saveKeys($secretKey, $webhookToken, $remove = false)
    {
        $s = self::settings();
        if ($remove) {
            $s->xendit_secret_key = null;
            $s->xendit_webhook_token = null;
        } else {
            if ($secretKey !== null && trim($secretKey) !== '') {
                $s->xendit_secret_key = trim($secretKey);
            }
            if ($webhookToken !== null && trim($webhookToken) !== '') {
                $s->xendit_webhook_token = trim($webhookToken);
            }
        }
        $s->save();
    }

    // ------------------------------------------------------------- talking to Xendit

    /**
     * A Windows PHP (WAMP) ships with no trusted-certificate list, so HTTPS
     * fails verification (cURL error 60). Use Windows' own store rather than
     * switching verification off. Same reasoning as WebPush::clientOptions().
     */
    private static function client()
    {
        if (self::$http) {
            return self::$http;
        }
        $options = ['timeout' => 20, 'connect_timeout' => 8, 'http_errors' => false];
        if (PHP_OS_FAMILY === 'Windows' && ! ini_get('curl.cainfo') && ! ini_get('openssl.cafile')) {
            $options['curl'] = [CURLOPT_SSL_OPTIONS => 16]; // CURLSSLOPT_NATIVE_CA
        }

        return new Client($options);
    }

    /** @return array [status, body] */
    private static function call($method, $path, array $body = null, array $headers = [])
    {
        $key = self::secretKey();
        if (! $key) {
            throw new XenditException('Xendit is not set up yet. Add its secret key in Settings → System Settings → Payment Gateway.');
        }

        $options = [
            'headers' => array_merge([
                'Authorization' => 'Basic ' . base64_encode($key . ':'),
                'Accept' => 'application/json',
            ], $headers),
        ];
        if ($body !== null) {
            $options['json'] = $body;
        }

        try {
            $response = self::client()->request($method, rtrim(config('services.xendit.base_url'), '/') . $path, $options);
        } catch (\Throwable $e) {
            Log::warning('Xendit unreachable: ' . $e->getMessage());
            throw new XenditException('Xendit could not be reached. Check the internet connection and try again.');
        }

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true) ?: []];
    }

    private static function refused($status, $body, $code = null)
    {
        if ($status === 401) {
            return new XenditException('Xendit did not accept the secret key. Check it in Settings → System Settings → Payment Gateway.', $code);
        }

        return new XenditException('Xendit refused: ' . ($body['message'] ?? ('HTTP ' . $status)), $code ?? ($body['error_code'] ?? null));
    }

    /** Settings' "Test connection": any answer but 401 means the key is real. */
    public static function testConnection()
    {
        [$status, $body] = self::call('GET', '/balance');
        if ($status === 401) {
            throw self::refused($status, $body);
        }

        return $status >= 200 && $status < 300
            ? 'Connected to Xendit (' . self::mode() . ' mode).'
            : 'Xendit accepted the key (' . self::mode() . ' mode).';
    }

    // ------------------------------------------------------------- checkouts

    /** Ask for $amount on this sale. Throws XenditException with words for the cashier. */
    public static function start(Sale $sale, $amount, $userId)
    {
        $amount = round((float) $amount, 2);
        $due = round((float) $sale->GrandTotal - (float) $sale->paid_amount, 2);
        if ($amount <= 0) {
            throw new XenditException('Enter an amount to collect.');
        }
        if ($amount > $due) {
            throw new XenditException($due <= 0
                ? 'This sale is already paid in full.'
                : 'Only ' . number_format($due, 2) . ' is still due on this sale.');
        }
        if (! self::configured()) {
            throw new XenditException('Xendit is not set up yet. Add its secret key in Settings → System Settings → Payment Gateway.');
        }

        $id = DB::table('xendit_checkouts')->insertGetId([
            'sale_id' => $sale->id, 'amount' => $amount, 'status' => 'pending',
            // Unique per attempt; Xendit allows 64 characters.
            'reference_id' => substr('CIMS-' . preg_replace('/[^A-Za-z0-9_-]/', '', $sale->Ref) . '-' . uniqid(), 0, 64),
            'user_id' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $checkout = DB::table('xendit_checkouts')->find($id);
        $company = optional(self::settings())->CompanyName;

        [$status, $body] = self::call('POST', '/sessions', [
            'reference_id' => $checkout->reference_id,
            'session_type' => 'PAY',
            'mode' => 'PAYMENT_LINK',
            'currency' => 'PHP',
            'country' => 'PH',
            'amount' => $amount,
            'description' => trim(($company ? $company . ' — ' : '') . 'Sale ' . $sale->Ref),
            'expires_at' => now()->addMinutes(self::MINUTES_TO_PAY)->utc()->format('Y-m-d\TH:i:s\Z'),
            'metadata' => ['sale_ref' => (string) $sale->Ref, 'checkout' => (string) $id],
        ]);

        if ($status < 200 || $status >= 300 || empty($body['payment_session_id'])) {
            DB::table('xendit_checkouts')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
            throw self::refused($status, $body);
        }

        DB::table('xendit_checkouts')->where('id', $id)->update([
            'session_id' => $body['payment_session_id'],
            'payment_link_url' => $body['payment_link_url'] ?? null,
            'expires_at' => isset($body['expires_at']) ? Carbon::parse($body['expires_at'])->setTimezone(config('app.timezone')) : now()->addMinutes(self::MINUTES_TO_PAY),
            'updated_at' => now(),
        ]);

        return DB::table('xendit_checkouts')->find($id);
    }

    /** Ask Xendit how a waiting checkout stands, at most every few seconds. */
    public static function refresh($checkout, $force = false)
    {
        if ($checkout->status !== 'pending' || ! $checkout->session_id) {
            return $checkout;
        }
        if (! $force && $checkout->last_checked_at
            && Carbon::parse($checkout->last_checked_at)->diffInSeconds(now()) < self::ASK_EVERY_SECONDS) {
            return $checkout;
        }

        DB::table('xendit_checkouts')->where('id', $checkout->id)->update(['last_checked_at' => now()]);
        [$status, $body] = self::call('GET', '/sessions/' . rawurlencode($checkout->session_id));
        if ($status < 200 || $status >= 300) {
            Log::warning('Xendit session lookup failed', ['status' => $status, 'checkout' => $checkout->id]);

            return DB::table('xendit_checkouts')->find($checkout->id);
        }

        return self::apply($checkout->id, $body);
    }

    /** Bring the checkout in line with what Xendit says about its session. */
    private static function apply($checkoutId, array $session)
    {
        $state = $session['status'] ?? null;

        if ($state === 'COMPLETED') {
            return self::complete($checkoutId, $session);
        }
        if ($state === 'EXPIRED' || $state === 'CANCELED') {
            DB::table('xendit_checkouts')->where('id', $checkoutId)->where('status', 'pending')->update([
                'status' => $state === 'EXPIRED' ? 'expired' : 'cancelled', 'updated_at' => now(),
            ]);
        }

        return DB::table('xendit_checkouts')->find($checkoutId);
    }

    /**
     * The money moved: record it, once. Even if the cashier had already
     * cancelled -- the customer paid, so the sale was paid.
     */
    private static function complete($checkoutId, array $session)
    {
        $paymentId = $session['payment_id'] ?? null;

        // Which channel, and what was captured, live on the payment, not the session.
        $channel = null;
        $paid = null;
        if ($paymentId) {
            [$status, $payment] = self::call('GET', '/v3/payments/' . rawurlencode($paymentId), null, ['api-version' => self::PAYMENTS_API_VERSION]);
            if ($status >= 200 && $status < 300) {
                $channel = $payment['channel_code'] ?? null;
                if (! empty($payment['captures'])) {
                    $paid = round(array_sum(array_map(function ($c) {
                        return (float) ($c['capture_amount'] ?? 0);
                    }, $payment['captures'])), 2);
                }
            }
        }

        $recorded = DB::transaction(function () use ($checkoutId, $session, $paymentId, $channel, $paid) {
            $checkout = DB::table('xendit_checkouts')->where('id', $checkoutId)->lockForUpdate()->first();
            if ($checkout->payment_sale_id) {
                return null;
            }

            $amount = $paid ?: (float) $checkout->amount;
            $sale = Sale::withoutGlobalScopes()->lockForUpdate()->findOrFail($checkout->sale_id);
            $totalPaid = round((float) $sale->paid_amount + $amount, 2);
            $due = round((float) $sale->GrandTotal - $totalPaid, 2);

            $payment = PaymentSale::create([
                'sale_id' => $sale->id,
                'Ref' => app(PaymentSalesController::class)->getNumberOrder(),
                'date' => now(),
                'Reglement' => self::REGLEMENT,
                'montant' => $amount,
                'change' => 0,
                'notes' => trim('Xendit ' . ($channel ?: '') . ' · ' . $checkout->session_id),
                'user_id' => $checkout->user_id,
            ]);

            $sale->update([
                'paid_amount' => $totalPaid,
                'payment_statut' => $due <= 0 ? 'paid' : ($totalPaid > 0 ? 'partial' : 'unpaid'),
            ]);

            DB::table('xendit_checkouts')->where('id', $checkoutId)->update([
                'status' => 'paid', 'payment_id' => $paymentId,
                'payment_request_id' => $session['payment_request_id'] ?? null,
                'channel' => $channel, 'paid_at' => now(), 'payment_sale_id' => $payment->id, 'updated_at' => now(),
            ]);

            return ['sale' => $sale, 'amount' => $amount, 'overpaid' => $due < 0, 'user_id' => $checkout->user_id];
        });

        if ($recorded) {
            // The cashier may have walked away from the screen; tell them.
            $sale = $recorded['sale'];
            if ($recorded['user_id']) {
                NotificationService::toUser($recorded['user_id'], 'xendit_paid',
                    $sale->Ref . ': ₱' . number_format($recorded['amount'], 2) . ' received through Xendit' . ($channel ? ' (' . $channel . ')' : ''),
                    null, '/app/sales/detail/' . $sale->id, 'success');
            }
            if ($recorded['overpaid']) {
                NotificationService::broadcast('Sales_edit', 'xendit_overpaid',
                    $sale->Ref . ' has been paid more than its total',
                    'A Xendit payment arrived after the sale was already settled. Refund the difference to the customer.',
                    '/app/sales/detail/' . $sale->id, 'danger');
            }
        }

        return DB::table('xendit_checkouts')->find($checkoutId);
    }

    /** The cashier gives up waiting. If it was paid meanwhile, it stays paid. */
    public static function cancel($checkout)
    {
        if ($checkout->status !== 'pending') {
            return $checkout;
        }

        [$status, $body] = self::call('POST', '/sessions/' . rawurlencode($checkout->session_id) . '/cancel');
        if ($status >= 200 && $status < 300) {
            return self::apply($checkout->id, $body + ['status' => 'CANCELED']);
        }

        // Not ACTIVE any more: paid or expired at that same moment. Find out which.
        return self::refresh($checkout, true);
    }

    /** Xendit's webhook. Only ever a prompt to ask Xendit directly. */
    public static function handleWebhook(array $payload)
    {
        $data = $payload['data'] ?? [];
        $checkout = null;
        if (! empty($data['payment_session_id'])) {
            $checkout = DB::table('xendit_checkouts')->where('session_id', $data['payment_session_id'])->first();
        } elseif (! empty($data['payment_id'])) {
            $checkout = DB::table('xendit_checkouts')->where('payment_id', $data['payment_id'])->first();
        }

        if ($checkout) {
            self::refresh($checkout, true);
        }
    }

    // ------------------------------------------------------------- refunds

    /** The Xendit checkout behind a recorded payment, if it came through Xendit. */
    public static function forPayment($paymentSaleId)
    {
        return DB::table('xendit_checkouts')->where('payment_sale_id', $paymentSaleId)->first();
    }

    /**
     * Give the money back through Xendit before the payment is removed.
     * Throws XenditException (code XENDIT_REFUND_REFUSED) when Xendit will not,
     * e.g. QR Ph and over-the-counter payments, which cannot be refunded.
     */
    public static function refund($checkout)
    {
        if ($checkout->refund_id) {
            return;
        }
        if (! $checkout->payment_request_id) {
            throw new XenditException('Xendit has no payment on record to refund for this.', 'XENDIT_REFUND_REFUSED');
        }

        [$status, $body] = self::call('POST', '/refunds', [
            'payment_request_id' => $checkout->payment_request_id,
            'reason' => 'REQUESTED_BY_CUSTOMER',
            'reference_id' => 'CIMS-RF-' . $checkout->id,
            'amount' => (float) $checkout->amount,
            'currency' => 'PHP',
        ]);

        if ($status < 200 || $status >= 300 || ($body['status'] ?? null) === 'FAILED') {
            throw self::refused($status, $body, 'XENDIT_REFUND_REFUSED');
        }

        DB::table('xendit_checkouts')->where('id', $checkout->id)->update([
            'refund_id' => $body['id'] ?? null, 'refund_status' => $body['status'] ?? 'PENDING', 'updated_at' => now(),
        ]);
    }

    /** Refunded by hand (cash back at the counter): note it, ask Xendit nothing. */
    public static function markRefundedOutside($checkout)
    {
        DB::table('xendit_checkouts')->where('id', $checkout->id)->update([
            'refund_status' => 'OUTSIDE_XENDIT', 'updated_at' => now(),
        ]);
    }

    /** True while a sale holds Xendit money that has not been given back. */
    public static function saleHoldsXenditMoney($saleId)
    {
        return DB::table('xendit_checkouts as x')
            ->join('payment_sales as p', 'p.id', '=', 'x.payment_sale_id')
            ->where('x.sale_id', $saleId)
            ->whereNull('p.deleted_at')
            ->exists();
    }

    // ------------------------------------------------------------- for the screen

    public static function present($checkout)
    {
        $out = [
            'id' => $checkout->id,
            'sale_id' => $checkout->sale_id,
            'amount' => (float) $checkout->amount,
            'status' => $checkout->status,
            'channel' => $checkout->channel,
            'payment_link_url' => $checkout->payment_link_url,
            'expires_at' => $checkout->expires_at ? Carbon::parse($checkout->expires_at)->toIso8601String() : null,
            'paid_at' => $checkout->paid_at,
            'payment_sale_id' => $checkout->payment_sale_id,
        ];
        if ($checkout->status === 'pending' && $checkout->payment_link_url) {
            $out['qr_svg'] = self::qr($checkout->payment_link_url);
        }

        return $out;
    }

    private static function qr($text)
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(280, 1), new SvgImageBackEnd()));
        $svg = $writer->writeString($text);

        // Only the <svg> element; no XML declaration inside the page.
        return substr($svg, strpos($svg, '<svg'));
    }
}
