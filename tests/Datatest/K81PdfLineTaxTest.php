<?php

namespace Tests\Datatest;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W2Helpers;

/**
 * K81 — the invoice line TAX column shows a figure worked out from the product
 * TaxNet instead of the line's actual vat_amount.
 * Only the PDF renderer is replaced (it records what Sale_PDF hands the view);
 * the tax figures under test are computed by the controller as usual.
 */
class K81PdfLineTaxTest extends DatatestCase
{
    use W2Helpers;

    private $pdf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetCaches();
        $this->pdf = new class {
            public $view;
            public $data = [];

            public function loadView($view, $data = [], $mergeData = [], $encoding = null)
            {
                $this->view = $view;
                $this->data = $data;

                return $this;
            }

            public function setPaper(...$args)
            {
                return $this;
            }

            public function download($name = 'document.pdf')
            {
                return response('%PDF-fake', 200, ['Content-Type' => 'application/pdf']);
            }

            public function stream($name = 'document.pdf')
            {
                return $this->download($name);
            }

            public function output()
            {
                return '%PDF-fake';
            }
        };
        Pdf::swap($this->pdf);
    }

    private function invoiceLines($saleId)
    {
        $this->get("/api/Sale_PDF/$saleId")->assertOk();
        $this->assertSame('pdf.sale_pdf', $this->pdf->view, 'fixture: the invoice view was rendered');

        return $this->pdf->data['details'];
    }

    /**
     * K81 — two vatable bags (line VAT 56.79) print TAX 0.00 because the product's TaxNet is 0.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_the_tax_column_prints_the_lines_vat()
    {
        $this->as($this->owner)->pos([$this->line(2)])->assertOk();
        $saleId = $this->lastId('sales');
        $vat = $this->money(DB::table('sale_details')->where('sale_id', $saleId)->value('vat_amount'));
        $this->assertEquals(56.79, $vat, 'fixture: 530 - 530/1.12');

        $lines = $this->invoiceLines($saleId);

        $this->assertEquals('56.79', $lines[0]['taxe'], "K81: the invoice prints TAX {$lines[0]['taxe']} on a line whose VAT is 56.79");
    }

    /**
     * K81 — a senior (VAT-exempt) line of a 12% product prints TAX 28.39 although its VAT is 0.00.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_vat_exempt_senior_line_prints_no_tax()
    {
        $vatItem = $this->product(['TaxNet' => 12, 'tax_method' => '2']);
        $this->as($this->owner)->posUnpaid([$this->lineOf($vatItem, 1, 265)], null, $this->senior())->assertOk();
        $saleId = $this->lastId('sales');
        $this->assertEquals(0, $this->money(DB::table('sale_details')->where('sale_id', $saleId)->value('vat_amount')), 'fixture');

        $lines = $this->invoiceLines($saleId);

        $this->assertEquals('0.00', $lines[0]['taxe'], "K81: a VAT-exempt senior line prints TAX {$lines[0]['taxe']}");
    }
}
