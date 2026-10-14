<?php

namespace App\utils\Marketplace;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * Shopee Open Platform, v2.
 *
 * NOT VERIFIED AGAINST A LIVE SHOP. Written to Shopee's documented v2 scheme:
 * every request carries partner_id, timestamp, access_token, shop_id and a
 * sign, where the sign is an HMAC-SHA256 of the concatenated
 * partner_id + path + timestamp + access_token + shop_id, keyed with the partner
 * key. Endpoint paths are configuration rather than constants, because Shopee
 * moves them between versions and a path change should not need a code change.
 *
 * Everything that could differ per region -- the host in particular, Shopee
 * being region-sharded -- comes from the account or config.
 *
 * The one thing this deliberately will not do is pretend. An account that is not
 * configured returns ok=false with a reason, rather than an empty order list
 * that the sync service would read as "nothing new today".
 */
class ShopeeAdapter implements MarketplaceAdapter
{
    private $http;

    public function __construct(Client $http = null)
    {
        $this->http = $http ?: new Client([
            'timeout' => (int) config('marketplace.timeout_seconds', 30),
            'http_errors' => false,
        ]);
    }

    public function fetchOrders(array $account, $since)
    {
        $missing = $this->unconfigured($account);
        if ($missing) {
            return ['ok' => false, 'orders' => [], 'error' => $missing];
        }

        // Shopee windows this query and will not accept an unbounded one.
        $to = time();
        $from = $since ? strtotime((string) $since) : ($to - 86400 * 7);
        $maxWindow = (int) config('marketplace.shopee.max_window_seconds', 1209600);
        if ($to - $from > $maxWindow) {
            $from = $to - $maxWindow;
        }

        $list = $this->call($account, config('marketplace.shopee.order_list_path'), [
            'time_range_field' => 'create_time',
            'time_from' => $from,
            'time_to' => $to,
            'page_size' => (int) config('marketplace.shopee.page_size', 50),
            'response_optional_fields' => 'order_status',
        ]);

        if (! $list['ok']) {
            return ['ok' => false, 'orders' => [], 'error' => $list['error']];
        }

        $ids = [];
        foreach (($list['body']['response']['order_list'] ?? []) as $row) {
            if (! empty($row['order_sn'])) {
                $ids[] = $row['order_sn'];
            }
        }

        if (! $ids) {
            return ['ok' => true, 'orders' => [], 'error' => null];
        }

        // Detail comes in batches; the list call carries no line items.
        $orders = [];
        foreach (array_chunk($ids, (int) config('marketplace.shopee.detail_batch', 50)) as $chunk) {
            $detail = $this->call($account, config('marketplace.shopee.order_detail_path'), [
                'order_sn_list' => implode(',', $chunk),
                'response_optional_fields' => 'item_list,total_amount,buyer_username,create_time,currency',
            ]);

            if (! $detail['ok']) {
                // Partial success is still a failure: importing half a page and
                // moving the watermark would skip the rest for good.
                return ['ok' => false, 'orders' => [], 'error' => $detail['error']];
            }

            foreach (($detail['body']['response']['order_list'] ?? []) as $row) {
                $orders[] = $this->normalise($row);
            }
        }

        return ['ok' => true, 'orders' => $orders, 'error' => null];
    }

    public function pushStock(array $account, array $listing, $quantity)
    {
        $missing = $this->unconfigured($account);
        if ($missing) {
            return ['ok' => false, 'error' => $missing];
        }

        if (empty($listing['external_item_id'])) {
            return ['ok' => false, 'error' => 'this listing has no Shopee item id'];
        }

        $result = $this->call($account, config('marketplace.shopee.update_stock_path'), [], [
            'item_id' => (int) $listing['external_item_id'],
            'stock_list' => [[
                'model_id' => 0,
                'seller_stock' => [['stock' => (int) floor($quantity)]],
            ]],
        ]);

        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error']];
        }

