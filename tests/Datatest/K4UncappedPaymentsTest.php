<?php

namespace Tests\Datatest;

use App\utils\Wallet\FakeGateway;
use App\utils\WalletPaymentService;
use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W2Helpers;

/**
 * K4 — "paid <= total" is enforced only on sale and purchase payment create.
 * Refunds, payment edits, wallet attach and sale edits are uncapped (M4: paid never exceeds the total).
 */
class K4UncappedPaymentsTest extends DatatestCase
{
    use W2Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetCaches();
    }

    protected function tearDown(): void
    {
        WalletPaymentService::useGateway(null);
        parent::tearDown();
    }

    /**
     * K4 — a 265.00 sale return can be refunded three times (795.00 paid out).
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_sale_return_cannot_be_refunded_more_than_its_total()
    {
        $this->as($this->owner)->pos([$this->line(2)])->assertOk();
        $saleId = $this->lastId('sales');
        $this->saleReturn($saleId, [$this->line(1)])->assertOk();
        $returnId = $this->lastId('sale_returns');

        for ($i = 0; $i < 3; $i++) {
            $this->refund($returnId, 265);
        }

        $return = DB::table('sale_returns')->where('id', $returnId)->first();
        $this->assertEquals(265, $this->money($return->GrandTotal), 'fixture: one bag returned');
        $this->assertLessThanOrEqual(265, $this->money($return->paid_amount),
            "K4: a 265.00 return was refunded {$return->paid_amount}");
        $this->assertLessThanOrEqual(265, $this->money(DB::table('payment_sale_returns')
            ->where('sale_return_id', $returnId)->whereNull('deleted_at')->sum('montant')), 'K4: refund rows exceed the return');
    }

    /**
     * K4 — a sale payment edited from 10 to 5,000 is accepted on a 265.00 sale.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_sale_payment_edit_cannot_pay_more_than_the_total()
    {
        $this->as($this->owner)->posUnpaid([$this->line(1)])->assertOk();
        $saleId = $this->lastId('sales');
        $this->paySale($saleId, 10)->assertOk();
        $paymentId = $this->lastId('payment_sales');

        $this->putJson("/api/payment/sale/$paymentId", ['sale_id' => $saleId, 'date' => now()->toDateString(),
            'montant' => 5000, 'change' => 0, 'Reglement' => 'Cash', 'notes' => null]);

        $sale = DB::table('sales')->where('id', $saleId)->first();
        $this->assertLessThanOrEqual(265, $this->money($sale->paid_amount),
            "K4: an edited payment left a 265.00 sale with {$sale->paid_amount} paid");
    }

    /**
     * K4 — a purchase payment edited to 99,999 is accepted on a 2,300.00 purchase.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_purchase_payment_edit_cannot_pay_more_than_the_total()
    {
        $this->as($this->owner)->storePurchase([$this->purchaseLine(10)])->assertOk();
        $purchaseId = $this->lastId('purchases');
        $this->postJson('/api/payment/purchase', ['purchase_id' => $purchaseId, 'date' => now()->toDateString(),
            'montant' => 100, 'change' => 0, 'Reglement' => 'Cash'])->assertOk();
        $paymentId = $this->lastId('payment_purchases');

        $this->putJson("/api/payment/purchase/$paymentId", ['purchase_id' => $purchaseId, 'date' => now()->toDateString(),
            'montant' => 99999, 'change' => 0, 'Reglement' => 'Cash']);

        $purchase = DB::table('purchases')->where('id', $purchaseId)->first();
        $this->assertEquals(2300, $this->money($purchase->GrandTotal), 'fixture: 10 bags at 230');
        $this->assertLessThanOrEqual(2300, $this->money($purchase->paid_amount),
            "K4: an edited payment left a 2,300.00 purchase with {$purchase->paid_amount} paid");
    }

    /**
     * K4 — a purchase payment edit naming another purchase_id in the body rewrites that other bill.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_purchase_payment_edit_stays_on_its_own_purchase()
    {
        $this->as($this->owner)->storePurchase([$this->purchaseLine(10)])->assertOk();
        $first = $this->lastId('purchases');
        $this->storePurchase([$this->purchaseLine(5)])->assertOk();
        $other = $this->lastId('purchases');
        $this->postJson('/api/payment/purchase', ['purchase_id' => $first, 'date' => now()->toDateString(),
            'montant' => 1000, 'change' => 0, 'Reglement' => 'Cash'])->assertOk();
        $paymentId = $this->lastId('payment_purchases');

        $this->putJson("/api/payment/purchase/$paymentId", ['purchase_id' => $other, 'date' => now()->toDateString(),
            'montant' => 100, 'change' => 0, 'Reglement' => 'Cash']);

        $payment = DB::table('payment_purchases')->where('id', $paymentId)->first();
        $firstRow = DB::table('purchases')->where('id', $first)->first();
        $otherRow = DB::table('purchases')->where('id', $other)->first();
        $this->assertEquals((int) $first, (int) $payment->purchase_id, 'fixture: the payment row stays on its purchase');
        $this->assertEquals(0, $this->money($otherRow->paid_amount),
            "K4: purchase {$other} was never paid but now reads paid {$otherRow->paid_amount}");
        $this->assertEquals($this->money(DB::table('payment_purchases')->where('purchase_id', $first)->whereNull('deleted_at')->sum('montant')),
            $this->money($firstRow->paid_amount), 'K4: the edited purchase no longer agrees with its own payment rows');
    }

    /**
     * K4 — a settled 1,000.00 wallet payment attaches to a 265.00 sale, leaving it paid 1,000.00.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_wallet_payment_cannot_be_attached_beyond_what_is_due()
    {
        config(['wallet.driver' => 'fake']);
        $gateway = new FakeGateway;
        WalletPaymentService::useGateway($gateway);

        $this->as($this->owner)->posUnpaid([$this->line(1)])->assertOk();
        $saleId = $this->lastId('sales');
        $walletId = $this->postJson('/api/wallet/start', ['channel' => 'GCASH', 'amount' => 1000,
            'warehouse_id' => $this->main])->assertOk()->json('payment.id');
        $gateway->markPaid(DB::table('wallet_payments')->where('id', $walletId)->value('reference_id'));
        $this->getJson("/api/wallet/$walletId/status")->assertOk()->assertJsonPath('payment.status', 'paid');

        $this->postJson("/api/wallet/$walletId/attach", ['sale_id' => $saleId]);

        $sale = DB::table('sales')->where('id', $saleId)->first();
        $this->assertEquals(265, $this->money($sale->GrandTotal), 'fixture: one bag');
        $this->assertLessThanOrEqual(265, $this->money($sale->paid_amount),
            "K4: a wallet payment left a 265.00 sale with {$sale->paid_amount} paid");
    }

    /**
     * K4 (B20) — editing a fully paid 530.00 sale down to one bag drops the total below what was paid; the edit must be refused.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_sale_edit_cannot_drop_the_total_below_what_was_paid()
    {
        $this->as($this->owner)->pos([$this->line(2)])->assertOk();
        $saleId = $this->lastId('sales');

        $response = $this->putJson("/api/sales/$saleId", $this->saleEditPayload($saleId, [1]));

        $sale = DB::table('sales')->where('id', $saleId)->first();
        $this->assertEquals(530, $this->money($sale->paid_amount), 'fixture: paid 530 in full');
        $this->assertGreaterThanOrEqual($this->money($sale->paid_amount), $this->money($sale->GrandTotal),
            "K4: the edit left a sale totalling {$sale->GrandTotal} with {$sale->paid_amount} paid (HTTP {$response->status()})");
        $response->assertStatus(422);
    }
}
