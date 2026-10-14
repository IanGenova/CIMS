<?php

namespace App\utils;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Notices that reach a phone, or a computer whose browser is closed.
 *
 * The bell only knows about a notice while the app is open in a tab. Web Push
 * hands the notice to the device's own push service (Google, Mozilla, Apple,
 * Microsoft), which wakes the service worker (public/pos-sw.js) to show it.
 *
 *   encrypt()      RFC 8291 (aes128gcm): only the device can read the notice;
 *                  the push service in the middle sees ciphertext
 *   vapidHeader()  RFC 8292: a signed token proving the notice is from this
 *                  server, so nobody else can push to these devices
 *
 * Built on PHP's OpenSSL; no library. tests/Feature/WebPushTest.php checks
 * the encryption against the RFC's published example.
 *
 * The server's key pair is made on first use and kept in storage/app, which
 * is not committed. Replacing it disconnects every device until it next opens
 * the app (it re-subscribes then).
 */
class WebPush
{
    const KEY_FILE = 'app/webpush-vapid.key';

    /** Push services a device may name; the server posts to these and nowhere else. */
    const PUSH_HOSTS = ['googleapis.com', 'mozilla.com', 'push.apple.com', 'notify.windows.com'];

    private static $http;
    private static $queued = [];

    /** Tests swap in a fake push service. */
    public static function useHttp($client)
    {
        self::$http = $client;
        self::$queued = [];
    }

    // ------------------------------------------------------------- keys

    private static function privateKey()
    {
        $file = storage_path(self::KEY_FILE);

        if (! is_file($file)) {
            @mkdir(dirname($file), 0755, true);
            file_put_contents($file, base64_encode(self::newScalar()), LOCK_EX);
        }

        return self::keyFromScalar(base64_decode(trim(file_get_contents($file))));
    }

    /**
     * A fresh private key, as 32 random bytes. (openssl_pkey_new() needs an
     * openssl.cnf that a Windows PHP install often cannot find.) Any value
     * from 1 to just under the curve order is a key; the order begins
     * FFFFFFFF, so refusing that prefix and zero keeps it in range.
     */
    private static function newScalar()
    {
        do {
            $d = random_bytes(32);
        } while (substr($d, 0, 4) === "\xff\xff\xff\xff" || trim($d, "\0") === "");

        return $d;
    }

    /** Uncompressed P-256 point: 0x04 || x || y. */
    private static function point($key)
    {
        $ec = openssl_pkey_get_details($key)['ec'];

        return "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
    }

    /** What a browser needs to subscribe (applicationServerKey). */
    public static function publicKey()
    {
        return self::b64u(self::point(self::privateKey()));
    }

    /** A raw 32-byte P-256 private scalar as a key OpenSSL can use; it derives the public half. */
    private static function keyFromScalar($d)
    {
        $der = hex2bin('30310201010420') . $d . hex2bin('a00a06082a8648ce3d030107');

        return openssl_pkey_get_private("-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n");
    }

    private static function publicFromPoint($point)
    {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;

        return openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n");
    }

    // ------------------------------------------------------------- RFC 8291

    /**
     * @param  string       $payload     the notice, as sent to the service worker
     * @param  string       $uaPublic    device key, 65 raw bytes (p256dh)
     * @param  string       $authSecret  device secret, 16 raw bytes (auth)
     * @param  string|null  $asPrivate   one-off key, 32 raw bytes; tests only
     * @param  string|null  $salt        16 raw bytes; tests only
     * @return string                    the request body
     */
    public static function encrypt($payload, $uaPublic, $authSecret, $asPrivate = null, $salt = null)
    {
        // A fresh key for every message, as the RFC requires.
        $local = $asPrivate === null
            ? self::keyFromScalar(self::newScalar())
            : self::keyFromScalar($asPrivate);
        $asPublic = self::point($local);
        $salt = $salt ?? random_bytes(16);

        $shared = openssl_pkey_derive(self::publicFromPoint($uaPublic), $local, 32);
        if ($shared === false) {
            throw new \RuntimeException('The device key is not a valid P-256 key.');
        }

        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        // One record; 0x02 marks it as the last.
        $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);

