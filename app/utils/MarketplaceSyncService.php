<?php

namespace App\utils;

use App\utils\Marketplace\FakeAdapter;
use App\utils\Marketplace\LazadaAdapter;
use App\utils\Marketplace\MarketplaceAdapter;
use App\utils\Marketplace\ShopeeAdapter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Marketplace orders in, stock levels out.
 *
 * THE RULE THAT MATTERS
 *
 * An order the marketplace has told us about is not the same thing as a sale.
 * Polling shows the same order on every run, sometimes for weeks, so the order
 * is recorded first under the marketplace's own identifier, and only then is a
 * sale made from it. The unique index on (account, external_order_id) is what
 * makes importing twice impossible rather than merely unlikely -- an import that
 * relied on remembering a timestamp would double-sell the moment a run was
 * interrupted halfway.
 *
 * A SKU NOBODY MAPPED IS NOT A GUESS
 *
 * The mapping between a marketplace listing and something on the shelf is the
 * piece a person has to get right. When an order arrives for a SKU that is not
 * mapped, the order is held and named, and no sale is made. The alternative --
 * matching on a product code that looks close enough -- sells the wrong thing
 * and is discovered at the packing bench.
 *
 * A MARKETPLACE SALE IS A SALE
 *
 * It goes through the same tables as everything else, so it takes stock off the
 * same shelf, writes the same movement, and reaches the same ledger. A separate
 * "online stock" figure is the thing this feature exists to prevent.
 */
class MarketplaceSyncService
{
    /** Overridden in tests; null means build from the account's channel. */
    private static $adapter = null;

    public static function useAdapter(MarketplaceAdapter $adapter = null)
    {
        self::$adapter = $adapter;
    }

    public static function adapterFor($channel)
    {
        if (self::$adapter) {
            return self::$adapter;
        }

        switch (strtolower((string) $channel)) {
            case 'shopee':
                return new ShopeeAdapter;
            case 'lazada':
                return new LazadaAdapter;
            default:
                return new FakeAdapter;
        }
    }

    /**
     * Pull orders for one account and turn the new ones into sales.
     */
    public static function importOrders($accountId)
    {
        $account = DB::table('marketplace_accounts')->where('id', $accountId)
            ->whereNull('deleted_at')->first();

        if (! $account) {
            return ['error' => 'no such account'];
        }

        $adapter = self::adapterFor($account->channel);
        $since = $account->orders_synced_at;

        // A token close to running out is renewed first; a shop that can no
        // longer be renewed stops here and asks for a reconnect.
        $credentials = MarketplaceAuth::credentials($account);
        if (isset($credentials['error'])) {
            return ['error' => $credentials['error'], 'imported' => 0, 'already' => 0, 'unmapped' => 0];
        }

        $response = $adapter->fetchOrders($credentials['account'], $since);

        // A marketplace that cannot be reached has told us nothing. Saying so is
        // the difference between "no new orders" and "we did not manage to ask",
        // and treating the second as the first is how orders quietly vanish.
        if (! $response['ok']) {
            Log::warning('Marketplace fetch failed for account ' . $accountId . ': ' . $response['error']);

            return ['error' => $response['error'], 'imported' => 0, 'already' => 0, 'unmapped' => 0];
        }

        $counts = ['imported' => 0, 'already' => 0, 'unmapped' => 0, 'cancelled' => 0, 'failed' => 0];
        // Orders recorded after this id are new in this run (a timestamp is not
        // enough: two polls inside one second would both count the same order).
        $lastSeenId = (int) DB::table('marketplace_orders')->max('id');

        foreach ($response['orders'] as $incoming) {
            $counts = self::absorb($account, $incoming, $counts);
        }

        DB::table('marketplace_accounts')->where('id', $accountId)
            ->update(['orders_synced_at' => now(), 'updated_at' => now()]);

        // Only orders held for the first time in this run: one already held is
        // seen again on every poll and was announced when it first arrived.
        $newlyHeld = DB::table('marketplace_orders')->where('marketplace_account_id', $accountId)
            ->where('state', 'unmapped')->where('id', '>', $lastSeenId)->count();
        ActivityNotifier::marketplaceOrdersWaiting($accountId, $newlyHeld);

        return $counts;
    }

