<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W3Helpers;

/**
 * K72 — Customer email loses its rules through a duplicate array key and
 * supplier email is not validated. Decision: customer email is optional
 * (walk-in customers have none); when given it must be valid.
 */
class K72PartnerEmailTest extends DatatestCase
{
    use W3Helpers;

    /**
     * K72 — A customer is saved with email "not-an-email".
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_customer_with_an_invalid_email_is_refused()
    {
        $response = $this->actAs($this->owner)->postJson('/api/clients', $this->clientBody(['email' => 'not-an-email']));

        $this->assertRefused($response, '"not-an-email" is not an email address');
        $this->assertSame(0, DB::table('clients')->where('email', 'not-an-email')->count());
    }

    /**
     * K72 — A customer without an email fails with 500 "Column 'email' cannot be null".
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_walk_in_customer_without_an_email_is_saved()
    {
        $body = $this->clientBody(['name' => 'Walk-in Pedro']);
        unset($body['email']);

        $response = $this->actAs($this->owner)->postJson('/api/clients', $body);

        $this->assertSame(200, $response->getStatusCode(),
            'decision: a customer email is optional (got HTTP ' . $response->getStatusCode() . ': '
            . substr((string) $response->getContent(), 0, 200) . ')');
        $this->assertSame(1, DB::table('clients')->where('name', 'Walk-in Pedro')->count());
    }

    /**
     * K72 — A supplier is saved with email "nope".
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_supplier_with_an_invalid_email_is_refused()
    {
        $response = $this->actAs($this->owner)->postJson('/api/providers', $this->providerBody(['email' => 'nope']));

        $this->assertRefused($response, '"nope" is not an email address');
        $this->assertSame(0, DB::table('providers')->where('email', 'nope')->count());
    }
}
