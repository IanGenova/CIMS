<?php

namespace Tests\Datatest;

use Tests\Datatest\Concerns\W5Helpers;

/**
 * K84 — The PDF/print endpoints behind every document button (Sale_PDF,
 * Sales/Print_Invoice, Purchase_PDF, Quote_PDF, Return_*_PDF, payment_*_PDF)
 * check no permission at all (A1 for reads): a user with no permissions, a
 * stockman with no sales/purchase/return rights and a purchaser with no sales
 * rights all download customer invoices with name, phone, e-mail and address.
 *
 * The warehouse filter itself is applied (falsify-G7: Branch and Main
 * documents give 404 to the other warehouse's user), so only the missing
 * permission check is pinned here. The Branch cashier's 500 on a Main payment
 * receipt is K96.
 */
class K84DocumentPdfPermissionTest extends DatatestCase
{
    use W5Helpers;

    public function allDocuments()
    {
        $kinds = ['sale', 'sale_print', 'purchase', 'quotation', 'sale_return', 'purchase_return', 'payment_sale',
            'payment_purchase', 'payment_sale_return', 'payment_purchase_return'];

        return array_combine($kinds, array_map(function ($k) {
            return [$k];
        }, $kinds));
    }

    public function salesSideDocuments()
    {
        $kinds = ['sale', 'sale_print', 'quotation', 'sale_return', 'payment_sale', 'payment_sale_return'];

        return array_combine($kinds, array_map(function ($k) {
            return [$k];
        }, $kinds));
    }

    private function assertNoDocument($response, $who, $url)
    {
        $status = $response->getStatusCode();
        $this->assertTrue($status >= 400 && $status < 500,
            "A1: $who has no permission to read this document and must be refused at $url "
            . "(got HTTP $status: " . $this->peek($response) . ')');
    }

    /**
     * K84 — A user with no permissions at all downloads every kind of document.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     *
     * @dataProvider allDocuments
     */
    public function test_a_user_with_no_permissions_cannot_download_documents($kind)
    {
        $ids = $this->documents();
        $url = $this->documentUrl($kind, $ids);

        $response = $this->actAs($this->nobody)->get($url, ['Accept' => 'application/json']);

        $this->assertNoDocument($response, 'a user with no permissions', $url);
    }

    /**
     * K84 — A Main stockman (stock rights only: transfers, adjustments, receipts,
     * counts, products) downloads Main sales, purchases, quotations, returns and
     * payment receipts. Red until repaired (.flow/DATATEST-2026-10-06.md).
     *
     * @dataProvider allDocuments
     */
    public function test_a_stockman_cannot_download_documents_outside_his_rights($kind)
    {
        $ids = $this->documents();
        $url = $this->documentUrl($kind, $ids);

        $response = $this->actAs($this->stockman)->get($url, ['Accept' => 'application/json']);

        $this->assertNoDocument($response, 'a Main stockman without the matching view permission', $url);
    }

    /**
     * K84 — A purchaser (purchases and suppliers only) downloads customer
     * invoices, quotations, sale returns and their receipts. Purchase_PDF is
     * legitimate for this role (falsify-G7 correction) and is the fixture check.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     *
     * @dataProvider salesSideDocuments
     */
    public function test_a_purchaser_cannot_download_sales_side_documents($kind)
    {
        $purchaser = $this->person(['Purchases_add', 'Purchases_view', 'Purchases_edit', 'payment_purchases_add',
            'payment_purchases_view', 'Suppliers_view', 'Reports_suppliers']);
        $ids = $this->documents();

        $this->actAs($purchaser)->get($this->documentUrl('purchase', $ids), ['Accept' => 'application/json'])
            ->assertOk();

        $url = $this->documentUrl($kind, $ids);
        $response = $this->actAs($purchaser)->get($url, ['Accept' => 'application/json']);

        $this->assertNoDocument($response, 'a purchaser without any sales permission', $url);
    }
}
