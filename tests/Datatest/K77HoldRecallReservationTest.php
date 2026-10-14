<?php

namespace Tests\Datatest;

use Tests\Datatest\Concerns\W4Helpers;

/**
 * K77 — Recalling a held basket gives its reserved stock back before the sale
 * exists, so another till can take the goods (C19). Not a race: it reproduces
 * one request at a time. Rule S6: a hold keeps its stock aside until the basket
 * is sold, discarded, or released after 12 hours -- a recall is none of these.
 * Red until repaired (.flow/DATATEST-2026-10-06.md).
 */
class K77HoldRecallReservationTest extends DatatestCase
{
    use W4Helpers;

    public function test_recalling_a_held_basket_keeps_its_stock_from_another_till()
    {
        $otherTill = $this->person(['Pos_view', 'Sales_add', 'Sales_view', 'payment_sales_add', 'payment_sales_view'], [$this->main]);
        $this->setShelf(2);

        $this->as($this->owner)->holdBasket(2)->assertOk();
        $this->assertEquals(0, $this->shelf(), 'fixture: the hold sets both bags aside');
        $hold = $this->lastId('pos_holds');

        // Before the recall the hold works: another till cannot sell the bags.
        $this->assertSaleRefused($this->as($otherTill)->pos([$this->line(2)]), 'fixture: held bags cannot be sold elsewhere');

        $this->as($this->owner)->postJson("/api/pos/holds/{$hold}/recall")->assertOk();
        $sales = $this->liveSales();

        $taken = $this->as($otherTill)->pos([$this->line(2)]);

        $this->assertSaleRefused($taken,
            'S6: the recalled basket is still being rung up at the first till; another till must not be able to sell its 2 bags');
        $this->assertSame($sales, $this->liveSales(), 'S6: no other sale took the held goods');
    }

    private function assertSaleRefused($response, $message)
    {
        $status = $response->getStatusCode();
        $this->assertTrue($status >= 400 && $status < 500,
            "$message (got HTTP $status: " . substr($response->getContent(), 0, 200) . ')');
    }
}
