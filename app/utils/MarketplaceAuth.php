<?php

namespace App\utils;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Connecting a Shopee or Lazada shop, and keeping it connected.
 *
 * The seller presses Connect, approves this app on the marketplace's own page,
 * and is sent back here with a one-time code. The code becomes an access token
 * and a renewal token. Shopee's access token lasts about four hours, so it is
 * renewed before each sync once it is close to running out.
 *
 * NOT VERIFIED AGAINST A LIVE SHOP, like the adapters: written to Shopee Open
 * Platform v2 and Lazada Open Platform as documented. Paths and hosts are
 * configuration (config/marketplace.php).
 *
 * WHAT STOPS SOMEONE ATTACHING THEIR SHOP TO OURS
 *
 * Every Connect press makes a random state. Only its hash is stored, bound to
 * the shop record and to the person who pressed it, good for one use and a
 * few minutes. The way back must present that state, from the same signed-in
 * person, before any code is exchanged -- and the state is spent before the
 * exchange, so a replayed return does nothing.
 *
 * WHERE THE TOKENS LIVE
 *
 * Encrypted with the application key, in the accounts table. They are
 * decrypted only to hand to an adapter (credentials()), never returned to the
 * screen and never written to the log.
 */
class MarketplaceAuth
{
    /** Overridden in tests. */
    private static $http = null;

    public static function useHttp(Client $client = null)
    {
        self::$http = $client;
    }

    private static function http()
    {
        return self::$http ?: new Client([
            'timeout' => (int) config('marketplace.timeout_seconds', 30),
            'http_errors' => false,
        ]);
    }

    // ------------------------------------------------------------- the link

