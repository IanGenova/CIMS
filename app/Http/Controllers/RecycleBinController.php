<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecycleBinController extends Controller
{
    /**
     * Soft deletes were already used across the schema, but nothing in the
     * interface ever showed a deleted record -- so a mistaken delete was, in
     * practice, permanent. This reads the deleted_at columns that already
     * exist rather than introducing storage of its own.
     *
     * Only tables where restoring is meaningful are listed. Order matters on
     * screen: the things people delete by accident come first.
     */
    private function tables()
    {
        return [
            'products' => ['label' => 'Products', 'title' => 'name', 'subtitle' => 'code', 'permission' => 'products_delete'],
            'clients' => ['label' => 'Customers', 'title' => 'name', 'subtitle' => 'email', 'permission' => 'Customers_delete'],
            'providers' => ['label' => 'Suppliers', 'title' => 'name', 'subtitle' => 'email', 'permission' => 'Suppliers_delete'],
            'sales' => ['label' => 'Sales', 'title' => 'Ref', 'subtitle' => 'date', 'permission' => 'Sales_delete'],
            'purchases' => ['label' => 'Purchases', 'title' => 'Ref', 'subtitle' => 'date', 'permission' => 'purchases_delete'],
            'quotations' => ['label' => 'Quotations', 'title' => 'Ref', 'subtitle' => 'date', 'permission' => 'Quotations_delete'],
            'sale_returns' => ['label' => 'Sale returns', 'title' => 'Ref', 'subtitle' => 'date', 'permission' => 'Sale_Returns_delete'],
            'purchase_returns' => ['label' => 'Purchase returns', 'title' => 'Ref', 'subtitle' => 'date', 'permission' => 'Purchase_Returns_delete'],
            'expenses' => ['label' => 'Expenses', 'title' => 'Ref', 'subtitle' => 'details', 'permission' => 'expense_delete'],
            'adjustments' => ['label' => 'Adjustments', 'title' => 'Ref', 'subtitle' => 'date', 'permission' => 'adjustment_delete'],
            'transfers' => ['label' => 'Transfers', 'title' => 'Ref', 'subtitle' => 'date', 'permission' => 'transfer_delete'],
            'categories' => ['label' => 'Categories', 'title' => 'name', 'subtitle' => 'code', 'permission' => 'category'],
            'brands' => ['label' => 'Brands', 'title' => 'name', 'subtitle' => null, 'permission' => 'brand'],
            'warehouses' => ['label' => 'Warehouses', 'title' => 'name', 'subtitle' => 'city', 'permission' => 'warehouse'],
            'units' => ['label' => 'Units', 'title' => 'name', 'subtitle' => 'ShortName', 'permission' => 'unit'],
            'users' => ['label' => 'Users', 'title' => 'username', 'subtitle' => 'email', 'permission' => 'users_delete'],
        ];
    }

    /** How much is sitting in the bin, per type. */
    public function summary(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', User::class);

        $out = [];
        $total = 0;

        foreach ($this->tables() as $table => $meta) {
            if (! \Schema::hasTable($table) || ! \Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            $count = DB::table($table)->whereNotNull('deleted_at')->count();
            $total += $count;

            $out[] = [
                'table' => $table,
                'label' => $meta['label'],
                'count' => $count,
            ];
        }

        return response()->json(['types' => $out, 'total' => $total]);
    }

    /** The deleted rows of one type. */
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', User::class);

        $table = $request->table;
        $meta = $this->meta($table);

        $rows = DB::table($table)
            ->whereNotNull('deleted_at')
            ->when($request->filled('search'), function ($q) use ($request, $meta) {
                return $q->where($meta['title'], 'LIKE', "%{$request->search}%");
            })
            ->orderByDesc('deleted_at')->limit(200)->get();

        $out = [];
        foreach ($rows as $r) {
            $arr = (array) $r;
            $out[] = [
                'id' => $arr['id'],
                'title' => isset($arr[$meta['title']]) ? $arr[$meta['title']] : ('#' . $arr['id']),
                'subtitle' => $meta['subtitle'] && isset($arr[$meta['subtitle']]) ? $arr[$meta['subtitle']] : null,
                'deleted_at' => $arr['deleted_at'],
            ];
        }

        return response()->json([
            'label' => $meta['label'],
            'rows' => $out,
            'totalRows' => count($out),
        ]);
    }

    public function restore(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', User::class);

        $request->validate([
            'table' => 'required|string',
            'id' => 'required|integer',
        ]);

        $meta = $this->meta($request->table);

        $row = DB::table($request->table)->where('id', $request->id)
            ->whereNotNull('deleted_at')->first();

        if (! $row) {
            throw ValidationException::withMessages([
                'record' => ['That record is not in the recycle bin.'],
            ]);
        }

        // Restoring a child whose parent is still deleted would produce a row
        // that appears in lists but cannot be opened.
        $this->assertParentsPresent($request->table, $row);

        DB::table($request->table)->where('id', $request->id)->update(['deleted_at' => null]);

        $arr = (array) $row;
        DB::table('restore_logs')->insert([
            'model' => $request->table,
            'record_id' => $request->id,
            'label' => isset($arr[$meta['title']]) ? substr((string) $arr[$meta['title']], 0, 255) : null,
            'user_id' => Auth::user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    /** Delete for real. Deliberately separate from restore, and irreversible. */
    public function purge(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', User::class);

        $request->validate([
            'table' => 'required|string',
            'id' => 'required|integer',
        ]);

        $this->meta($request->table);

        DB::table($request->table)->where('id', $request->id)
            ->whereNotNull('deleted_at')->delete();

        return response()->json(['success' => true]);
    }

    public function restoreLog(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', User::class);

        $rows = DB::table('restore_logs as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->orderByDesc('r.id')->limit(100)
            ->select('r.*', 'u.username')->get();

        return response()->json(['log' => $rows]);
    }

    // ---------------------------------------------------------------

    private function meta($table)
    {
        $tables = $this->tables();

        // Only the listed tables, so this cannot be pointed at anything else.
        if (! isset($tables[$table])) {
            throw ValidationException::withMessages([
                'table' => ['That is not a recoverable record type.'],
            ]);
        }

        return $tables[$table];
    }

    /**
     * Refuse a restore whose owning record is still deleted, and say which.
     */
    private function assertParentsPresent($table, $row)
    {
        $parents = [
            'sales' => [['clients', 'client_id', 'customer'], ['warehouses', 'warehouse_id', 'warehouse']],
            'purchases' => [['providers', 'provider_id', 'supplier'], ['warehouses', 'warehouse_id', 'warehouse']],
            'quotations' => [['clients', 'client_id', 'customer'], ['warehouses', 'warehouse_id', 'warehouse']],
            'products' => [['categories', 'category_id', 'category']],
        ];

        if (! isset($parents[$table])) {
            return;
        }

        $arr = (array) $row;
        foreach ($parents[$table] as $p) {
            list($parentTable, $column, $label) = $p;

            if (empty($arr[$column]) || ! \Schema::hasTable($parentTable)) {
                continue;
            }

            $parent = DB::table($parentTable)->where('id', $arr[$column])->first();

            if (! $parent) {
                throw ValidationException::withMessages([
                    'record' => ['Its ' . $label . ' no longer exists, so this cannot be restored.'],
                ]);
            }

            if (\Schema::hasColumn($parentTable, 'deleted_at') && $parent->deleted_at !== null) {
                throw ValidationException::withMessages([
                    'record' => ['Restore its ' . $label . ' first — that is still in the recycle bin.'],
                ]);
            }
        }
    }
}
