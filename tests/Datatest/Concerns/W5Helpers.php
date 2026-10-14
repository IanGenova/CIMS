<?php

namespace Tests\Datatest\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Fixtures for the W5 data-test regressions (.flow/DATATEST-2026-10-06.md):
 * the UI clusters K84-K96 (documents, security, reports, numbering, product
 * form). Builds on W3Helpers (request bodies, actAs, assertRefused) and adds
 * a reset of the password-policy cache per request and one document of each
 * kind for the PDF endpoints.
 */
trait W5Helpers
{
    use W3Helpers;

    /**
     * DatatestCase::call() resets the static caches before every request; the
     * password policy cache is reset here too, as a fresh PHP process would.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        \App\utils\PasswordPolicy::forget();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /**
     * One of every document the PDF buttons print, all at Main, made by the
     * owner through the API; payments are inserted as rows (the payment screens
     * are not under test here). Returns ids keyed by kind.
     */
    protected function documents()
    {
        // The PDF templates print the shop logo; the shipped default (read only).
        DB::table('settings')->update(['logo' => 'logo-default.png']);
        $this->actAs($this->owner);
        $ids = [];

        $this->pos([$this->line(2)])->assertOk();
        $ids['sale'] = $this->lastId('sales');

        $this->purchase([$this->buyLine(2)])->assertOk();
        $ids['purchase'] = $this->lastId('purchases');

        $this->quotation([$this->line(1)])->assertOk();
        $ids['quotation'] = $this->lastId('quotations');

        $this->saleReturn([$this->line(1)])->assertOk();
        $ids['sale_return'] = $this->lastId('sale_returns');

        $this->purchaseReturn([$this->buyLine(1)])->assertOk();
        $ids['purchase_return'] = $this->lastId('purchase_returns');

        $ids['payment_sale'] = DB::table('payment_sales')->where('sale_id', $ids['sale'])->value('id')
            ?: DB::table('payment_sales')->insertGetId(['user_id' => $this->owner->id, 'date' => $this->today(),
                'Ref' => 'INV/SL_DT5', 'sale_id' => $ids['sale'], 'montant' => 530, 'Reglement' => 'Cash']);
        $ids['payment_purchase'] = DB::table('payment_purchases')->insertGetId(['user_id' => $this->owner->id,
            'date' => $this->today(), 'Ref' => 'INV/PR_DT5', 'purchase_id' => $ids['purchase'], 'montant' => 460,
            'Reglement' => 'Cash']);
        $ids['payment_sale_return'] = DB::table('payment_sale_returns')->insertGetId(['user_id' => $this->owner->id,
            'date' => $this->today(), 'Ref' => 'INV/RT_DT5', 'sale_return_id' => $ids['sale_return'], 'montant' => 265,
            'Reglement' => 'Cash']);
        $ids['payment_purchase_return'] = DB::table('payment_purchase_returns')->insertGetId(['user_id' => $this->owner->id,
            'date' => $this->today(), 'Ref' => 'INV/RT_DT5P', 'purchase_return_id' => $ids['purchase_return'],
            'montant' => 230, 'Reglement' => 'Cash']);

        return $ids;
    }

    /** The URL behind each document's PDF/print button. */
    protected function documentUrl($kind, array $ids)
    {
        $urls = [
            'sale' => '/api/Sale_PDF/%d',
            'sale_print' => '/api/Sales/Print_Invoice/%d',
            'purchase' => '/api/Purchase_PDF/%d',
            'quotation' => '/api/Quote_PDF/%d',
            'sale_return' => '/api/Return_sale_PDF/%d',
            'purchase_return' => '/api/Return_Purchase_PDF/%d',
            'payment_sale' => '/api/payment_Sale_PDF/%d',
            'payment_purchase' => '/api/Payment_Purchase_PDF/%d',
            'payment_sale_return' => '/api/payment_Return_sale_PDF/%d',
            'payment_purchase_return' => '/api/payment_Return_Purchase_PDF/%d',
        ];
        $idKey = $kind === 'sale_print' ? 'sale' : $kind;

        return sprintf($urls[$kind], $ids[$idKey]);
    }

    /** First 300 characters of a response, for failure messages. */
    protected function peek($response)
    {
        $body = (string) $response->getContent();
        if (strncmp($body, '%PDF', 4) === 0) {
            return '%PDF document, ' . strlen($body) . ' bytes';
        }

        return substr($body, 0, 300);
    }
}
