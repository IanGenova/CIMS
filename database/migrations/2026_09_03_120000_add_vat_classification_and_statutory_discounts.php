<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Philippine VAT classification and the statutory Senior Citizen / PWD discount.
 *
 * The existing tax engine handles rates. What it cannot express is the
 * *classification* -- vatable, VAT-exempt, or zero-rated -- and BIR reporting is
 * built entirely on that distinction: a 0% rate and a VAT-exempt sale are the
 * same number and different filings.
 *
 * Everything here is additive and defaults to the behaviour the system already
 * had: every product starts vatable, the statutory discount starts at "none",
 * and a deployment that never touches these columns computes exactly what it
 * computed before.
 */
class AddVatClassificationAndStatutoryDiscounts extends Migration
{
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            // vatable   -- the ordinary case, 12% output VAT
            // vat_exempt -- e.g. prescription medicines, agricultural produce
            // zero_rated -- e.g. export sales, sales to PEZA-registered buyers
            $table->string('vat_class', 16)->default('vatable')->after('tax_group_id');
            // Alcohol and tobacco are excluded from the statutory discount even
            // when a senior buys them, so eligibility is per product.
            $table->boolean('sc_pwd_eligible')->default(true)->after('vat_class');
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->decimal('vat_rate', 8, 4)->default(12)->after('default_language');
            // Philippine retail prices are quoted VAT-inclusive by convention,
            // and the statutory computation depends on knowing which it is.
            $table->boolean('prices_include_vat')->default(true)->after('vat_rate');
            $table->string('tin', 32)->nullable()->after('prices_include_vat');
        });

        // A buyer's TIN is required on the invoice for a B2B sale.
        Schema::table('clients', function (Blueprint $table) {
            $table->string('tin', 32)->nullable()->after('email');
        });

        foreach (['sales', 'quotations'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                // The four figures every BIR invoice must summarise.
                $table->decimal('vatable_sales', 20, 4)->default(0)->after('GrandTotal');
                $table->decimal('vat_amount', 20, 4)->default(0)->after('vatable_sales');
                $table->decimal('vat_exempt_sales', 20, 4)->default(0)->after('vat_amount');
                $table->decimal('zero_rated_sales', 20, 4)->default(0)->after('vat_exempt_sales');

                // The statutory discount, kept apart from the ordinary trade
                // discount because it is a different thing on the invoice and a
                // different line in the books.
                $table->decimal('statutory_discount', 20, 4)->default(0)->after('zero_rated_sales');
                $table->string('statutory_type', 16)->default('none')->after('statutory_discount');
                $table->string('statutory_id_number', 64)->nullable()->after('statutory_type');
                $table->string('statutory_holder_name', 192)->nullable()->after('statutory_id_number');
                // A shared restaurant bill is apportioned by head: the discount
                // applies to the beneficiaries' share of the table, not the lot.
                $table->unsignedInteger('statutory_beneficiaries')->default(0)->after('statutory_holder_name');
                $table->unsignedInteger('statutory_total_persons')->default(1)->after('statutory_beneficiaries');
            });
        }

        foreach (['sale_details', 'quotation_details'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                // Snapshotted, like the price: reclassifying a product later must
                // not restate an invoice that has already been issued.
                $table->string('vat_class', 16)->default('vatable')->after('product_id');
                $table->boolean('sc_pwd_eligible')->default(true)->after('vat_class');
                $table->decimal('vat_amount', 20, 4)->default(0)->after('sc_pwd_eligible');
                $table->decimal('statutory_discount', 20, 4)->default(0)->after('vat_amount');
            });
        }

        // Existing settings rows predate the columns; give them the defaults.
        DB::table('settings')->whereNull('vat_rate')->update(['vat_rate' => 12]);
    }

    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['vat_class', 'sc_pwd_eligible']);
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['vat_rate', 'prices_include_vat', 'tin']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('tin');
        });

        foreach (['sales', 'quotations'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropColumn(['vatable_sales', 'vat_amount', 'vat_exempt_sales',
                    'zero_rated_sales', 'statutory_discount', 'statutory_type',
                    'statutory_id_number', 'statutory_holder_name',
                    'statutory_beneficiaries', 'statutory_total_persons']);
            });
        }

        foreach (['sale_details', 'quotation_details'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropColumn(['vat_class', 'sc_pwd_eligible', 'vat_amount', 'statutory_discount']);
            });
        }
    }
}
