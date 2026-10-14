<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W3Helpers;

/**
 * K57 — The Send_SMS endpoints (nine of them) have no permission check, while
 * their email twins do (map A1). The 500 that follows is only Twilio refusing
 * blank credentials: the request got past every gate.
 */
class K57SendSmsPermissionTest extends DatatestCase
{
    use W3Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->emulatePrepares();
        // Never let a test reach a real SMS gateway.
        foreach (['TWILIO_SID', 'TWILIO_TOKEN', 'TWILIO_FROM'] as $key) {
            putenv("$key=");
            $_ENV[$key] = '';
            $_SERVER[$key] = '';
        }
    }

    /**
     * K57 — A user with no permissions reaches the SMS sender for a sale.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_user_without_permission_cannot_text_a_sale()
    {
        $this->actAs($this->owner);
        $sale = $this->unpaidSale();

        $response = $this->actAs($this->nobody)->postJson('/api/sales/send/sms', ['id' => $sale]);

        $this->assertForbiddenStatus($response, 'A1: texting a sale needs the same permission as emailing it');
    }

    /**
     * K57 — A user with no permissions reaches the SMS sender for a purchase.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_user_without_permission_cannot_text_a_purchase()
    {
        $this->actAs($this->owner)->purchase([$this->buyLine(10)])->assertOk();
        $purchase = $this->lastId('purchases');

        $response = $this->actAs($this->nobody)->postJson('/api/purchases/send/sms', ['id' => $purchase]);

        $this->assertForbiddenStatus($response, 'A1: texting a purchase needs the same permission as emailing it');
    }

    /**
     * K57 — The remaining SMS endpoints (quotation, returns, payments) do not check permission either.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_user_without_permission_cannot_reach_any_other_sms_endpoint()
    {
        $this->actAs($this->owner);
        $pay = ['date' => $this->today(), 'montant' => 10, 'Reglement' => 'Cash', 'change' => 0];
        $sale = $this->unpaidSale();
        $this->postJson('/api/payment/sale', $pay + ['sale_id' => $sale])->assertOk();
        $this->quotation([$this->line(1)])->assertOk();
        $this->saleReturn([$this->line(1)])->assertOk();
        $saleReturn = $this->lastId('sale_returns');
        $this->postJson('/api/payment/returns_sale', $pay + ['sale_return_id' => $saleReturn])->assertOk();
        $this->purchaseReturn([$this->buyLine(1)])->assertOk();
        $purchaseReturn = $this->lastId('purchase_returns');
        $this->postJson('/api/payment/returns_purchase', $pay + ['purchase_return_id' => $purchaseReturn])->assertOk();
        $this->purchase([$this->buyLine(1)], ['statut' => 'received'])->assertOk();
        $this->postJson('/api/payment/purchase', $pay + ['purchase_id' => $this->lastId('purchases')])->assertOk();
        $ids = ['quotations' => $this->lastId('quotations'), 'returns/sale' => $saleReturn, 'returns/purchase' => $purchaseReturn,
            'payment/sale' => $this->lastId('payment_sales'), 'payment/purchase' => $this->lastId('payment_purchases'),
            'payment/returns_sale' => $this->lastId('payment_sale_returns'),
            'payment/returns_purchase' => $this->lastId('payment_purchase_returns')];
        foreach ($ids as $path => $id) {
            $this->assertNotNull($id, "fixture: a record to text exists for $path");
        }

        $this->actAs($this->nobody);
        $let = [];
        foreach ($ids as $path => $id) {
            $status = $this->postJson("/api/{$path}/send/sms", ['id' => $id])->getStatusCode();
            if ($status !== 403) {
                $let[] = "$path ($status)";
            }
        }

        $this->assertSame([], $let, 'A1: every SMS endpoint must refuse a user without permission with 403');
    }
}
