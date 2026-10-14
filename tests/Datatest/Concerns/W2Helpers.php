<?php

namespace Tests\Datatest\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Extra fixtures and drivers for the W2 data-test regression tests
 * (.flow/DATATEST-2026-10-06.md). Used alongside Tests\Datatest\DatatestCase;
 * it only adds rows and wraps API calls the way the testers made them.
 */
trait W2Helpers
{
    /** Clear the per-process caches the money code keeps, so one test cannot leak into the next. */
    protected function forgetCaches()
    {
        \App\utils\PricingService::forget();
        \App\utils\VatService::forget();
        \App\utils\CurrencyService::forget();
        \App\utils\LedgerService::forget();
        \App\utils\ProductKind::forget();
        \App\utils\BatchService::forget();
    }

    /** A further product sold by the bag, stocked at Main and Branch. */
    protected function product(array $attrs = [], $mainQty = 100, $branchQty = 100)
    {
        static $n = 0;
        $n++;
        $id = DB::table('products')->insertGetId(array_merge([
            'code' => 'W2-' . $n . '-' . substr(uniqid(), -6), 'Type_barcode' => 'CODE128', 'name' => "W2 item $n",
            'cost' => 230, 'price' => 265, 'category_id' => DB::table('categories')->value('id'), 'unit_id' => $this->bag,
            'unit_sale_id' => $this->bag, 'unit_purchase_id' => $this->bag, 'TaxNet' => 0, 'tax_method' => '2',
        ], $attrs));
        DB::table('product_warehouse')->insert([
            ['product_id' => $id, 'warehouse_id' => $this->main, 'qte' => $mainQty],
            ['product_id' => $id, 'warehouse_id' => $this->branch, 'qte' => $branchQty],
        ]);

        return $id;
    }

    /** A sale line for any product. */
    protected function lineOf($product, $qty, $price, array $extra = [])
    {
        return $this->line($qty, $price, array_merge(['product_id' => $product], $extra));
    }

    /** A senior-citizen sale's statutory fields, with a proper ID and holder. */
    protected function senior(array $extra = [])
    {
        return array_merge(['statutory_type' => 'senior', 'statutory_id_number' => 'SC-2026-0001',
            'statutory_holder_name' => 'Lola Remedios', 'statutory_beneficiaries' => 1, 'statutory_total_persons' => 1], $extra);
    }

    /** POS sale with nothing tendered. */
    protected function posUnpaid(array $lines, $warehouse = null, array $extra = [])
    {
        return $this->pos($lines, $warehouse, array_merge(['amount' => 0, 'received_amount' => 0, 'change' => 0], $extra));
    }

    /** A back-office sale (POST /api/sales), unpaid unless an amount is given. */
    protected function storeSale(array $lines, array $extra = [])
    {
        $total = array_sum(array_column($lines, 'subtotal'));
        $amount = $extra['amount'] ?? 0;

        return $this->postJson('/api/sales', array_merge([
            'client_id' => $this->client, 'warehouse_id' => $this->main, 'date' => now()->toDateString(),
            'statut' => 'completed', 'GrandTotal' => $total, 'amount' => $amount, 'change' => 0, 'notes' => null,
            'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
            'payment' => ['status' => $amount > 0 ? 'paid' : 'pending', 'Reglement' => 'Cash'],
            'details' => $lines,
        ], $extra));
    }

    /** The PUT /api/sales/{id} body for a saved sale, as the edit screen sends it, with optional changes. */
    protected function saleEditPayload($saleId, array $quantities = [], array $extra = [])
    {
        $sale = DB::table('sales')->where('id', $saleId)->first();
        $details = [];
        foreach (DB::table('sale_details')->where('sale_id', $saleId)->orderBy('id')->get() as $i => $d) {
            $qty = $quantities[$i] ?? (float) $d->quantity;
            $details[] = ['id' => $d->id, 'no_unit' => 1, 'product_id' => $d->product_id,
                'product_variant_id' => $d->product_variant_id, 'sale_unit_id' => $d->sale_unit_id, 'quantity' => $qty,
                'Unit_price' => (float) $d->price, 'tax_percent' => (float) $d->TaxNet, 'tax_method' => $d->tax_method,
                'discount' => (float) $d->discount, 'discount_Method' => $d->discount_method,
                'subtotal' => round((float) $d->price * $qty, 2)];
        }

        return array_merge([
            'date' => substr($sale->date, 0, 10), 'client_id' => $sale->client_id, 'warehouse_id' => $sale->warehouse_id,
            'statut' => $sale->statut, 'notes' => null, 'tax_rate' => (float) $sale->tax_rate,
            'discount' => (float) $sale->discount, 'shipping' => (float) $sale->shipping,
            'GrandTotal' => array_sum(array_column($details, 'subtotal')), 'details' => $details,
        ], $extra);
    }