    /**
     * The marketplace page where the seller approves this app.
     *
     * Returns ['url' => string] or ['error' => string].
     */
    public static function connectUrl($account, $userId, $baseUrl)
    {
        $missing = self::unconfigured($account->channel);
        if ($missing) {
            return ['error' => $missing];
        }

        $state = Str::random(48);
        DB::table('marketplace_auth_states')->insert([
            'state_hash' => hash('sha256', $state),
            'marketplace_account_id' => $account->id,
            'user_id' => $userId,
            'expires_at' => now()->addMinutes((int) config('marketplace.state_ttl_minutes', 15)),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $base = rtrim((string) (config('marketplace.redirect_base') ?: $baseUrl), '/');
        $callback = $base . '/marketplace/callback/' . $account->channel;

        if ($account->channel === 'shopee') {
            // Shopee hands the redirect back exactly as given, with code and
            // shop_id added, so the state travels inside it.
            $path = config('marketplace.shopee.auth_path');
            $partnerId = (string) config('marketplace.shopee.partner_id');
            $timestamp = time();

            return ['url' => rtrim((string) config('marketplace.shopee.host'), '/') . $path . '?' . http_build_query([
                'partner_id' => $partnerId,
                'timestamp' => $timestamp,
                'sign' => hash_hmac('sha256', $partnerId . $path . $timestamp, (string) config('marketplace.shopee.partner_key')),
                'redirect' => $callback . '?state=' . $state,
            ])];
        }

        // Lazada matches redirect_uri against the registered address, so the
        // state goes in its own parameter (standard OAuth 2).
        return ['url' => config('marketplace.lazada.auth_url') . '?' . http_build_query([
            'response_type' => 'code',
            'force_auth' => 'true',
            'redirect_uri' => $callback,
            'client_id' => (string) config('marketplace.lazada.app_key'),
            'state' => $state,
        ])];
    }

    // --------------------------------------------------------- the way back

    /**
     * The seller is back from the marketplace.
     *
     * Returns ['account_id' => int] or ['error' => string, 'account_id' => int|null].
     */
    public static function complete($channel, array $query, $userId)
    {
        $state = (string) ($query['state'] ?? '');
        $row = $state === '' ? null
            : DB::table('marketplace_auth_states')->where('state_hash', hash('sha256', $state))->first();

        if (! $row || $row->used_at !== null || now()->greaterThan($row->expires_at)
            || (int) $row->user_id !== (int) $userId) {
            return ['error' => 'This connect link is not valid any more. Press Connect again.', 'account_id' => null];
        }

        // Spent before anything else happens: a replayed return does nothing.
        $spent = DB::table('marketplace_auth_states')->where('id', $row->id)->whereNull('used_at')
            ->update(['used_at' => now(), 'updated_at' => now()]);
        if (! $spent) {
            return ['error' => 'This connect link is not valid any more. Press Connect again.', 'account_id' => null];
        }

        $account = DB::table('marketplace_accounts')->where('id', $row->marketplace_account_id)
            ->whereNull('deleted_at')->first();
        if (! $account || $account->channel !== $channel) {
            return ['error' => 'That shop is not on this system any more.', 'account_id' => null];
        }

        if (! empty($query['error'])) {
            return self::fail($account, 'The marketplace did not authorise the shop: '
                . Str::limit((string) ($query['error_description'] ?? $query['error']), 150));
        }

        $code = (string) ($query['code'] ?? '');
        if ($code === '') {
            return self::fail($account, 'The marketplace sent no authorisation code back.');
        }

        // Shopee names the shop on the way back; Lazada names the seller in
        // the token answer. Refuse a shop that belongs to another record
        // before spending the code on it.
        $shopId = $channel === 'shopee' ? (string) ($query['shop_id'] ?? '') : null;
        if ($channel === 'shopee') {
            if ($shopId === '' || ! ctype_digit($shopId)) {
                return self::fail($account, 'Shopee did not say which shop was authorised.');
            }
            $clash = self::shopClash($account, $shopId);
            if ($clash) {
                return self::fail($account, $clash);
            }
        }

        $answer = $channel === 'shopee' ? self::shopeeToken($code, $shopId) : self::lazadaToken($code);
        if (isset($answer['error'])) {
            return self::fail($account, $answer['error']);
        }

        if ($channel === 'lazada') {
            $shopId = $answer['shop_id'];
            if ($shopId === null) {
                return self::fail($account, 'Lazada did not say which seller account was authorised.');
            }
            $clash = self::shopClash($account, $shopId);
            if ($clash) {
                return self::fail($account, $clash);
            }
        }

        self::store($account->id, $answer, ['external_shop_id' => $shopId, 'connected_at' => now()]);

        return ['account_id' => (int) $account->id];
    }

    /** Another record already holds this shop, or this record holds another. */
    private static function shopClash($account, $shopId)
    {
        if ($account->external_shop_id !== null && $account->external_shop_id !== ''
            && (string) $account->external_shop_id !== (string) $shopId) {
            return 'This record is for shop ' . $account->external_shop_id . ', but the login was for shop '
                . $shopId . '. Add the other shop as its own record.';
        }

        $other = DB::table('marketplace_accounts')
            ->where('channel', $account->channel)
            ->where('external_shop_id', (string) $shopId)
            ->where('id', '!=', $account->id)
            ->whereNull('deleted_at')
            ->first();

        return $other ? 'That shop is already connected as "' . $other->name . '".' : null;
    }

    private static function fail($account, $message)
    {
        DB::table('marketplace_accounts')->where('id', $account->id)
            ->update(['auth_error' => Str::limit($message, 250), 'updated_at' => now()]);

        return ['error' => $message, 'account_id' => (int) $account->id];
    }

    private static function store($accountId, array $answer, array $extra = [])
    {
        DB::table('marketplace_accounts')->where('id', $accountId)->update(array_merge([
            'access_token' => Crypt::encryptString($answer['access_token']),
            'refresh_token' => Crypt::encryptString($answer['refresh_token']),
            'token_expires_at' => now()->addSeconds($answer['expires_in']),
            'refresh_expires_at' => $answer['refresh_expires_in'] ? now()->addSeconds($answer['refresh_expires_in']) : null,
            'auth_error' => null,
            'updated_at' => now(),
        ], $extra));
    }

    // ----------------------------------------------------- staying connected

    /**
     * The account as an adapter needs it: a token that is good for a while,
     * decrypted. Renews first when the token is close to running out.
     *
     * Returns ['account' => array] or ['error' => string].
     */
    public static function credentials($account)
    {
        $account = (array) $account;

        if (! in_array($account['channel'] ?? '', ['shopee', 'lazada'], true) || empty($account['refresh_token'])) {
            // Not connected (the adapter will say so), or a channel with no tokens.
            $account['access_token'] = self::reveal($account['access_token'] ?? null);

            return ['account' => $account];
        }

        $margin = (int) config('marketplace.refresh_margin_seconds', 600);
        $expires = $account['token_expires_at'] ? strtotime($account['token_expires_at']) : 0;

        if ($expires - time() <= $margin) {
            $renewed = self::renew($account);
            if (isset($renewed['error'])) {
                return $renewed;
            }
            $account = (array) DB::table('marketplace_accounts')->where('id', $account['id'])->first();
        }

        $account['access_token'] = self::reveal($account['access_token']);
        $account['refresh_token'] = null; // an adapter has no use for it

        return ['account' => $account];
    }

    private static function renew(array $account)
    {
        $refresh = self::reveal($account['refresh_token']);

        $answer = $account['channel'] === 'shopee'
            ? self::shopeeRefresh($refresh, (string) $account['external_shop_id'])
            : self::lazadaRefresh($refresh);

        if (isset($answer['error'])) {
            $message = 'Reconnect needed: the marketplace would not renew access (' . $answer['error'] . ').';
            DB::table('marketplace_accounts')->where('id', $account['id'])
                ->update(['auth_error' => Str::limit($message, 250), 'updated_at' => now()]);

            // Somebody has to press Reconnect; tell them, not just the record.
            ActivityNotifier::marketplaceReconnect($account['id'], $message);

            return ['error' => $message];
        }

        self::store($account['id'], $answer);

        return ['ok' => true];
    }

    private static function reveal($value)
    {
        if ($value === null || $value === '') {
            return $value;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable $e) {
            return null; // unreadable (e.g. the app key changed): treat as not connected
        }
    }

    // --------------------------------------------------------------- Shopee

    private static function shopeeToken($code, $shopId)
    {
        return self::shopeeAuthCall(config('marketplace.shopee.token_path'), [
            'code' => $code,
            'shop_id' => (int) $shopId,
            'partner_id' => (int) config('marketplace.shopee.partner_id'),
        ]);
    }

    private static function shopeeRefresh($refreshToken, $shopId)
    {
        return self::shopeeAuthCall(config('marketplace.shopee.refresh_path'), [
            'refresh_token' => (string) $refreshToken,
            'shop_id' => (int) $shopId,
            'partner_id' => (int) config('marketplace.shopee.partner_id'),
        ]);
    }

    /** Public-level calls: the sign covers partner_id + path + timestamp only. */
    private static function shopeeAuthCall($path, array $body)
    {
        $partnerId = (string) config('marketplace.shopee.partner_id');
        $timestamp = time();
        $url = rtrim((string) config('marketplace.shopee.host'), '/') . $path;

        try {
            $response = self::http()->request('POST', $url, [
                'query' => [
                    'partner_id' => $partnerId,
                    'timestamp' => $timestamp,
                    'sign' => hash_hmac('sha256', $partnerId . $path . $timestamp, (string) config('marketplace.shopee.partner_key')),
                ],
                'json' => $body,
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Shopee auth unreachable: ' . $e->getMessage());

            return ['error' => 'Shopee could not be reached'];
        }

        $data = json_decode((string) $response->getBody(), true);
        $data = is_array($data) ? $data : [];

        if (! empty($data['error']) || empty($data['access_token']) || empty($data['refresh_token'])) {
            $message = Str::limit((string) ($data['message'] ?? $data['error'] ?? ('Shopee answered ' . $response->getStatusCode())), 150);
            Log::warning('Shopee refused an authorisation request: ' . $message);

            return ['error' => $message ?: 'Shopee did not issue a token'];
        }

        return [
            'access_token' => (string) $data['access_token'],
            'refresh_token' => (string) $data['refresh_token'],
            'expires_in' => (int) ($data['expire_in'] ?? 14400),
            // Shopee's renewal token lasts 30 days and each renewal issues a new one.
            'refresh_expires_in' => 30 * 86400,
        ];
    }

    // --------------------------------------------------------------- Lazada

    private static function lazadaToken($code)
    {
        $answer = self::lazadaAuthCall(config('marketplace.lazada.token_path'), ['code' => $code]);
        if (isset($answer['error'])) {
            return $answer;
        }

        // One Lazada login can hold sellers in several countries; bind the
        // one for this shop's country.
        $country = strtolower((string) config('marketplace.lazada.country', 'ph'));
        $shopId = null;
        foreach (($answer['data']['country_user_info'] ?? []) as $info) {
            if (strtolower((string) ($info['country'] ?? '')) === $country && ! empty($info['seller_id'])) {
                $shopId = (string) $info['seller_id'];
            }
        }
        $answer['shop_id'] = $shopId;

        return $answer;
    }

    private static function lazadaRefresh($refreshToken)
    {
        return self::lazadaAuthCall(config('marketplace.lazada.refresh_path'), ['refresh_token' => (string) $refreshToken]);
    }

    /** Lazada's sign: path + every parameter name and value, sorted by name, HMAC-SHA256, upper case. */
    private static function lazadaAuthCall($path, array $params)
    {
        $params = array_merge($params, [
            'app_key' => (string) config('marketplace.lazada.app_key'),
            'timestamp' => (string) (int) (microtime(true) * 1000),
            'sign_method' => 'sha256',
        ]);
        ksort($params);
        $base = $path;
        foreach ($params as $name => $value) {
            $base .= $name . $value;
        }
        $params['sign'] = strtoupper(hash_hmac('sha256', $base, (string) config('marketplace.lazada.app_secret')));

        try {
            $response = self::http()->request('POST', rtrim((string) config('marketplace.lazada.auth_host'), '/') . $path, [
                'form_params' => $params,
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Lazada auth unreachable: ' . $e->getMessage());

            return ['error' => 'Lazada could not be reached'];
        }

        $data = json_decode((string) $response->getBody(), true);
        $data = is_array($data) ? $data : [];

        if ((string) ($data['code'] ?? '') !== '0' || empty($data['access_token']) || empty($data['refresh_token'])) {
            $message = Str::limit((string) ($data['message'] ?? ('Lazada answered ' . $response->getStatusCode())), 150);
            Log::warning('Lazada refused an authorisation request: ' . $message);

            return ['error' => $message ?: 'Lazada did not issue a token'];
        }

        return [
            'access_token' => (string) $data['access_token'],
            'refresh_token' => (string) $data['refresh_token'],
            'expires_in' => (int) ($data['expires_in'] ?? 0),
            'refresh_expires_in' => (int) ($data['refresh_expires_in'] ?? 0),
            'data' => $data,
        ];
    }

    // ------------------------------------------------------------- settings

    public static function unconfigured($channel)
    {
        if ($channel === 'shopee' && (! config('marketplace.shopee.partner_id') || ! config('marketplace.shopee.partner_key'))) {
            return 'Shopee is not set up: the partner id and key are missing.';
        }
        if ($channel === 'lazada' && (! config('marketplace.lazada.app_key') || ! config('marketplace.lazada.app_secret'))) {
            return 'Lazada is not set up: the app key and secret are missing.';
        }
        if (! in_array($channel, ['shopee', 'lazada'], true)) {
            return 'Only Shopee and Lazada shops can be connected.';
        }

        return null;
    }
}
