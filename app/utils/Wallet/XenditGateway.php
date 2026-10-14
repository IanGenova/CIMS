<?php

namespace App\utils\Wallet;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * Xendit, speaking the Payments API.
 *
 * Xendit has two generations of e-wallet API in the wild: the older
 * /ewallets/charges, which takes channel codes like PH_GCASH, and the current
 * /v3/payment_requests, which takes GCASH. Which one an account is on is a
 * property of the account, not of this code, so the path and the channel codes
 * both come from config and neither is written in here.
 *
 * Two details are not spelled out in the public documentation and carry Xendit's
 * long-standing defaults: authentication is HTTP Basic with the secret key as
 * the username and an empty password, and callbacks are identified by an
 * x-callback-token header. Both are settings, so if the account differs it is a
 * line in .env rather than a change here.
 *
 * Every status the provider can report is mapped to one of ours. An unrecognised
 * status is treated as pending rather than as success -- the failure that costs
 * money is deciding something was paid when it was not.
 */
class XenditGateway implements WalletGateway
{
    private $http;

    public function __construct(Client $http = null)
    {
        $options = [
            'base_uri' => rtrim((string) config('wallet.base_url'), '/') . '/',
            'timeout' => (int) config('wallet.timeout_seconds', 20),
            'http_errors' => false,
        ];
        // A Windows PHP (WAMP) has no trusted-certificate list: use Windows'
        // own store rather than failing every call with cURL error 60.
        if (PHP_OS_FAMILY === 'Windows' && ! ini_get('curl.cainfo') && ! ini_get('openssl.cafile')) {
            $options['curl'] = [CURLOPT_SSL_OPTIONS => 16]; // CURLSSLOPT_NATIVE_CA
        }
        $this->http = $http ?: new Client($options);
    }

    public function createCharge(array $params)
    {
        $channels = (array) config('wallet.channels');
        $key = strtoupper($params['channel']);

        if (! isset($channels[$key])) {
            return $this->failure('that wallet is not configured');
        }

        $body = [
            'reference_id' => $params['reference_id'],
            'type' => 'PAY',
            'country' => config('wallet.country', 'PH'),
            'currency' => config('wallet.currency', 'PHP'),
            'request_amount' => round((float) $params['amount'], 2),
            'capture_method' => 'AUTOMATIC',
            'channel_code' => $channels[$key]['code'],
            'channel_properties' => [
                'success_return_url' => config('wallet.success_url'),
                'failure_return_url' => config('wallet.failure_url'),
            ],
        ];

        $response = $this->send('POST', ltrim((string) config('wallet.charge_path'), '/'), $body);

        if (! $response['ok']) {
            return $this->failure($response['error'], $response['raw']);
        }

        $raw = $response['raw'];

        return [
            'ok' => true,
            'charge_id' => $raw['payment_request_id'] ?? ($raw['id'] ?? null),
            'status' => $this->normalise($raw['status'] ?? null),
            'action' => $this->actionFrom($raw),
            'raw' => $raw,
            'error' => null,
        ];
    }

    public function fetchCharge($reference, $providerChargeId = null)
    {
        if (! $providerChargeId) {
            // Without the provider's own id there is nothing to ask about. Say
            // so rather than guessing a URL that would 404 and read as a failure.
            return $this->failure('no provider charge id to look up') + ['amount' => null];
        }

        $path = ltrim((string) config('wallet.charge_path'), '/') . '/' . rawurlencode($providerChargeId);
        $response = $this->send('GET', $path);

        if (! $response['ok']) {
            return $this->failure($response['error'], $response['raw']) + ['amount' => null];
        }

        $raw = $response['raw'];

        return [
            'ok' => true,
            'charge_id' => $raw['payment_request_id'] ?? ($raw['id'] ?? $providerChargeId),
            'status' => $this->normalise($raw['status'] ?? null),
            'amount' => isset($raw['request_amount']) ? (float) $raw['request_amount']
                : (isset($raw['captured_amount']) ? (float) $raw['captured_amount'] : null),
            'action' => $this->actionFrom($raw),
            'raw' => $raw,
            'error' => null,
        ];
    }

