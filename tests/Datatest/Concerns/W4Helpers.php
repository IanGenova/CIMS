<?php

namespace Tests\Datatest\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Drivers for the W4 data-test regressions (concurrency and idempotency
 * clusters that also reproduce one request at a time): offline sync, online
 * replays, tills and POS holds. Used by DatatestCase subclasses, which supply
 * $this->cement, $this->bag, $this->main, etc. The racing halves of these
 * clusters live in tests/datatest-races/.
 */
trait W4Helpers
{
    /**
     * Every HTTP request starts as a fresh PHP process does in production, so
     * static state from one request cannot leak into the next.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        \App\utils\StockLedger::clear();
        \App\utils\StockLedger::fallback(null);
        \App\utils\BatchService::forget();
        \App\utils\PricingService::forget();
        \App\utils\ProductKind::forget();
        \App\utils\VatService::forget();
        \App\utils\CurrencyService::forget();
        \App\utils\LedgerService::forget();
        \App\utils\LocationService::forget();
        \App\utils\WarehouseAccess::forget();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function setShelf($qty, $warehouse = null, $product = null)
    {
        DB::table('product_warehouse')->where('product_id', $product ?: $this->cement)
            ->where('warehouse_id', $warehouse ?: $this->main)->update(['qte' => $qty]);
    }

    /** The body CreatePOS takes, as the till queues it offline. */
    protected function posPayload(array $lines, $warehouse = null, array $extra = [])
    {
        $total = array_sum(array_column($lines, 'subtotal'));

        return array_merge(['client_id' => $this->client, 'warehouse_id' => $warehouse ?: $this->main,
            'GrandTotal' => $total, 'amount' => $total, 'received_amount' => $total, 'change' => 0,
            'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'payment' => ['Reglement' => 'Cash'],
            'details' => $lines], $extra);
    }

    /** POST /api/offline/sales/sync with one queued sale. */
    protected function offlineSync($key, array $payload)
    {
        return $this->postJson('/api/offline/sales/sync', ['sales' => [['idempotency_key' => $key, 'payload' => $payload]]]);
    }

    protected function offlineKeyRows($key)
    {
        return DB::table('offline_sale_keys')->where('idempotency_key', $key)->get();
    }

    /** A back-office sale body (POST /api/sales), unpaid. */
    protected function storeSaleBody(array $lines, array $extra = [])
    {
        $total = array_sum(array_column($lines, 'subtotal'));

        return array_merge(['client_id' => $this->client, 'warehouse_id' => $this->main, 'date' => now()->toDateString(),
            'statut' => 'completed', 'GrandTotal' => $total, 'amount' => 0, 'change' => 0, 'notes' => 'W4',
            'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
            'payment' => ['status' => 'pending', 'Reglement' => 'Cash'], 'details' => $lines], $extra);
    }

    protected function openTill($warehouse, $float, $terminal = null)
    {
        $body = ['warehouse_id' => $warehouse, 'opening_float' => $float];
        if ($terminal !== null) {
            $body['terminal'] = $terminal;
        }

        return $this->postJson('/api/till/open', $body);
    }

    /** POST /api/pos/holds: park a basket of cement. */
    protected function holdBasket($qty, $warehouse = null)
    {
        return $this->postJson('/api/pos/holds', ['warehouse_id' => $warehouse ?: $this->main, 'label' => 'W4 hold',
            'cart' => [['product_id' => $this->cement, 'sale_unit_id' => $this->bag, 'quantity' => $qty]],
            'total' => 265 * $qty]);
    }

    protected function liveSales()
    {
        return DB::table('sales')->whereNull('deleted_at')->count();
    }
}
