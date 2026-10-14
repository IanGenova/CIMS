<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Double-entry accounting: a chart of accounts and a journal.
 *
 * There was no chart of accounts, no journal and no export, so nothing that
 * happened in the system could be handed to an accountant.
 *
 * Entries are append-only. A mistake is corrected by posting a reversal, not
 * by editing history -- the same principle the batch ledger uses.
 */
class CreateAccountingTables extends Migration
{
    public function up()
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('code', 32);
            $table->string('name', 192);
            // asset | liability | equity | income | expense
            $table->string('type', 32);
            $table->unsignedInteger('parent_id')->nullable();
            // Which side increases this account: debit or credit.
            $table->string('normal_balance', 8)->default('debit');
            // Accounts the auto-posting rules look up by slug.
            $table->string('slug', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique('code');
            $table->unique('slug');
            $table->index('type');
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->increments('id');
            $table->string('Ref', 192);
            $table->date('date');
            $table->string('memo', 255)->nullable();
            // What produced this entry: Sale, Purchase, Expense, Payment, Manual
            $table->string('source_type', 64)->nullable();
            $table->integer('source_id')->nullable();
            // Set when this entry reverses another one.
            $table->unsignedInteger('reverses_entry_id')->nullable();
            $table->integer('user_id')->nullable();
            $table->timestamps();

            $table->unique('Ref');
            $table->index(['source_type', 'source_id']);
            $table->index('date');
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('journal_entry_id');
            $table->unsignedInteger('account_id');
            $table->decimal('debit', 20, 4)->default(0);
            $table->decimal('credit', 20, 4)->default(0);
            $table->string('memo', 255)->nullable();
            $table->timestamps();

            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->onDelete('cascade');
            $table->foreign('account_id')->references('id')->on('accounts');
            $table->index(['account_id', 'journal_entry_id']);
        });

        // A minimal chart, enough for the postings this system can produce.
        $now = now();
        $rows = [
            ['1000', 'Cash and Bank',        'asset',     'debit',  'cash'],
            ['1100', 'Accounts Receivable',  'asset',     'debit',  'accounts_receivable'],
            ['1200', 'Inventory',            'asset',     'debit',  'inventory'],
            ['2000', 'Accounts Payable',     'liability', 'credit', 'accounts_payable'],
            ['2100', 'Tax Payable',          'liability', 'credit', 'tax_payable'],
            ['3000', 'Owner Equity',         'equity',    'credit', 'equity'],
            ['4000', 'Sales Revenue',        'income',    'credit', 'sales_revenue'],
            ['4100', 'Sales Returns',        'income',    'debit',  'sales_returns'],
            ['5000', 'Cost of Goods Sold',   'expense',   'debit',  'cogs'],
            ['6000', 'Operating Expenses',   'expense',   'debit',  'operating_expenses'],
            ['7000', 'Shipping Income',      'income',    'credit', 'shipping_income'],
            ['8000', 'Discounts Given',      'expense',   'debit',  'discounts_given'],
        ];

        foreach ($rows as $r) {
            DB::table('accounts')->insert([
                'code' => $r[0], 'name' => $r[1], 'type' => $r[2],
                'normal_balance' => $r[3], 'slug' => $r[4],
                'is_active' => true, 'is_system' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down()
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounts');
    }
}