    /**
     * One order from the marketplace, considered.
     */
    private static function absorb($account, array $incoming, array $counts)
    {
        $externalId = (string) $incoming['external_order_id'];

        $existing = DB::table('marketplace_orders')
            ->where('marketplace_account_id', $account->id)
            ->where('external_order_id', $externalId)
            ->first();

        // A cancellation for something already sold has to give the stock back.
        if ($incoming['status'] === 'cancelled') {
            if ($existing && $existing->state === 'imported' && $existing->sale_id) {
                $result = VoidService::void($existing->sale_id, 'Cancelled on ' . $account->channel);

                if (isset($result['error'])) {
                    // A void the system will not allow -- usually because the
                    // shift it belonged to has been counted down -- needs a
                    // person and a credit note, not a silent retry.
                    DB::table('marketplace_orders')->where('id', $existing->id)->update([
                        'external_status' => $incoming['external_status'],
                        'state_reason' => 'Cancelled on the marketplace but the sale could not be voided: '
                            . VoidService::refusal($result['error']),
                        'updated_at' => now(),
                    ]);
                    $counts['failed']++;

                    return $counts;
                }
            }

            if ($existing) {
                DB::table('marketplace_orders')->where('id', $existing->id)->update([
                    'state' => 'cancelled',
                    'external_status' => $incoming['external_status'],
                    'updated_at' => now(),
                ]);
            } else {
                self::record($account, $incoming, 'cancelled', null, null);
            }

            $counts['cancelled']++;

            return $counts;
        }

        // Already dealt with, however many times polling shows it again.
        if ($existing && in_array($existing->state, ['imported', 'ignored', 'cancelled'], true)) {
            $counts['already']++;

            return $counts;
        }

        // Every line has to resolve to something on the shelf before any of it
        // is sold, so a part-mapped order is held whole rather than half sold.
        $lines = [];
        $missing = [];

        foreach ($incoming['lines'] as $line) {
            $listing = DB::table('marketplace_listings')
                ->where('marketplace_account_id', $account->id)
                ->where('external_sku', $line['sku'])
                ->whereNull('deleted_at')
                ->first();

            if (! $listing) {
                $missing[] = $line['sku'];
                continue;
            }

            $lines[] = ['listing' => $listing, 'line' => $line];
        }

        if ($missing) {
            $reason = 'No product is mapped to: ' . implode(', ', array_unique($missing));

            if ($existing) {
                DB::table('marketplace_orders')->where('id', $existing->id)->update([
                    'state' => 'unmapped', 'state_reason' => $reason, 'updated_at' => now(),
                ]);
            } else {
                self::record($account, $incoming, 'unmapped', $reason, null);
            }

            $counts['unmapped']++;

            return $counts;
        }

        try {
            $saleId = self::makeSale($account, $incoming, $lines);
        } catch (\Throwable $e) {
            $reason = substr('Could not create the sale: ' . $e->getMessage(), 0, 255);
            Log::warning('Marketplace order ' . $externalId . ' failed: ' . $e->getMessage());

            if ($existing) {
                DB::table('marketplace_orders')->where('id', $existing->id)->update([
                    'state' => 'failed', 'state_reason' => $reason, 'updated_at' => now(),
                ]);
            } else {
                self::record($account, $incoming, 'failed', $reason, null);
            }

            $counts['failed']++;

            return $counts;
        }

        if ($existing) {
            DB::table('marketplace_orders')->where('id', $existing->id)->update([
                'state' => 'imported', 'state_reason' => null, 'sale_id' => $saleId,
                'external_status' => $incoming['external_status'], 'updated_at' => now(),
            ]);
        } else {
            self::record($account, $incoming, 'imported', null, $saleId);
        }

        $counts['imported']++;

        return $counts;
    }

