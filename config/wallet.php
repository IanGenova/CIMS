<?php

/*
 * Wallet payments — GCash, Maya and QR Ph, through one provider.
 *
 * Everything here that could differ between Xendit's API generations is a
 * setting rather than a constant. Their public documentation states the endpoint
 * shape and the channel code for GCash but does not spell out the authentication
 * scheme or the callback header, so those carry Xendit's long-standing documented
 * defaults and can be corrected in .env without touching code if they turn out to
 * differ on the account in use.
 */

return [

    // Which driver talks to the outside world. 'fake' is deterministic and makes
    // no network calls; it is what the tests run against.
    'driver' => env('WALLET_DRIVER', 'xendit'),

    'currency' => env('WALLET_CURRENCY', 'PHP'),
    'country' => env('WALLET_COUNTRY', 'PH'),

    /*
     * Credentials. Never committed, never stored in the database, never entered
     * by anyone but the shop that owns them.
     */
    'secret_key' => env('XENDIT_SECRET_KEY'),

    /*
     * The token Xendit puts on every callback it sends. A callback that does not
     * carry it is not from Xendit, and a callback that does carry it is still
     * only a prompt to go and ask the provider what really happened.
     */
    'callback_token' => env('XENDIT_CALLBACK_TOKEN', 'set-me-in-env'),
    'callback_header' => env('XENDIT_CALLBACK_HEADER', 'x-callback-token'),

    'base_url' => env('XENDIT_BASE_URL', 'https://api.xendit.co'),

    // The current Payments API. The older /ewallets/charges endpoint takes
    // different field names and channel codes; if this account is on that
    // generation, both settings below change together.
    'charge_path' => env('XENDIT_CHARGE_PATH', '/v3/payment_requests'),
    'api_version' => env('XENDIT_API_VERSION', '2024-11-11'),

    /*
     * What each wallet is called to the provider, and what it is called to the
     * cashier. The codes differ between API generations, which is exactly why
     * they are not written into the driver.
     */
    'channels' => [
        'GCASH' => [
            'code' => env('XENDIT_CHANNEL_GCASH', 'GCASH'),
            'label' => 'GCash',
        ],
        'PAYMAYA' => [
            'code' => env('XENDIT_CHANNEL_MAYA', 'PAYMAYA'),
            'label' => 'Maya',
        ],
    ],

    /*
     * Where the customer is sent after paying on their phone. Only used by the
     * redirect flow; a till showing a QR code never leaves the page.
     */
    'success_url' => env('WALLET_SUCCESS_URL', env('APP_URL') . '/wallet/done'),
    'failure_url' => env('WALLET_FAILURE_URL', env('APP_URL') . '/wallet/failed'),

    /*
     * How long the till keeps offering a charge before it gives up on it. A
     * customer who has walked away should not leave a payment pending forever,
     * and a cashier should not be able to attach a charge from an hour ago to
     * the sale in front of them.
     */
    'expires_after_minutes' => (int) env('WALLET_EXPIRY_MINUTES', 15),

    // How often the till asks the provider whether the money arrived.
    'poll_seconds' => (int) env('WALLET_POLL_SECONDS', 3),

    'timeout_seconds' => (int) env('WALLET_HTTP_TIMEOUT', 20),
];
