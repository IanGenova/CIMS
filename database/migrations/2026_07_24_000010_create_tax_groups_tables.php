<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-component tax.
 *
 * A product currently carries a single TaxNet percentage. That cannot express
 * a VAT split, a compound levy, or a customer who is exempt. A tax group is a
 * named set of component rates; products point at a group instead of (or as
 * well as) carrying a flat rate, and a client can be marked exempt.
 *
 * products.TaxNet is deliberately left in place. It stays the fallback for
 * every product that has no group, so nothing that works today changes.
 */
class CreateTaxGroupsTables extends Migration
{
    public function up()
    {
        Schema::create('tax_groups', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 192);
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tax_group_rates', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('tax_group_id');
            $table->string('name', 192);              // "VAT", "Withholding"
            $table->decimal('rate', 20, 4)->default(0);
            // A compound component is charged on the running total including
            // the components before it, not on the bare net price.
            $table->boolean('is_compound')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('tax_group_id')->references('id')->on('tax_groups')->onDelete('cascade');
            $table->index(['tax_group_id', 'sort_order']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('tax_group_id')->nullable()->after('TaxNet');
            $table->foreign('tax_group_id')->references('id')->on('tax_groups')->onDelete('set null');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('is_tax_exempt')->default(false)->after('adresse');
            $table->string('tax_number', 100)->nullable()->after('is_tax_exempt');
        });
    }

    public function down()
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['is_tax_exempt', 'tax_number']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['tax_group_id']);
            $table->dropColumn('tax_group_id');
        });
        Schema::dropIfExists('tax_group_rates');
        Schema::dropIfExists('tax_groups');
    }
}
