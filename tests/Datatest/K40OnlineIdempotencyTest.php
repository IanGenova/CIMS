<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W4Helpers;

/**
 * K40 — Online POS and sale creates ignore the client's idempotency key, so a
 * lost reply plus a retry rings the sale twice (R8).
 * Red until repaired (.flow/DATATEST-2026-10-06.md).
 */
class K40OnlineIdempotencyTest extends DatatestCase
{
    use W4Helpers;

    public function test_a_replayed_pos_sale_with_the_same_key_rings_once()
    {
        $this->as($this->owner);
        $first = $this->pos([$this->line(2)], null, ['idempotency_key' => 'k40-pos-1'])->assertOk();
        $replay = $this->pos([$this->line(2)], null, ['idempotency_key' => 'k40-pos-1']);

        $this->assertSame(1, $this->liveSales(),
            'R8: the same POS sale sent twice with one idempotency_key must create one sale; the replay answered HTTP '
            . $replay->getStatusCode() . ' and made sale ' . $replay->json('id') . ' next to ' . $first->json('id'));
        $this->assertSame(1, DB::table('payment_sales')->whereNull('deleted_at')->count(), 'R8: and one payment');
        $this->assertEquals(48, $this->shelf(), 'R8: and take the two bags once');
    }

    public function test_a_replayed_back_office_sale_with_the_same_key_is_created_once()
    {
        $this->as($this->owner);
        $body = $this->storeSaleBody([$this->line(2)], ['idempotency_key' => 'k40-sale-1']);
        $this->postJson('/api/sales', $body)->assertOk();
        $replay = $this->postJson('/api/sales', $body);

        $this->assertSame(1, $this->liveSales(),
            'R8: POST /api/sales sent twice with one idempotency_key must create one sale; the replay answered HTTP '
            . $replay->getStatusCode());
        $this->assertEquals(48, $this->shelf(), 'R8: and take the two bags once');
    }
}