        return $salt . pack('N', 4096) . chr(strlen($asPublic)) . $asPublic . $cipher . $tag;
    }

    // ------------------------------------------------------------- RFC 8292

    public static function vapidHeader($endpoint)
    {
        $parts = parse_url($endpoint);
        $from = config('mail.from.address');

        $jwt = self::b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.' . self::b64u(json_encode([
            'aud' => $parts['scheme'] . '://' . $parts['host'],
            'exp' => time() + 12 * 3600,
            'sub' => $from ? 'mailto:' . $from : 'mailto:admin@localhost',
        ]));

        $key = self::privateKey();
        openssl_sign($jwt, $der, $key, OPENSSL_ALGO_SHA256);

        return 'vapid t=' . $jwt . '.' . self::b64u(self::derToRaw($der)) . ', k=' . self::b64u(self::point($key));
    }

    /** OpenSSL signs in DER; JWT wants the bare r || s. */
    private static function derToRaw($der)
    {
        $offset = 2;
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$offset + 1]);
            $int = ltrim(substr($der, $offset + 2, $len), "\0");
            $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $len;
        }

        return $out;
    }

    // ------------------------------------------------------------- devices

    /** Only a real push service, over HTTPS: the server will post to it. */
    public static function acceptableEndpoint($endpoint)
    {
        $parts = parse_url((string) $endpoint);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }
        $host = strtolower($parts['host']);
        foreach (self::PUSH_HOSTS as $allowed) {
            if ($host === $allowed || substr($host, -strlen('.' . $allowed)) === '.' . $allowed) {
                return true;
            }
        }

        return false;
    }

    public static function subscribe($userId, $endpoint, $p256dh, $auth)
    {
        // One row per device; a device signed into by someone else moves to them.
        DB::table('push_subscriptions')->updateOrInsert(
            ['endpoint' => $endpoint],
            ['user_id' => $userId, 'p256dh' => $p256dh, 'auth' => $auth, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    public static function unsubscribe($userId, $endpoint)
    {
        DB::table('push_subscriptions')->where('endpoint', $endpoint)->where('user_id', $userId)->delete();
    }

    // ------------------------------------------------------------- sending

    /**
     * Send after the response, so a slow push service never holds up the
     * person who saved the purchase.
     */
    public static function queue($notificationId)
    {
        if (! Schema::hasTable('push_subscriptions') || ! DB::table('push_subscriptions')->exists()) {
            return;
        }
        if (empty(self::$queued)) {
            app()->terminating(function () {
                $ids = self::$queued;
                self::$queued = [];
                foreach ($ids as $id) {
                    self::sendForNotification($id);
                }
            });
        }
        self::$queued[] = $notificationId;
    }

    /** Who a notice is for: the same people the bell shows it to. */
    private static function recipients($n)
    {
        $users = DB::table('users')->where('statut', 1)->whereNull('deleted_at');

        if ($n->user_id) {
            $users->where('id', $n->user_id);
        } elseif ($n->permission) {
            $users->whereIn('id', DB::table('role_user')
                ->join('permission_role', 'permission_role.role_id', '=', 'role_user.role_id')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->where('permissions.name', $n->permission)
                ->select('role_user.user_id'));
        }
        if ($n->actor_id) {
            $users->where('id', '!=', $n->actor_id);
        }

        return $users->pluck('id')->all();
    }

    public static function sendForNotification($notificationId)
    {
        $n = DB::table('app_notifications')->where('id', $notificationId)->first();
        if (! $n || ! empty($n->pushed_at)) {
            return 0;
        }

        $devices = DB::table('push_subscriptions')->whereIn('user_id', self::recipients($n) ?: [0])->get();

        $payload = json_encode([
            'id' => $n->id,
            'title' => $n->title,
            'body' => $n->body,
            'link' => $n->link ?: '/app/dashboard',
            'level' => $n->level,
        ]);

        $http = self::$http ?: new Client(self::clientOptions());
        $sent = 0;

        foreach ($devices as $device) {
            try {
                $body = self::encrypt($payload, self::unb64u($device->p256dh), self::unb64u($device->auth));
                $response = $http->post($device->endpoint, [
                    'headers' => [
                        'Authorization' => self::vapidHeader($device->endpoint),
                        'Content-Encoding' => 'aes128gcm',
                        'Content-Type' => 'application/octet-stream',
                        'TTL' => 86400,
                        'Urgency' => in_array($n->level, ['warning', 'danger']) ? 'high' : 'normal',
                    ],
                    'body' => $body,
                ]);
                $status = $response->getStatusCode();

                if ($status === 404 || $status === 410) {
                    // The person turned notifications off, or the browser was reset.
                    DB::table('push_subscriptions')->where('id', $device->id)->delete();
                } elseif ($status >= 200 && $status < 300) {
                    $sent++;
                } else {
                    Log::warning('Web push refused', ['status' => $status, 'host' => parse_url($device->endpoint, PHP_URL_HOST)]);
                }
            } catch (\Throwable $e) {
                Log::warning('Web push failed: ' . $e->getMessage());
            }
        }

        DB::table('app_notifications')->where('id', $n->id)->update(['pushed_at' => now()]);

        return $sent;
    }

    /**
     * A Windows PHP (WAMP) ships with no list of trusted certificates, so every
     * HTTPS call fails verification (cURL error 60). Rather than switch
     * verification off, use Windows' own certificate store. A server that has
     * a CA file configured, or runs Linux, keeps its own.
     */
    public static function clientOptions()
    {
        $options = ['timeout' => 5, 'connect_timeout' => 3, 'http_errors' => false];

        if (PHP_OS_FAMILY === 'Windows' && ! ini_get('curl.cainfo') && ! ini_get('openssl.cafile')) {
            $options['curl'] = [CURLOPT_SSL_OPTIONS => 16]; // CURLSSLOPT_NATIVE_CA
        }

        return $options;
    }

    // ------------------------------------------------------------- base64url

    private static function b64u($s)
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function unb64u($s)
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }
}
