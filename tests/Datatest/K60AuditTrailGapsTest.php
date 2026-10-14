<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W3Helpers;

/**
 * K60 — The audit trail misses voids, payment deletions, master-data deletes,
 * rate changes and user deactivation, because those writes bypass Eloquent
 * model events (query-builder updates and DB::table writes).
 */
class K60AuditTrailGapsTest extends DatatestCase
{
    use W3Helpers;

    /** Audit rows for one record written after $after. */
    private function audited($type, $id, $after)
    {
        return DB::table('audit_logs')->where('id', '>', $after)->where('auditable_type', 'App\\Models\\' . $type)
            ->where('auditable_id', $id)->get();
    }

    private function mark()
    {
        return (int) DB::table('audit_logs')->max('id');
    }

    /** Fixture: auditing is on in this process (a customer create is logged). */
    private function assertAuditingIsOn()
    {
        $this->actAs($this->owner)->postJson('/api/clients', $this->clientBody())->assertOk();
        $this->assertGreaterThan(0, $this->audited('Client', $this->lastId('clients'), 0)->count(),
            'fixture: creating a customer writes an audit row');
    }

    /**
     * K60 — Deleting a customer writes no audit row.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_deleting_a_customer_is_audited()
    {
        $this->assertAuditingIsOn();
        $after = $this->mark();

        $this->deleteJson("/api/clients/{$this->client}")->assertOk();

        $this->assertGreaterThan(0, $this->audited('Client', $this->client, $after)->count(), 'the customer delete must be in the audit trail');
    }

    /**
     * K60 — Deleting a supplier writes no audit row.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_deleting_a_supplier_is_audited()
    {
        $this->assertAuditingIsOn();
        $after = $this->mark();

        $this->deleteJson("/api/providers/{$this->supplier}")->assertOk();

        $this->assertGreaterThan(0, $this->audited('Provider', $this->supplier, $after)->count(), 'the supplier delete must be in the audit trail');
    }

    /**
     * K60 — Changing a currency's exchange rate writes no audit row.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_changing_an_exchange_rate_is_audited()
    {
        $this->assertAuditingIsOn();
        $eur = $this->currency('EUR', 60);
        $after = $this->mark();

        $this->putJson("/api/currencies/{$eur}", ['code' => 'EUR', 'name' => 'EUR', 'symbol' => 'E', 'exchange_rate' => 65])->assertOk();

        $this->assertEquals(65, (float) DB::table('currencies')->where('id', $eur)->value('exchange_rate'), 'fixture: the rate changed');
        $this->assertGreaterThan(0, $this->audited('Currency', $eur, $after)->count(), 'the rate change must be in the audit trail');
    }

    /**
     * K60 — Deactivating a user writes no audit row.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_deactivating_a_user_is_audited()
    {
        $this->assertAuditingIsOn();
        $after = $this->mark();

        $this->putJson("/api/users/Activated/{$this->cashier->id}", ['statut' => 0])->assertOk();

        $this->assertSame(0, (int) DB::table('users')->where('id', $this->cashier->id)->value('statut'), 'fixture: the cashier is off');
        $this->assertGreaterThan(0, $this->audited('User', $this->cashier->id, $after)->count(), 'the deactivation must be in the audit trail');
    }

    /**
     * K60 — Voiding a sale writes no audit row for the sale.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_voiding_a_sale_is_audited()
    {
        $this->assertAuditingIsOn();
        $this->pos([$this->line(1)])->assertOk();
        $sale = $this->lastId('sales');
        $after = $this->mark();

        $this->postJson("/api/sales/{$sale}/void", ['reason' => 'Customer changed mind'])->assertOk();

        $this->assertSame('voided', DB::table('sales')->where('id', $sale)->value('statut'), 'fixture: the sale is voided');
        $this->assertGreaterThan(0, $this->audited('Sale', $sale, $after)->count(), 'the void must be in the audit trail');
    }

    /**
     * K60 — Deleting a sale payment writes no audit row for the payment.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_deleting_a_payment_is_audited()
    {
        $this->assertAuditingIsOn();
        $sale = $this->unpaidSale();
        $this->postJson('/api/payment/sale', ['sale_id' => $sale, 'date' => $this->today(), 'montant' => 100,
            'Reglement' => 'Cash', 'change' => 0])->assertOk();
        $payment = $this->lastId('payment_sales');
        $after = $this->mark();

        $this->deleteJson("/api/payment/sale/{$payment}")->assertOk();

        $this->assertGreaterThan(0, $this->audited('PaymentSale', $payment, $after)->count(), 'the payment delete must be in the audit trail');
    }
}
