<?php

/*
 * Selling on Shopee and Lazada, and keeping one stockroom behind both.
 *
 * Endpoint paths and hosts are settings rather than constants for the same
 * reason they are in the wallet config: both platforms move paths between
 * versions and shard their hosts by region, and neither should need a code
 * change. The signing schemes are in the adapters because those are genuinely
 * different algorithms, not parameters.
 *
 * Neither adapter has been exercised against a live shop. Both require an
 * approved partner app and a per-seller authorisation, which is not something
 * that can be arranged from here.
 */

return [

    'timeout_seconds' => (int) env('MARKETPLACE_HTTP_TIMEOUT', 30),

    // How far back a first sync reaches when an account has never run.
    'initial_lookback_days' => (int) env('MARKETPLACE_LOOKBACK_DAYS', 7),

    // ---- Connecting a shop -------------------------------------------------
    // Where the marketplace sends the seller back after they approve the app:
    // <base>/marketplace/callback/shopee|lazada. It must be the address
    // registered in the partner console. Empty means the address the person
    // is using when they press Connect.
    'redirect_base' => env('MARKETPLACE_REDIRECT_BASE'),

    // How long a Connect link stays good. It is single use either way.
    'state_ttl_minutes' => (int) env('MARKETPLACE_STATE_TTL', 15),

    // Renew a token this long before it runs out, not after a call fails.
    'refresh_margin_seconds' => (int) env('MARKETPLACE_REFRESH_MARGIN', 600),

    'shopee' => [
        // From the Shopee Open Platform console, once an app is approved.
        'partner_id' => env('SHOPEE_PARTNER_ID'),
        'partner_key' => env('SHOPEE_PARTNER_KEY'),

        // Shopee is sharded by region; the sandbox is a different host again.
        'host' => env('SHOPEE_HOST', 'https://partner.shopeemobile.com'),

        'order_list_path' => env('SHOPEE_ORDER_LIST_PATH', '/api/v2/order/get_order_list'),
        'order_detail_path' => env('SHOPEE_ORDER_DETAIL_PATH', '/api/v2/order/get_order_detail'),
        'update_stock_path' => env('SHOPEE_UPDATE_STOCK_PATH', '/api/v2/product/update_stock'),

        // Authorising a shop: the seller's approval page, then code -> tokens,
        // then renewing them (an access token lasts about four hours).
        'auth_path' => env('SHOPEE_AUTH_PATH', '/api/v2/shop/auth_partner'),
        'token_path' => env('SHOPEE_TOKEN_PATH', '/api/v2/auth/token/get'),
        'refresh_path' => env('SHOPEE_REFRESH_PATH', '/api/v2/auth/access_token/get'),

        'page_size' => (int) env('SHOPEE_PAGE_SIZE', 50),
        'detail_batch' => (int) env('SHOPEE_DETAIL_BATCH', 50),

        // Shopee refuses an unbounded order query; fifteen days is its limit.
        'max_window_seconds' => (int) env('SHOPEE_MAX_WINDOW', 1209600),
    ],

    'lazada' => [
        'app_key' => env('LAZADA_APP_KEY'),
        'app_secret' => env('LAZADA_APP_SECRET'),

        'host' => env('LAZADA_HOST', 'https://api.lazada.com.ph/rest'),

        'order_list_path' => env('LAZADA_ORDER_LIST_PATH', '/orders/get'),
        'order_items_path' => env('LAZADA_ORDER_ITEMS_PATH', '/order/items/get'),
        'update_stock_path' => env('LAZADA_UPDATE_STOCK_PATH', '/product/price_quantity/update'),

        // Authorising a seller happens on Lazada's own auth host, not the
        // regional API host.
        'auth_url' => env('LAZADA_AUTH_URL', 'https://auth.lazada.com/oauth/authorize'),
        'auth_host' => env('LAZADA_AUTH_HOST', 'https://auth.lazada.com/rest'),
        'token_path' => env('LAZADA_TOKEN_PATH', '/auth/token/create'),
        'refresh_path' => env('LAZADA_REFRESH_PATH', '/auth/token/refresh'),
        // The seller account to bind when one login covers several countries.
        'country' => env('LAZADA_COUNTRY', 'ph'),

        'page_size' => (int) env('LAZADA_PAGE_SIZE', 50),
    ],
];