    /**
     * A marketplace order, written as an ordinary sale.
     *
     * Through the same tables as a till sale on purpose: it takes stock off the
     * same shelf, writes the same movement, and reaches the same books. It is
     * flagged is_pos = 0 so it never appears in a drawer reading, because no
     * cash was taken at any till.
     */
    private static function makeSale($account, array $incoming, array $lines)
    {
        return DB::transaction(function () use ($account, $incoming, $lines) {
            StockLedger::context('marketplace', 'Sale', null, [
                'notes' => ucfirst($account->channel) . ' order ' . $incoming['external_order_id'],
            ]);

            $total = 0.0;
            foreach ($lines as $entry) {
                $total += (float) $entry['line']['quantity'] * (float) $entry['line']['unit_price'];
            }
            $total = round($total, 2);

            $saleId = DB::table('sales')->insertGetId([
                'user_id' => Auth::check() ? Auth::user()->id : $account->id,
                'date' => substr((string) $incoming['ordered_at'], 0, 10),
                // The same gap-free invoice sequence the till and the sales
                // screen draw from, so a marketplace order does not get a
                // reference from a different run of numbers.
                'Ref' => app('App\Http\Controllers\SalesController')->getNumberOrder(),
                'is_pos' => 0,
                'client_id' => $account->client_id,
                'warehouse_id' => $account->warehouse_id,
                'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
                'GrandTotal' => $total,
                'paid_amount' => $total,
                'payment_statut' => 'paid',
                'statut' => 'completed',
                'notes' => ucfirst($account->channel) . ' order ' . $incoming['external_order_id'],
                'created_at' => now(), 'updated_at' => now(),
            ]);

            StockLedger::context('marketplace', 'Sale', $saleId, [
                'notes' => ucfirst($account->channel) . ' order ' . $incoming['external_order_id'],
            ]);

            foreach ($lines as $entry) {
                $listing = $entry['listing'];
                $line = $entry['line'];
                $quantity = (float) $line['quantity'];

                DB::table('sale_details')->insert([
                    'date' => substr((string) $incoming['ordered_at'], 0, 10),
                    'sale_id' => $saleId,
                    'product_id' => $listing->product_id,
                    'product_variant_id' => $listing->product_variant_id,
                    'sale_unit_id' => DB::table('products')->where('id', $listing->product_id)
                        ->value('unit_sale_id'),
                    'quantity' => $quantity,
                    'price' => (float) $line['unit_price'],
                    'total' => round($quantity * (float) $line['unit_price'], 2),
                    'TaxNet' => 0, 'tax_method' => '1',
                    'discount' => 0, 'discount_method' => '2',
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                // Through the model, so the movement ledger and the negative
                // stock guard both apply exactly as they do at the till.
                $stock = \App\Models\product_warehouse::where('deleted_at', '=', null)
                    ->where('warehouse_id', $account->warehouse_id)
                    ->where('product_id', $listing->product_id)
                    ->when($listing->product_variant_id !== null, function ($q) use ($listing) {
                        return $q->where('product_variant_id', $listing->product_variant_id);
                    }, function ($q) {
                        return $q->whereNull('product_variant_id');
                    })
                    ->lockForUpdate()
                    ->first();

                if (! $stock) {
                    throw new \RuntimeException('there is no stock record for that product in this warehouse');
                }

                $stock->qte = (float) $stock->qte - $quantity;
                $stock->save();
            }

            StockLedger::clear();

            return $saleId;
        });
    }

    private static function record($account, array $incoming, $state, $reason, $saleId)
    {
        DB::table('marketplace_orders')->insert([
            'marketplace_account_id' => $account->id,
            'external_order_id' => (string) $incoming['external_order_id'],
            'external_status' => $incoming['external_status'] ?? null,
            'ordered_at' => $incoming['ordered_at'] ?? null,
            'total' => round((float) ($incoming['total'] ?? 0), 4),
            'currency' => $incoming['currency'] ?? 'PHP',
            'buyer_name' => $incoming['buyer_name'] ?? null,
            'state' => $state,
            'state_reason' => $reason ? substr($reason, 0, 255) : null,
            'sale_id' => $saleId,
            'raw' => json_encode($incoming['raw'] ?? $incoming),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Tell the marketplace what is actually on the shelf.
     *
     * Only where it has changed. These APIs are rate limited and most runs would
     * otherwise spend their whole budget re-sending numbers that did not move.
     */
    public static function pushStock($accountId)
    {
        $account = DB::table('marketplace_accounts')->where('id', $accountId)
            ->whereNull('deleted_at')->first();

        if (! $account) {
            return ['error' => 'no such account'];
        }

        $adapter = self::adapterFor($account->channel);
        $counts = ['pushed' => 0, 'skipped' => 0, 'failed' => 0];

        $listings = DB::table('marketplace_listings')
            ->where('marketplace_account_id', $accountId)
            ->where('push_stock', 1)
            ->whereNull('deleted_at')
            ->get();

        // Renewed only if there is anything to send.
        $credentials = null;
        if ($listings->isNotEmpty()) {
            $credentials = MarketplaceAuth::credentials($account);
            if (isset($credentials['error'])) {
                return ['error' => $credentials['error']];
            }
        }

        foreach ($listings as $listing) {
            $available = self::availableFor($listing, $account->warehouse_id);

            if ($listing->last_pushed_qty !== null
                && abs((float) $listing->last_pushed_qty - $available) < 0.0001) {
                $counts['skipped']++;
                continue;
            }

            $result = $adapter->pushStock($credentials['account'], (array) $listing, $available);

            if (! $result['ok']) {
                DB::table('marketplace_listings')->where('id', $listing->id)->update([
                    'last_push_error' => substr((string) $result['error'], 0, 255),
                    'updated_at' => now(),
                ]);
                $counts['failed']++;
                continue;
            }

            DB::table('marketplace_listings')->where('id', $listing->id)->update([
                'last_pushed_qty' => $available,
                'last_pushed_at' => now(),
                'last_push_error' => null,
                'updated_at' => now(),
            ]);
            $counts['pushed']++;
        }

        return $counts;
    }

    /**
     * How many of this listing there are to sell.
     *
     * What is on the shelf in the account's warehouse, and never below zero --
     * a negative pushed to a marketplace is either rejected or, worse, read as
     * something else entirely.
     */
    public static function availableFor($listing, $warehouseId)
    {
        $qty = DB::table('product_warehouse')
            ->whereNull('deleted_at')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $listing->product_id)
            ->when($listing->product_variant_id !== null, function ($q) use ($listing) {
                return $q->where('product_variant_id', $listing->product_variant_id);
            }, function ($q) {
                return $q->whereNull('product_variant_id');
            })
            ->sum('qte');

        return max(0.0, round((float) $qty, 4));
    }

    /**
     * Put one held order through again, from the payload already stored.
     *
     * Deliberately does not ask the marketplace. Somebody has just mapped a SKU
     * and is watching the screen; making that depend on a remote call would fail
     * exactly when the marketplace is the thing that is unavailable, and the
     * order's own payload was kept when it first arrived precisely so it need
     * not be fetched twice.
     */
    public static function retryOrder($orderId)
    {
        $order = DB::table('marketplace_orders')->where('id', $orderId)
            ->whereNull('deleted_at')->first();

        if (! $order) {
            return ['error' => 'no such order'];
        }

        $account = DB::table('marketplace_accounts')
            ->where('id', $order->marketplace_account_id)->first();

        if (! $account) {
            return ['error' => 'the account for that order is gone'];
        }

        // Already dealt with; saying so is the whole answer.
        if (in_array($order->state, ['imported', 'ignored', 'cancelled'], true)) {
            return ['state' => $order->state, 'sale_id' => $order->sale_id];
        }

        $raw = json_decode((string) $order->raw, true);
        if (! is_array($raw)) {
            return ['error' => 'the stored marketplace payload cannot be read'];
        }

        // The payload was stored in the normalised shape, so it goes back
        // through exactly the path a freshly fetched order takes.
        $incoming = [
            'external_order_id' => $order->external_order_id,
            'status' => $raw['status'] ?? 'open',
            'external_status' => $order->external_status,
            'ordered_at' => $order->ordered_at,
            'total' => (float) $order->total,
            'currency' => $order->currency,
            'buyer_name' => $order->buyer_name,
            'lines' => $raw['lines'] ?? [],
            'raw' => $raw,
        ];

        self::absorb($account, $incoming, ['imported' => 0, 'already' => 0,
            'unmapped' => 0, 'cancelled' => 0, 'failed' => 0]);

        $after = DB::table('marketplace_orders')->where('id', $orderId)->first();

        return ['state' => $after->state, 'sale_id' => $after->sale_id,
                'reason' => $after->state_reason];
    }

    /**
     * The orders waiting on a person, and what each of them is waiting for.
     *
     * This is the queue the screen is built around: an order held because a SKU
     * is unmapped is not an error, it is a job, and it stays invisible until
     * somebody is shown it.
     */
    public static function needingAttention($limit = 200)
    {
        $rows = DB::table('marketplace_orders as o')
            ->leftJoin('marketplace_accounts as a', 'a.id', '=', 'o.marketplace_account_id')
            ->whereNull('o.deleted_at')
            ->whereIn('o.state', ['unmapped', 'failed'])
            ->orderByDesc('o.ordered_at')
            ->limit($limit)
            ->select('o.*', 'a.name as account_name', 'a.channel')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $raw = json_decode((string) $row->raw, true);
            $skus = [];

            foreach (($raw['lines'] ?? []) as $line) {
                $sku = (string) ($line['sku'] ?? '');
                if ($sku === '') {
                    continue;
                }
                $mapped = DB::table('marketplace_listings')
                    ->where('marketplace_account_id', $row->marketplace_account_id)
                    ->where('external_sku', $sku)
                    ->whereNull('deleted_at')
                    ->exists();

                if (! $mapped) {
                    $skus[] = $sku;
                }
            }

            $out[] = [
                'id' => (int) $row->id,
                'account_id' => (int) $row->marketplace_account_id,
                'account_name' => $row->account_name,
                'channel' => $row->channel,
                'external_order_id' => $row->external_order_id,
                'ordered_at' => $row->ordered_at,
                'total' => round((float) $row->total, 2),
                'currency' => $row->currency,
                'buyer_name' => $row->buyer_name,
                'state' => $row->state,
                'reason' => $row->state_reason,
                'missing_skus' => array_values(array_unique($skus)),
                'lines' => $raw['lines'] ?? [],
            ];
        }

        return $out;
    }

    /** Accounts the scheduler should work through. */
    public static function activeAccounts()
    {
        return DB::table('marketplace_accounts')
            ->whereNull('deleted_at')->where('is_active', 1)->get();
    }
}
