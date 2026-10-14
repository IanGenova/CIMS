<?php

namespace App\utils\Marketplace;

/**
 * A marketplace that does exactly what it is told.
 *
 * The cases worth testing here cannot be provoked reliably against a real
 * marketplace: the same order arriving on every poll, a SKU nobody mapped, a
 * cancellation after the goods were already taken off the shelf, and the API
 * simply being down. All four lose money or lose stock, and all four are one
 * method call away here.
 */
class FakeAdapter implements MarketplaceAdapter
{
    /** external_order_id => order */
    private $orders = [];

    /** sku => last quantity pushed */
    private $pushed = [];

    private $pushCount = 0;

    private $failFetch = false;

    public function fetchOrders(array $account, $since)
    {
        if ($this->failFetch) {
            $this->failFetch = false;

            return ['ok' => false, 'orders' => [], 'error' => 'the marketplace could not be reached'];
        }

        $orders = array_values($this->orders);
        usort($orders, function ($a, $b) {
            return strcmp($a['ordered_at'], $b['ordered_at']);
        });

        return ['ok' => true, 'orders' => $orders, 'error' => null];
    }

    public function pushStock(array $account, array $listing, $quantity)
    {
        $this->pushCount++;
        $this->pushed[$listing['external_sku']] = (float) $quantity;

        return ['ok' => true, 'error' => null];
    }

    // ---- what a test drives it with -------------------------------------

    public function addOrder(array $order)
    {
        $order += [
            'status' => 'open',
            'external_status' => $order['status'] ?? 'READY_TO_SHIP',
            'currency' => 'PHP',
            'buyer_name' => null,
            'lines' => [],
        ];

        // The marketplace's own words, normalised the way a real adapter would.
        $order['external_status'] = $order['status'];
        $order['status'] = strtoupper($order['status']) === 'CANCELLED' ? 'cancelled' : 'open';

        $order['total'] = 0.0;
        foreach ($order['lines'] as $line) {
            $order['total'] += (float) $line['quantity'] * (float) $line['unit_price'];
        }
        $order['raw'] = ['fake' => true] + $order;

        $this->orders[$order['external_order_id']] = $order;
    }

    /** The marketplace changed its mind about an order. */
    public function setStatus($externalOrderId, $status)
    {
        if (! isset($this->orders[$externalOrderId])) {
            return;
        }
        $this->orders[$externalOrderId]['external_status'] = $status;
        $this->orders[$externalOrderId]['status'] =
            strtoupper($status) === 'CANCELLED' ? 'cancelled' : 'open';
    }

    public function failNextFetch()
    {
        $this->failFetch = true;
    }

    public function pushedFor($sku)
    {
        return $this->pushed[$sku] ?? null;
    }

    public function pushCount()
    {
        return $this->pushCount;
    }
}
