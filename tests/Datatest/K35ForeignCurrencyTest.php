<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W2Helpers;

/**
 * K35 — reports and the journal add document-currency amounts as pesos,
 * base_amount is never read, and purchases never stamp a currency
 * (M7: amounts in a non-base currency convert at the stored exchange rate).
 * The item is VAT-exempt so the expected figures do not depend on K28.
 */
class K35ForeignCurrencyTest extends DatatestCase
{
    use W2Helpers;

    private $usd;
    private $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetCaches();
        DB::table('currencies')->insert(['code' => 'PHP', 'name' => 'Peso', 'symbol' => 'P', 'exchange_rate' => 1, 'is_base' => 1]);
        $this->usd = DB::table('currencies')->insertGetId(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$',
            'exchange_rate' => 0.0175, 'is_base' => 0]);
        $this->item = $this->product(['price' => 10, 'cost' => 5, 'vat_class' => 'vat_exempt']);
        \App\utils\CurrencyService::forget();
    }

    private function usdSale()
    {
        $this->as($this->owner)->storeSale([$this->lineOf($this->item, 1, 10)],
            ['currency_id' => $this->usd, 'amount' => 10])->assertOk();
        $sale = $this->lastSale();
        $this->assertEquals(571.43, $this->money($sale->base_amount), 'fixture: 10 USD at 0.0175 is 571.43 pesos');

        return $sale;
    }

    /**
     * K35 — a 10.00 USD sale (571.43 pesos) is reported as 10.00 in the P&L sales total and revenue.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_profit_and_loss_reports_a_foreign_sale_in_pesos()
    {
        $this->usdSale();

        $pnl = $this->profitAndLoss();

        $this->assertEquals(571.43, $this->money($pnl['sales']['sum']),
            "K35: the P&L sales total reads {$pnl['sales']['sum']} for a sale worth 571.43 pesos");
        $this->assertEquals(571.43, $this->money($pnl['revenue']),
            "K35: the P&L revenue reads {$pnl['revenue']} for a sale worth 571.43 pesos");
    }

    /**
     * K35 — the journal posts the 10.00 USD sale as 10.00 pesos of cash and revenue.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_the_journal_posts_a_foreign_sale_in_pesos()
    {
        $sale = $this->usdSale();

        $this->postLedger();

        $cash = $this->journalNet('cash', 'Sale', $sale->id);
        $this->assertEquals(571.43, $cash, "K35: the journal debited cash {$cash} for a sale worth 571.43 pesos");
    }

    /**
     * K35 — a purchase sent with currency_id USD is saved with no currency (currency_id NULL, rate 1).
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_purchase_keeps_its_currency()
    {
        $this->as($this->owner)->storePurchase([$this->purchaseLine(10, 5, $this->item)], 'received',
            ['currency_id' => $this->usd])->assertOk();

        $purchase = DB::table('purchases')->orderByDesc('id')->first();
        $this->assertEquals($this->usd, (int) $purchase->currency_id,
            'K35: a USD purchase was saved with currency_id ' . var_export($purchase->currency_id, true));
        $this->assertEquals(0.0175, (float) $purchase->exchange_rate, 'K35: the USD purchase did not stamp its rate');
    }
}
