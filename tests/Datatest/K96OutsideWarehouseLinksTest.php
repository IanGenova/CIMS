<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W5Helpers;

/**
 * K96 — Links to records outside the user's warehouse end in error pages
 * instead of a clean refusal: a Branch-only cashier asking for the receipt of
 * a Main payment gets HTTP 500 ("Attempt to read property \"Ref\" on null",
 * with the server's file path in the body), and a notification for a Main sale
 * reaches a Branch-only user whose click on it ends in a 404 page.
 */
class K96OutsideWarehouseLinksTest extends DatatestCase
{
    use W5Helpers;

    public function paymentReceipts()
    {
        return [
            'payment_sale' => ['payment_sale'],
            'payment_purchase' => ['payment_purchase'],
            'payment_sale_return' => ['payment_sale_return'],
            'payment_purchase_return' => ['payment_purchase_return'],
        ];
    }

    /**
     * K96 — The receipt of a Main payment, asked for by the Branch-only cashier,
     * is a server error that prints the file path, not a 403.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     *
     * @dataProvider paymentReceipts
     */
    public function test_a_main_payment_receipt_is_refused_to_a_branch_cashier_with_403($kind)
    {
        $ids = $this->documents();
        $url = $this->documentUrl($kind, $ids);

        $response = $this->actAs($this->cashier)->get($url, ['Accept' => 'application/json']);

        $body = (string) $response->getContent();
        $this->assertSame(403, $response->getStatusCode(),
            "A2: a Main payment receipt must be refused to the Branch-only cashier with 403 at $url "
            . '(got HTTP ' . $response->getStatusCode() . ': ' . $this->peek($response) . ')');
        $json = json_decode($body, true) ?: [];
        $this->assertFalse(isset($json['file']) || isset($json['trace']),
            'the refusal must not expose the server file path or stack trace');
    }

    /**
     * K96 — A Main sale voided by the owner is announced to a Branch-only manager
     * (record_view, Branch only), and the notification's link opens nothing for
     * them (GET /api/sales/{id} is 404). Either the notice must not reach them or
     * its link must open. Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_branch_user_gets_no_notification_link_he_cannot_open()
    {
        $branchManager = $this->person(['record_view', 'Sales_view', 'Sales_add', 'Pos_view', 'payment_sales_view'],
            [$this->branch]);

        $this->actAs($this->owner);
        $this->pos([$this->line(2)])->assertOk();
        $sale = $this->lastId('sales');
        $this->postJson("/api/sales/{$sale}/void", ['reason' => 'Customer changed his mind'])->assertOk();
        $this->assertTrue(DB::table('app_notifications')->where('link', "/app/sales/detail/{$sale}")->exists(),
            'fixture: voiding the Main sale writes a notification that links to it');

        $notifications = $this->actAs($branchManager)->getJson('/api/notifications')->assertOk()->json('notifications');

        $broken = [];
        foreach ($notifications as $n) {
            if (preg_match('#^/app/sales/detail/(\d+)$#', (string) ($n['link'] ?? ''), $m)) {
                $status = $this->actAs($branchManager)->getJson("/api/sales/{$m[1]}")->getStatusCode();
                if ($status !== 200) {
                    $broken[] = "\"{$n['title']}\" -> {$n['link']} (GET /api/sales/{$m[1]} is HTTP $status)";
                }
            }
        }

        $this->assertSame([], $broken,
            'A2: a Branch-only user must not be sent notifications whose link he cannot open');
    }
}
