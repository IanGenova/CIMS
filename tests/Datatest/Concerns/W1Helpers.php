<?php

namespace Tests\Datatest\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Request bodies for the documents the W1 data-test regressions drive: sales
 * (screen, not POS), purchases, goods receipts, returns, transfers and
 * adjustments, shaped as the screens send them (see tests/Feature/SerialServiceTest).
 * Used by DatatestCase subclasses, which supply $this->cement, $this->bag, etc.
 */
trait W1Helpers
{
    /**
     * Every HTTP request starts as a fresh PHP process does in production.
     *
     * PHPUnit runs all requests of a test in one process, so static state set by
     * one request (above all StockLedger's context, which store() sets and does
     * not always clear) would otherwise leak into the next one and name its
     * movements after the previous document -- hiding exactly the defects these
     * tests exist to show.
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

    /**
     * migrate:fresh, retried when MySQL answers 1615 "Prepared statement needs
     * to be re-prepared" -- a server-side table-definition-cache hiccup while
     * other test databases are being rebuilt on the same server, not a defect
     * of the product. Everything else is RefreshDatabase as it is.
     */
    protected function refreshTestDatabase()
    {
        if (! \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated) {
            for ($attempt = 1; ; $attempt++) {
                try {
                    $this->artisan('migrate:fresh', $this->migrateFreshUsing());
                    break;
                } catch (\Illuminate\Database\QueryException $e) {
                    if ($attempt >= 5 || strpos($e->getMessage(), '1615') === false) {
                        throw $e;
                    }
                }
            }
            $this->app[\Illuminate\Contracts\Console\Kernel::class]->setArtisan(null);
            \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = true;
        }

        $this->beginDatabaseTransaction();
    }

    protected function today()
    {
        return now()->toDateString();
    }

    // ------------------------------------------------------------------ sales

    /** POST /api/sales (the Sales screen, not the POS). */
    protected function sale(array $lines, $statut = 'completed', $warehouse = null, array $extra = [])
    {
        return $this->postJson('/api/sales', array_merge($this->saleBody($lines, $statut, $warehouse), $extra));
    }

    protected function saleBody(array $lines, $statut = 'completed', $warehouse = null)
    {
        $total = array_sum(array_column($lines, 'subtotal'));

        return ['date' => $this->today(), 'client_id' => $this->client, 'warehouse_id' => $warehouse ?: $this->main,
            'statut' => $statut, 'notes' => 'W1', 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
            'GrandTotal' => $total, 'amount' => 0, 'received_amount' => 0, 'change' => 0,
            'payment' => ['status' => 'pending', 'Reglement' => 'Cash'], 'details' => $lines];
    }

    /** PUT /api/sales/{id}: each existing line is sent back with its detail id. */
    protected function editSale($id, array $lines, $statut = 'completed', $warehouse = null)
    {
        return $this->putJson("/api/sales/{$id}", $this->saleBody($lines, $statut, $warehouse));
    }

    /** A saved sale line, ready to send back on an edit. */
    protected function savedSaleLine($saleId, $qty, $price = 265)
    {
        $detail = DB::table('sale_details')->where('sale_id', $saleId)->value('id');

        return $this->line($qty, $price, ['id' => $detail, 'no_unit' => 1]);
    }

    // ------------------------------------------------------------------ purchases

    protected function buyLine($qty = 10, $cost = 230, array $extra = [])
    {
        return array_merge(['id' => 0, 'no_unit' => 1, 'product_id' => $this->cement, 'product_variant_id' => null,
            'purchase_unit_id' => $this->bag, 'quantity' => $qty, 'Unit_cost' => $cost, 'subtotal' => $qty * $cost,
            'discount' => 0, 'discount_Method' => '2', 'tax_percent' => 0, 'tax_method' => '1'], $extra);
    }

    protected function purchaseBody(array $lines, $statut = 'ordered')
    {
        return ['date' => $this->today(), 'supplier_id' => $this->supplier, 'warehouse_id' => $this->main,
            'statut' => $statut, 'notes' => 'W1', 'tax_rate' => 0, 'discount' => 0, 'shipping' => 0,
            'GrandTotal' => array_sum(array_column($lines, 'subtotal')), 'details' => $lines];
    }

    /** POST /api/purchases; returns the new purchase id (the endpoint answers without one). */
    protected function purchase(array $lines, $statut = 'ordered')
    {
        $this->postJson('/api/purchases', $this->purchaseBody($lines, $statut))->assertOk();

        return $this->lastId('purchases');
    }

