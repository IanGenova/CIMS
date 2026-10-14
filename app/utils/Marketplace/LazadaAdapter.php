<?php

namespace App\utils\Marketplace;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * Lazada Open Platform.
 *
 * NOT VERIFIED AGAINST A LIVE SHOP. Written to Lazada's documented signing
 * scheme, which differs from Shopee's in a way that matters: the parameters are
 * sorted by name, concatenated as name immediately followed by value with no
 * separators, prefixed with the API path, and then HMAC-SHA256'd with the app
 * secret and upper-cased. Getting the sort or the prefix wrong produces an
 * authentication failure that says nothing about which, so the base string is
 * built in exactly one place.
 *
 * Like the Shopee adapter, an account that is not configured returns a failure
 * with a reason rather than an empty list -- "no orders" and "could not ask" must
 * never look the same to the sync service.
 */
class LazadaAdapter implements MarketplaceAdapter
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

        $from = $since
            ? date(DATE_ATOM, strtotime((string) $since))
            : date(DATE_ATOM, time() - 86400 * 7);

        $list = $this->call($account, config('marketplace.lazada.order_list_path'), [
            'created_after' => $from,
            'sort_direction' => 'ASC',
            'limit' => (int) config('marketplace.lazada.page_size', 50),
        ]);

        if (! $list['ok']) {
            return ['ok' => false, 'orders' => [], 'error' => $list['error']];
        }

        $orders = [];
        foreach (($list['body']['data']['orders'] ?? []) as $row) {
            $orderId = (string) ($row['order_id'] ?? '');
            if ($orderId === '') {
                continue;
            }

            // Lazada keeps the line items behind a second call.
            $items = $this->call($account, config('marketplace.lazada.order_items_path'), [
                'order_id' => $orderId,
            ]);

            if (! $items['ok']) {
                return ['ok' => false, 'orders' => [], 'error' => $items['error']];
            }

            $orders[] = $this->normalise($row, $items['body']['data'] ?? []);
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
            return ['ok' => false, 'error' => 'this listing has no Lazada item id'];
        }

        // Lazada takes this endpoint as XML in a payload parameter rather than
        // as JSON, which is why it is assembled rather than encoded.
        $payload = '<Request><Product><Skus><Sku>'
            . '<ItemId>' . (int) $listing['external_item_id'] . '</ItemId>'
            . '<SellerSku>' . htmlspecialchars((string) $listing['external_sku'], ENT_XML1) . '</SellerSku>'
            . '<Quantity>' . (int) floor($quantity) . '</Quantity>'
            . '</Sku></Skus></Product></Request>';

        $result = $this->call($account, config('marketplace.lazada.update_stock_path'), [
            'payload' => $payload,
        ], 'POST');

        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error']];
        }

        return ['ok' => true, 'error' => null];
    }

    // ---------------------------------------------------------------- internals

    private function unconfigured(array $account)
    {
        if (! config('marketplace.lazada.app_key') || ! config('marketplace.lazada.app_secret')) {
            return 'Lazada is not set up: the app key and secret are missing.';
        }
        if (empty($account['access_token'])) {
            return 'This Lazada shop has not been authorised yet.';
        }

        return null;
    }

    /**
     * Lazada's signature: sort the parameters by name, concatenate name and
     * value with nothing between them, put the API path in front, HMAC-SHA256
     * with the app secret, upper-case the result.
     */
    private function sign($path, array $params)
    {
        ksort($params);

        $base = $path;
        foreach ($params as $name => $value) {
            $base .= $name . $value;
        }

        return strtoupper(hash_hmac('sha256', $base, (string) config('marketplace.lazada.app_secret')));
    }

    private function call(array $account, $path, array $params = [], $method = 'GET')
    {
        $params = array_merge($params, [
            'app_key' => (string) config('marketplace.lazada.app_key'),
            'access_token' => (string) $account['access_token'],
            'timestamp' => (string) (int) (microtime(true) * 1000),
            'sign_method' => 'sha256',
        ]);

        $params['sign'] = $this->sign($path, $params);

        $host = rtrim((string) ($account['api_host'] ?? config('marketplace.lazada.host')), '/');
        $url = $host . $path;

        try {
            $options = ['headers' => ['Accept' => 'application/json']];
            if ($method === 'GET') {
                $options['query'] = $params;
            } else {
                $options['form_params'] = $params;
            }
            $response = $this->http->request($method, $url, $options);
        } catch (\Throwable $e) {
            Log::warning('Lazada unreachable: ' . $e->getMessage());

            return ['ok' => false, 'body' => [], 'error' => 'Lazada could not be reached'];
        }

        $decoded = json_decode((string) $response->getBody(), true);
        $decoded = is_array($decoded) ? $decoded : [];

        // Lazada reports failure in the body, with code '0' meaning success.
        $code = (string) ($decoded['code'] ?? '0');
        if ($code !== '0' && $code !== '') {
            $message = $decoded['message'] ?? ('Lazada answered code ' . $code);
            Log::warning('Lazada refused a request: ' . $message);

            return ['ok' => false, 'body' => $decoded, 'error' => $message];
        }

        if ($response->getStatusCode() >= 300) {
            return ['ok' => false, 'body' => $decoded,
                    'error' => 'Lazada answered ' . $response->getStatusCode()];
        }

        return ['ok' => true, 'body' => $decoded, 'error' => null];
    }

    /** Lazada's shape, in ours. */
    private function normalise(array $row, array $items)
    {
        $lines = [];
        $seen = [];

        // Lazada returns one entry per unit rather than a quantity, so identical
        // SKUs are counted up instead of becoming several lines of one.
        foreach ($items as $item) {
            $sku = (string) ($item['sku'] ?? '');
            if ($sku === '') {
                continue;
            }

            if (! isset($seen[$sku])) {
                $seen[$sku] = ['sku' => $sku, 'quantity' => 0.0,
                               'unit_price' => (float) ($item['paid_price'] ?? ($item['item_price'] ?? 0))];
            }
            $seen[$sku]['quantity'] += 1.0;
        }
        $lines = array_values($seen);

        $status = strtolower((string) ($row['statuses'][0] ?? ($row['status'] ?? '')));
        $cancelled = strpos($status, 'cancel') !== false;

        return [
            'external_order_id' => (string) $row['order_id'],
            'status' => $cancelled ? 'cancelled' : 'open',
            'external_status' => $status,
            'ordered_at' => isset($row['created_at'])
                ? date('Y-m-d H:i:s', strtotime((string) $row['created_at']))
                : date('Y-m-d H:i:s'),
            'total' => (float) ($row['price'] ?? 0),
            'currency' => 'PHP',
            'buyer_name' => $row['customer_first_name'] ?? null,
            'lines' => $lines,
            'raw' => ['order' => $row, 'items' => $items],
        ];
    }
}