        return ['ok' => true, 'error' => null];
    }

    // ---------------------------------------------------------------- internals

    private function unconfigured(array $account)
    {
        if (! config('marketplace.shopee.partner_id') || ! config('marketplace.shopee.partner_key')) {
            return 'Shopee is not set up: the partner id and key are missing.';
        }
        if (empty($account['access_token']) || empty($account['external_shop_id'])) {
            return 'This Shopee shop has not been authorised yet.';
        }

        return null;
    }

    /**
     * Shopee's signature: HMAC-SHA256 over the pieces concatenated in a fixed
     * order, keyed with the partner key. Anything out of order fails as an
     * authentication error rather than as a signature error, which is why the
     * base string is built in one place.
     */
    private function call(array $account, $path, array $query = [], array $body = null)
    {
        $partnerId = (string) config('marketplace.shopee.partner_id');
        $partnerKey = (string) config('marketplace.shopee.partner_key');
        $token = (string) $account['access_token'];
        $shopId = (string) $account['external_shop_id'];
        $timestamp = time();

        $base = $partnerId . $path . $timestamp . $token . $shopId;
        $sign = hash_hmac('sha256', $base, $partnerKey);

        $query = array_merge($query, [
            'partner_id' => $partnerId,
            'timestamp' => $timestamp,
            'access_token' => $token,
            'shop_id' => $shopId,
            'sign' => $sign,
        ]);

        $host = rtrim((string) ($account['api_host'] ?? config('marketplace.shopee.host')), '/');
        $url = $host . $path;

        try {
            $options = ['query' => $query, 'headers' => ['Accept' => 'application/json']];
            if ($body !== null) {
                $options['json'] = $body;
            }
            $response = $this->http->request($body === null ? 'GET' : 'POST', $url, $options);
        } catch (\Throwable $e) {
            Log::warning('Shopee unreachable: ' . $e->getMessage());

            return ['ok' => false, 'body' => [], 'error' => 'Shopee could not be reached'];
        }

        $decoded = json_decode((string) $response->getBody(), true);
        $decoded = is_array($decoded) ? $decoded : [];

        // Shopee answers 200 with an error field rather than an HTTP error, so
        // the status code alone is not the answer.
        if (! empty($decoded['error'])) {
            $message = $decoded['message'] ?? $decoded['error'];
            Log::warning('Shopee refused a request: ' . $message);

            return ['ok' => false, 'body' => $decoded, 'error' => $message];
        }

        if ($response->getStatusCode() >= 300) {
            return ['ok' => false, 'body' => $decoded,
                    'error' => 'Shopee answered ' . $response->getStatusCode()];
        }

        return ['ok' => true, 'body' => $decoded, 'error' => null];
    }

    /** Shopee's shape, in ours. */
    private function normalise(array $row)
    {
        $lines = [];
        foreach (($row['item_list'] ?? []) as $item) {
            $lines[] = [
                'sku' => (string) ($item['model_sku'] ?: ($item['item_sku'] ?? '')),
                'quantity' => (float) ($item['model_quantity_purchased'] ?? 1),
                'unit_price' => (float) ($item['model_discounted_price']
                    ?? ($item['model_original_price'] ?? 0)),
            ];
        }

        $status = strtoupper((string) ($row['order_status'] ?? ''));
        $cancelled = in_array($status, ['CANCELLED', 'UNPAID_CANCELLED', 'INVOICE_PENDING_CANCELLED'], true);

        return [
            'external_order_id' => (string) $row['order_sn'],
            'status' => $cancelled ? 'cancelled' : 'open',
            'external_status' => $status,
            'ordered_at' => date('Y-m-d H:i:s', (int) ($row['create_time'] ?? time())),
            'total' => (float) ($row['total_amount'] ?? 0),
            'currency' => (string) ($row['currency'] ?? 'PHP'),
            'buyer_name' => $row['buyer_username'] ?? null,
            'lines' => $lines,
            'raw' => $row,
        ];
    }
}
