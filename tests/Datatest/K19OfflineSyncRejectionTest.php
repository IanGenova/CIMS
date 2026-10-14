<?php

namespace Tests\Datatest;

use Tests\Datatest\Concerns\W4Helpers;

/**
 * K19 — Offline sync records its idempotency key at the wrong time: after the
 * sale is banked on success, and permanently on any refusal. The two-server
 * race (one key banked twice) is tests/datatest-races/K19-offline-key-two-servers.mjs;
 * the parts below reproduce one request at a time.
 * Red until repaired (.flow/DATATEST-2026-10-06.md).
 */
class K19OfflineSyncRejectionTest extends DatatestCase
{
    use W4Helpers;

    /** R17: a sale refused for a fixable reason (no stock) must bank on a retry once the stock is back. */
    public function test_a_sale_refused_for_low_stock_banks_when_retried_after_restock()
    {
        $key = 'k19-lowstock-1';
        $payload = $this->posPayload([$this->line(1)]);
        $this->setShelf(0);

        $first = $this->as($this->owner)->offlineSync($key, $payload)->assertOk();
        $this->assertSame('rejected', $first->json('results.0.status'), 'fixture: with an empty shelf the sale is refused');
        $sales = $this->liveSales();

        $this->setShelf(50);
        $retry = $this->offlineSync($key, $payload)->assertOk();

        $this->assertSame('accepted', $retry->json('results.0.status'),
            'R17: after a restock the same offline sale must bank; the refusal was stored for good and the retry answered "'
            . $retry->json('results.0.status') . '": ' . $retry->json('results.0.message'));
        $this->assertSame($sales + 1, $this->liveSales(), 'R17: the retried sale must exist');
        $this->assertEquals(49, $this->shelf());
    }

    /** R17: a user who may not sell is refused outright, and no key is burnt on their behalf. */
    public function test_a_sync_without_pos_permission_is_forbidden_and_writes_no_key()
    {
        $key = 'k19-noperm-1';
        $response = $this->as($this->nobody)->offlineSync($key, $this->posPayload([$this->line(1)]));

        $this->assertSame(403, $response->getStatusCode(),
            'R17: a user without POS permission must get 403, not HTTP ' . $response->getStatusCode() . ': '
            . substr($response->getContent(), 0, 200));
        $this->assertCount(0, $this->offlineKeyRows($key),
            'R17: an authorization refusal must not store the key as permanently rejected');
        $this->assertEquals(50, $this->shelf());
    }
}
