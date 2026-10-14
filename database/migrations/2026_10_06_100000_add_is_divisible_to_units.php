<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Units that may be sold in fractions (data test K53, .flow/DATATEST-2026-10-06.md).
 *
 * Half a bag of cement or a quarter of a sheet was accepted everywhere. Only
 * measured units (kilogram, metre, litre, cubic metre…) may be split; every
 * existing unit that measures is marked so, and the owner can change it on the
 * Units screen.
 */
class AddIsDivisibleToUnits extends Migration
{
    public function up()
    {
        Schema::table('units', function (Blueprint $table) {
            $table->boolean('is_divisible')->default(false)->after('operator_value');
        });

        DB::table('units')->where(function ($q) {
            foreach (['kg', 'g', 'mg', 'm', 'cm', 'mm', 'l', 'ml', 'cu.m', 'ft', 'yd', 'lb', 'oz'] as $short) {
                $q->orWhereRaw('LOWER(TRIM(ShortName)) = ?', [$short]);
            }
            foreach (['%kilo%', '%gram%', '%meter%', '%metre%', '%liter%', '%litre%'] as $name) {
                $q->orWhereRaw('LOWER(name) LIKE ?', [$name]);
            }
        })->update(['is_divisible' => true]);
    }

    public function down()
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('is_divisible');
        });
    }
}
