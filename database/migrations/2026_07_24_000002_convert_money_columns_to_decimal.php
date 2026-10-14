<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money and quantity columns were declared FLOAT(10,0) — binary floating point
 * with ZERO decimal places. MySQL 8.0.17+ silently rewrites that to DOUBLE and
 * decimals survive; MariaDB and MySQL 5.7 honour the (10,0) and round every
 * price, tax, payment and fractional quantity to a whole number.
 *
 * This converts them to DECIMAL, which is exact and behaves identically on
 * every engine:
 *   money / rates -> DECIMAL(20,4)
 *   quantities    -> DECIMAL(20,6)  (matches product_warehouse.qte)
 *
 * Existing values are rounded to the target scale by the engine. Run a backup
 * first if the database already holds production records.
 */
class ConvertMoneyColumnsToDecimal extends Migration
{
    /**
     * Columns that hold a countable quantity rather than an amount of money.
     * "discount", "TaxNet" and "tax_rate" are deliberately NOT here: depending
     * on the row's discount_method / tax_method they hold either a percentage
     * or a currency amount, so they need the money precision.
     */
    private $quantityColumns = [
        'quantity',
        'items',
        'stock_alert',
        'operator_value',
    ];

    /** Tables this migration is allowed to touch. */
    private $tables = [
        'adjustment_details', 'adjustments', 'expenses',
        'payment_purchase_returns', 'payment_purchases',
        'payment_sale_returns', 'payment_sales',
        'products', 'purchase_details', 'purchase_return_details',
        'purchase_returns', 'purchases', 'quotation_details', 'quotations',
        'sale_details', 'sale_return_details', 'sale_returns', 'sales',
        'transfer_details', 'transfers', 'units',
    ];

    public function up()
    {
        $this->convertAll('decimal');
    }

    /**
     * Reverts to DOUBLE rather than the original FLOAT(10,0). Restoring
     * FLOAT(10,0) would re-introduce the rounding defect, and on MariaDB it
     * would destroy the decimals in every existing row.
     */
    public function down()
    {
        $this->convertAll('double');
    }

    private function convertAll($target)
    {
        $database = DB::getDatabaseName();

        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = DB::select(
                "SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_TYPE
                   FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
                    AND DATA_TYPE IN ('float','double','decimal')",
                [$database, $table]
            );

            foreach ($columns as $column) {
                $name = $column->COLUMN_NAME;

                if ($target === 'decimal') {
                    $type = in_array($name, $this->quantityColumns, true)
                        ? 'DECIMAL(20,6)'
                        : 'DECIMAL(20,4)';
                } else {
                    $type = 'DOUBLE';
                }

                // Leave anything already converted by an earlier migration
                // (product_warehouse.qte) and anything already correct alone.
                if (strcasecmp($column->COLUMN_TYPE, $type) === 0) {
                    continue;
                }

                $sql = sprintf(
                    'ALTER TABLE `%s` MODIFY `%s` %s %s%s',
                    $table,
                    $name,
                    $type,
                    $column->IS_NULLABLE === 'YES' ? 'NULL' : 'NOT NULL',
                    $this->defaultClause($column->COLUMN_DEFAULT, $column->IS_NULLABLE)
                );

                DB::statement($sql);
            }
        }
    }

    private function defaultClause($default, $nullable)
    {
        if ($default === null) {
            // A nullable column with no stated default defaults to NULL anyway;
            // a NOT NULL column must not be given one.
            return $nullable === 'YES' ? ' DEFAULT NULL' : '';
        }

        if (! is_numeric($default)) {
            return '';
        }

        return ' DEFAULT ' . $default;
    }
}
