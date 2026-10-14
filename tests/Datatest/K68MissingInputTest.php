<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W3Helpers;

/**
 * K68 — Missing or invalid input reaches the database and returns a 500 with
 * SQL text instead of a 422.
 */
class K68MissingInputTest extends DatatestCase
{
    use W3Helpers;

    private function assert422($response, $message)
    {
        $this->assertSame(422, $response->getStatusCode(),
            "$message (got HTTP {$response->getStatusCode()}: " . substr((string) $response->getContent(), 0, 200) . ')');
    }

    /**
     * K68 — A paid POS sale without the "change" field fails with 500 "Column 'change' cannot be null".
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_pos_sale_without_change_is_validated_not_crashed()
    {
        $body = ['client_id' => $this->client, 'warehouse_id' => $this->main, 'GrandTotal' => 265, 'amount' => 265,
            'received_amount' => 265, 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
            'payment' => ['Reglement' => 'Cash'], 'details' => [$this->line(1)]];

        $response = $this->actAs($this->owner)->postJson('/api/pos/CreatePOS', $body);

        if ($response->getStatusCode() === 200) {
            $this->assertEquals(49, $this->shelf($this->main), 'accepted with change defaulted to 0: the sale is whole');
            $this->assertSame(1, DB::table('payment_sales')->count());

            return;
        }
        $this->assert422($response, 'a missing change is a validation answer or a default of 0, never a 500');
    }

    /**
     * K68 — A purchase from supplier 99999 fails with a 500 foreign-key error.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_purchase_from_an_unknown_supplier_is_a_422()
    {
        $response = $this->actAs($this->owner)->purchase([$this->buyLine(10)], ['supplier_id' => 99999]);

        $this->assert422($response, 'supplier 99999 does not exist');
        $this->assertSame(0, DB::table('purchases')->count());
    }

    /**
     * K68 — Creating a user without a role fails with 500 "Column 'role_id' cannot be null".
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_user_without_a_role_is_a_422()
    {
        $response = $this->actAs($this->owner)->postJson('/api/users', ['firstname' => 'Ana', 'lastname' => 'Reyes',
            'username' => 'ana.reyes', 'email' => 'ana.reyes@example.invalid', 'password' => 'Str0ng-Enough!2026', 'phone' => '0917']);

        $this->assert422($response, 'a user needs a role');
        $this->assertSame(0, DB::table('users')->where('email', 'ana.reyes@example.invalid')->count());
    }
}