    /** A purchase line, priced per bag. */
    protected function purchaseLine($qty, $cost = 230, $product = null, array $extra = [])
    {
        return array_merge(['product_id' => $product ?: $this->cement, 'product_variant_id' => null,
            'purchase_unit_id' => $this->bag, 'quantity' => $qty, 'Unit_cost' => $cost, 'subtotal' => $qty * $cost,
            'discount' => 0, 'discount_Method' => '2', 'tax_percent' => 0, 'tax_method' => '1'], $extra);
    }

    protected function storePurchase(array $lines, $statut = 'received', array $extra = [])
    {
        return $this->postJson('/api/purchases', array_merge([
            'supplier_id' => $this->supplier, 'warehouse_id' => $this->main, 'date' => now()->toDateString(),
            'statut' => $statut, 'notes' => null, 'tax_rate' => 0, 'discount' => 0, 'shipping' => 0,
            'GrandTotal' => array_sum(array_column($lines, 'subtotal')), 'details' => $lines,
        ], $extra));
    }

    /** A customer return of goods from a sale (linked to it, as the owner decided returns must be). */
    protected function saleReturn($saleId, array $lines, $warehouse = null, array $extra = [])
    {
        return $this->postJson('/api/returns/sale', array_merge([
            'sale_id' => $saleId, 'client_id' => $this->client, 'warehouse_id' => $warehouse ?: $this->main,
            'date' => now()->toDateString(), 'statut' => 'received', 'notes' => null, 'tax_rate' => 0, 'discount' => 0,
            'shipping' => 0, 'GrandTotal' => array_sum(array_column($lines, 'subtotal')), 'details' => $lines,
        ], $extra));
    }

    protected function refund($saleReturnId, $amount, $method = 'Cash')
    {
        return $this->postJson('/api/payment/returns_sale', ['sale_return_id' => $saleReturnId,
            'date' => now()->toDateString(), 'montant' => $amount, 'change' => 0, 'Reglement' => $method, 'notes' => null]);
    }

    protected function paySale($saleId, $amount, $method = 'Cash')
    {
        return $this->postJson('/api/payment/sale', ['sale_id' => $saleId, 'date' => now()->toDateString(),
            'montant' => $amount, 'change' => 0, 'Reglement' => $method, 'notes' => null]);
    }

    /** P&L for today, as the owner sees it. */
    protected function profitAndLoss()
    {
        $today = now()->toDateString();

        return $this->getJson("/api/report/ProfitAndLoss?from=$today&to=$today")->assertOk()->json('data');
    }

    /** Post everything outstanding to the journal, as the scheduled ledger:post does. */
    protected function postLedger()
    {
        $this->artisan('ledger:post', ['--force' => true])->assertExitCode(0);
    }

    /** Net movement on an account across the whole journal: debits minus credits. */
    protected function journalNet($slug, $sourceType = null, $sourceId = null)
    {
        $q = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.slug', $slug);
        if ($sourceType) {
            $q->where('e.source_type', $sourceType);
        }
        if ($sourceId) {
            $q->where('e.source_id', $sourceId);
        }

        return round((float) $q->sum(DB::raw('l.debit - l.credit')), 2);
    }

    /** The newest sale row. */
    protected function lastSale()
    {
        return DB::table('sales')->orderByDesc('id')->first();
    }

    protected function money($v)
    {
        return round((float) $v, 2);
    }
}