    /**
     * The keys entered on System Settings → Payment Gateway (stored encrypted,
     * shared with the hosted checkout, App\utils\XenditCheckout). The .env
     * values still work for an install set up before that screen existed.
     */
    public static function secretKey()
    {
        return \App\utils\XenditCheckout::secretKey() ?: config('wallet.secret_key');
    }

    public static function callbackToken()
    {
        return \App\utils\XenditCheckout::webhookToken() ?: config('wallet.callback_token');
    }

    public function verifyCallback($token)
    {
        $expected = (string) self::callbackToken();

        if ($expected === '' || $expected === 'set-me-in-env') {
            // Refusing every callback is the right answer when the shop has not
            // configured a token: an unverifiable callback must never be trusted.
            Log::warning('A wallet callback arrived but no callback token is configured.');

            return false;
        }

        return $token !== '' && hash_equals($expected, (string) $token);
    }

    // ---------------------------------------------------------------- internals

    private function send($method, $path, array $body = null)
    {
        $options = [
            // Basic auth, secret key as username, empty password.
            'auth' => [(string) self::secretKey(), ''],
            'headers' => array_filter([
                'Accept' => 'application/json',
                'api-version' => config('wallet.api_version'),
            ]),
        ];

        if ($body !== null) {
            $options['json'] = $body;
            // Xendit keys idempotency off this header, so a retried create does
            // not charge the customer twice.
            $options['headers']['Idempotency-key'] = $body['reference_id'] ?? null;
            $options['headers'] = array_filter($options['headers']);
        }

        try {
            $response = $this->http->request($method, $path, $options);
        } catch (\Throwable $e) {
            Log::warning('Wallet provider unreachable: ' . $e->getMessage());

            return ['ok' => false, 'raw' => [], 'error' => 'the payment provider could not be reached'];
        }

        $status = $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true);
        $decoded = is_array($decoded) ? $decoded : [];

        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'raw' => $decoded, 'error' => null];
        }

        $message = $decoded['message'] ?? ($decoded['error_code'] ?? ('the provider answered ' . $status));
        Log::warning('Wallet provider refused a request: ' . $message, ['status' => $status]);

        return ['ok' => false, 'raw' => $decoded, 'error' => $message];
    }

    /**
     * What the customer is given: a QR string to scan at the counter, or a link
     * to open on their phone.
     */
    private function actionFrom(array $raw)
    {
        $actions = $raw['actions'] ?? [];

        foreach ((array) $actions as $action) {
            $type = strtoupper((string) ($action['type'] ?? ''));
            $value = (string) ($action['value'] ?? '');

            if ($value === '') {
                continue;
            }

            // A QR is preferred: the customer scans it where they stand, and the
            // till never has to hand them a link.
            if ($type === 'PRESENT_TO_CUSTOMER') {
                return ['type' => 'qr', 'value' => $value];
            }
        }

        foreach ((array) $actions as $action) {
            $value = (string) ($action['value'] ?? '');
            if ($value !== '' && strtoupper((string) ($action['type'] ?? '')) === 'REDIRECT_CUSTOMER') {
                return ['type' => 'redirect', 'value' => $value];
            }
        }

        return ['type' => 'none', 'value' => ''];
    }

    /**
     * Their words for what happened, in ours.
     *
     * Anything unrecognised becomes pending, never paid. A provider that invents
     * a new status should leave a till waiting, not hand over goods.
     */
    private function normalise($status)
    {
        switch (strtoupper((string) $status)) {
            case 'SUCCEEDED':
            case 'CAPTURED':
            case 'PAID':
                return 'paid';

            case 'FAILED':
                return 'failed';

            case 'EXPIRED':
                return 'expired';

            case 'CANCELED':
            case 'CANCELLED':
            case 'VOIDED':
                return 'cancelled';

            case 'PENDING':
            case 'REQUIRES_ACTION':
            case 'AWAITING_CAPTURE':
                return 'pending';

            default:
                return 'pending';
        }
    }

    private function failure($message, array $raw = [])
    {
        return [
            'ok' => false,
            'charge_id' => null,
            'status' => 'failed',
            'action' => ['type' => 'none', 'value' => ''],
            'raw' => $raw,
            'error' => $message,
        ];
    }
}