    protected function editPurchase($id, array $lines, $statut)
    {
        return $this->putJson("/api/purchases/{$id}", $this->purchaseBody($lines, $statut));
    }

    protected function purchaseDetailId($purchaseId)
    {
        return DB::table('purchase_details')->where('purchase_id', $purchaseId)->value('id');
    }

    protected function receive($purchaseId, $received, $rejected = 0)
    {
        return $this->postJson('/api/goods_receipts', ['purchase_id' => $purchaseId, 'date' => $this->today(),
            'details' => [['purchase_detail_id' => $this->purchaseDetailId($purchaseId),
                'received_qte' => $received, 'rejected_qte' => $rejected]]]);
    }

    protected function cost($product = null)
    {
        return (float) DB::table('products')->where('id', $product ?: $this->cement)->value('cost');
    }

    // ------------------------------------------------------------------ returns

    protected function saleReturn(array $lines, array $extra = [])
    {
        return $this->postJson('/api/returns/sale', array_merge(['date' => $this->today(), 'client_id' => $this->client,
            'warehouse_id' => $this->main, 'statut' => 'received', 'notes' => 'W1', 'tax_rate' => 0, 'TaxNet' => 0,
            'discount' => 0, 'shipping' => 0, 'GrandTotal' => array_sum(array_column($lines, 'subtotal')),
            'details' => $lines], $extra));
    }

    protected function purchaseReturn(array $lines, array $extra = [])
    {
        return $this->postJson('/api/returns/purchase', array_merge(['date' => $this->today(), 'supplier_id' => $this->supplier,
            'warehouse_id' => $this->main, 'statut' => 'completed', 'notes' => 'W1', 'tax_rate' => 0, 'discount' => 0,
            'shipping' => 0, 'GrandTotal' => array_sum(array_column($lines, 'subtotal')), 'details' => $lines], $extra));
    }

    // ------------------------------------------------------------------ transfers

    protected function moveLine($qty, array $extra = [])
    {
        return array_merge(['id' => 0, 'no_unit' => 1, 'product_id' => $this->cement, 'product_variant_id' => null,
            'purchase_unit_id' => $this->bag, 'quantity' => $qty, 'Unit_cost' => 230, 'subtotal' => 230 * $qty,
            'tax_percent' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_Method' => '2'], $extra);
    }

    protected function transferBody(array $lines, $statut = 'completed', $from = null, $to = null)
    {
        return ['transfer' => ['date' => $this->today(), 'from_warehouse' => $from ?: $this->main,
            'to_warehouse' => $to ?: $this->branch, 'statut' => $statut, 'notes' => 'W1', 'tax_rate' => 0, 'TaxNet' => 0,
            'discount' => 0, 'shipping' => 0], 'details' => $lines, 'GrandTotal' => array_sum(array_column($lines, 'subtotal'))];
    }

    protected function transfer(array $lines, $statut = 'completed', $from = null, $to = null)
    {
        return $this->postJson('/api/transfers', $this->transferBody($lines, $statut, $from, $to));
    }

    // ------------------------------------------------------------------ adjustments

    protected function adjLine($qty, $type = 'add', array $extra = [])
    {
        return array_merge(['id' => 0, 'product_id' => $this->cement, 'product_variant_id' => null, 'quantity' => $qty,
            'type' => $type], $extra);
    }

    protected function adjust(array $lines, $warehouse = null)
    {
        return $this->postJson('/api/adjustments', ['date' => $this->today(), 'warehouse_id' => $warehouse ?: $this->main,
            'notes' => 'W1', 'details' => $lines]);
    }

    protected function editAdjustment($id, array $lines, $warehouse = null)
    {
        return $this->putJson("/api/adjustments/{$id}", ['date' => $this->today(), 'warehouse_id' => $warehouse ?: $this->main,
            'notes' => 'W1', 'details' => $lines]);
    }

    // ------------------------------------------------------------------ ledger

    /** stock_movements rows for a product at a warehouse, oldest first. */
    protected function movements($warehouse = null, $product = null, $afterId = 0)
    {
        return DB::table('stock_movements')->where('product_id', $product ?: $this->cement)
            ->where('warehouse_id', $warehouse ?: $this->main)->where('id', '>', $afterId)->orderBy('id')->get();
    }

    protected function lastMovementId()
    {
        return (int) DB::table('stock_movements')->max('id');
    }

    /** A status that is a refusal: 4xx, not a crash. */
    protected function assertRefused($response, $message)
    {
        $status = $response->getStatusCode();
        $this->assertTrue($status >= 400 && $status < 500, "$message (got HTTP $status: " . substr($response->getContent(), 0, 200) . ')');
    }
}
