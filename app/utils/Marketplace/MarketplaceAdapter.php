<?php

namespace App\utils\Marketplace;

/**
 * What a marketplace has to be able to do.
 *
 * Everything that decides anything -- whether an order has been seen before,
 * what to do about a SKU nobody mapped, whether stock is worth pushing -- lives
 * in the sync service and is the same for Shopee as for Lazada. This is only the
 * part that differs: how to ask, and how to read the answer.
 *
 * Orders come back normalised, so the service never sees a marketplace's own
 * field names or its own words for a status. A marketplace that invents a new
 * status maps to 'other' here rather than leaking upward, because a status the
 * service does not recognise must not be mistaken for one it does.
 */
interface MarketplaceAdapter
{
    /**
     * Orders placed since a moment, oldest first.
     *
     * Returns:
     *   [
     *     'ok' => bool,
     *     'orders' => [
     *       [
     *         'external_order_id' => string,
     *         'status' => 'open'|'cancelled'|'other',
     *         'external_status' => string,   what the marketplace actually called it
     *         'ordered_at' => 'Y-m-d H:i:s',
     *         'total' => float,
     *         'currency' => string,
     *         'buyer_name' => string|null,
     *         'lines' => [['sku' => string, 'quantity' => float, 'unit_price' => float], ...],
     *         'raw' => array,
     *       ], ...
     *     ],
     *     'error' => string|null,
     *   ]
     *
     * A failure must return ok=false rather than an empty list: "nothing new"
     * and "could not ask" are different answers, and treating the second as the
     * first is how orders get lost.
     */
    public function fetchOrders(array $account, $since);

    /**
     * Tell the marketplace how many are available.
     *
     * Returns ['ok' => bool, 'error' => string|null].
     */
    public function pushStock(array $account, array $listing, $quantity);
}
