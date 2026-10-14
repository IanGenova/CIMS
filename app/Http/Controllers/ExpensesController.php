<?php

namespace App\Http\Controllers;

use App\Exports\ExpenseExport;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Role;
use App\Models\Warehouse;
use App\utils\helpers;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;
use App\utils\DocumentSequence;

class ExpensesController extends BaseController
{

    //-------------- Show All  Expenses -----------\\

    public function index(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Expense::class);

        // How many items do you want to display.
        $perPage = \App\utils\ListLimit::of($request->limit);
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $helpers = new helpers();
        $role = Auth::user()->roles()->first();
        $view_records = Role::findOrFail($role->id)->inRole('record_view');
        // Filter fields With Params to retrieve
        $columns = array(0 => 'Ref', 1 => 'warehouse_id', 2 => 'date', 3 => 'expense_category_id');
        $param = array(0 => 'like', 1 => '=', 2 => '=', 3 => '=');
        $data = array();

        // Check If User Has Permission View  All Records
        $Expenses = Expense::with('expense_category', 'warehouse')
            ->where('deleted_at', '=', null)
            ->where(function ($query) use ($view_records) {
                if (!$view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            });

        //Multiple Filter
        $Filtred = $helpers->filter($Expenses, $columns, $param, $request)
        //Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere('date', 'LIKE', "%{$request->search}%")
                        ->orWhere('details', 'LIKE', "%{$request->search}%")
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('expense_category', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('warehouse', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        });
                });
            });
        $totalRows = $Filtred->count();
        $Expenses = $Filtred->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        foreach ($Expenses as $Expense) {

            $item['id'] = $Expense->id;
            $item['date'] = $Expense->date;
            $item['Ref'] = $Expense->Ref;
            $item['details'] = $Expense->details;
            $item['amount'] = $Expense->amount;
            $item['warehouse_name'] = $Expense['warehouse']->name;
            $item['category_name'] = $Expense['expense_category']->name;
            $data[] = $item;
        }

        $Expenses_category = ExpenseCategory::where('deleted_at', '=', null)->get(['id', 'name']);
        $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);

        return response()->json([
            'expenses' => $data,
            'Expenses_category' => $Expenses_category,
            'warehouses' => $warehouses,
            'totalRows' => $totalRows,
        ]);

    }

    //-------------- Store New Expense -----------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Expense::class);
        // 30 days back at most, never ahead (DATATEST K55/K37).
        \App\utils\BusinessDate::check($request['expense']['date'] ?? null, 'expense.date');

        request()->validate([
            'expense.date' => 'required',
            'expense.warehouse_id' => 'required',
            'expense.category_id' => 'required',
            'expense.details' => 'required|string|max:192',
            // Money out has to be a positive number. A negative expense is
            // income filed in the wrong place: it nets off the category it
            // sits in, so the account looks smaller rather than wrong.
            'expense.amount' => 'required|numeric|gt:0|max:9999999999999999',   // what decimal(20,4) holds (K37)
        ]);

        Expense::create([
            'user_id' => Auth::user()->id,
            'date' => $request['expense']['date'],
            'Ref' => $this->getNumberOrder(),
            'warehouse_id' => $request['expense']['warehouse_id'],
            'expense_category_id' => $request['expense']['category_id'],
            'details' => $request['expense']['details'],
            'amount' => $request['expense']['amount'],
        ]);

        return response()->json(['success' => true]);
    }

    //------------ function show -----------\\

    public function show($id){
        //
        
        }

    //-------------- Update  Expense -----------\\

    public function update(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'update', Expense::class);
        // 30 days back at most, never ahead (DATATEST K55/K37).
        \App\utils\BusinessDate::checkIfChanged('expenses', $id, $request['expense']['date'] ?? null, 'expense.date');
        $role = Auth::user()->roles()->first();
        $view_records = Role::findOrFail($role->id)->inRole('record_view');
        $expense = Expense::findOrFail($id);

        // Check If User Has Permission view All Records
        if (!$view_records) {
            // Check If User->id === expense->id
            $this->authorizeForUser($request->user('api'), 'check_record', $expense);
        }

        request()->validate([
            'expense.date' => 'required',
            'expense.warehouse_id' => 'required',
            'expense.category_id' => 'required',
            'expense.details' => 'required|string|max:192',
            // Money out has to be a positive number. A negative expense is
            // income filed in the wrong place: it nets off the category it
            // sits in, so the account looks smaller rather than wrong.
            'expense.amount' => 'required|numeric|gt:0|max:9999999999999999',   // what decimal(20,4) holds (K37)
        ]);

        $expense->update([
            'date' => $request['expense']['date'],
            'warehouse_id' => $request['expense']['warehouse_id'],
            'expense_category_id' => $request['expense']['category_id'],
            'details' => $request['expense']['details'],
            'amount' => $request['expense']['amount'],
        ]);

        return response()->json(['success' => true]);
    }

    //-------------- Delete Expense -----------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Expense::class);
        $role = Auth::user()->roles()->first();
        $view_records = Role::findOrFail($role->id)->inRole('record_view');
        $expense = Expense::findOrFail($id);

        // Check If User Has Permission view All Records
        if (!$view_records) {
            // Check If User->id === expense->id
            $this->authorizeForUser($request->user('api'), 'check_record', $expense);
        }

        $expense->update([
            'deleted_at' => Carbon::now(),
        ]);

        return response()->json(['success' => true]);
    }

    //-------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Expense::class);
        $selectedIds = $request->selectedIds;
        $role = Auth::user()->roles()->first();
        $view_records = Role::findOrFail($role->id)->inRole('record_view');

        foreach ($selectedIds as $expense_id) {
            $expense = Expense::findOrFail($expense_id);

            // Check If User Has Permission view All Records
            if (!$view_records) {
                // Check If User->id === expense->id
                $this->authorizeForUser($request->user('api'), 'check_record', $expense);
            }
            $expense->update([
                'deleted_at' => Carbon::now(),
            ]);
        }
        return response()->json(['success' => true]);
    }

    //--------------- Reference Number of Expense ----------------\\

    public function getNumberOrder()
    {
        // Was: read the last row, split the reference, add one -- which handed
        // two simultaneous callers the same number. DocumentSequence claims it
        // atomically, and a unique index on Ref refuses a duplicate outright.
        return DocumentSequence::next('expenses', 'EXP_', 0, 1111);
    }

    //-------------- Export All Expenses to EXCEL  ---------------\\

    public function exportExcel(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Expense::class);

        return Excel::download(new ExpenseExport, 'List_Expense.xlsx');
    }

    //---------------- Show Form Create Expense ---------------\\

    public function create(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'create', Expense::class);

        $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        $Expenses_category = ExpenseCategory::where('deleted_at', '=', null)->get(['id', 'name']);

        return response()->json([
            'Expenses_category' => $Expenses_category,
            'warehouses' => $warehouses,
        ]);
    }

    //------------- Show Form Edit Expense -----------\\

    public function edit(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'update', Expense::class);
        $role = Auth::user()->roles()->first();
        $view_records = Role::findOrFail($role->id)->inRole('record_view');
        $Expense = Expense::where('deleted_at', '=', null)->findOrFail($id);

        // Check If User Has Permission view All Records
        if (!$view_records) {
            // Check If User->id === Expense->id
            $this->authorizeForUser($request->user('api'), 'check_record', $Expense);
        }

        if ($Expense->warehouse_id) {
            if (Warehouse::where('id', $Expense->warehouse_id)
                ->where('deleted_at', '=', null)
                ->first()) {
                $data['warehouse_id'] = $Expense->warehouse_id;
            } else {
                $data['warehouse_id'] = '';
            }
        } else {
            $data['warehouse_id'] = '';
        }

        if ($Expense->expense_category_id) {
            if (ExpenseCategory::where('id', $Expense->expense_category_id)
                ->where('deleted_at', '=', null)
                ->first()) {
                $data['category_id'] = $Expense->expense_category_id;
            } else {
                $data['category_id'] = '';
            }
        } else {
            $data['category_id'] = '';
        }

        $data['date'] = $Expense->date;
        $data['amount'] = $Expense->amount;
        $data['details'] = $Expense->details;

        $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        $Expenses_category = ExpenseCategory::where('deleted_at', '=', null)->get(['id', 'name']);

        return response()->json([
            'expense' => $data,
            'expense_Category' => $Expenses_category,
            'warehouses' => $warehouses,
        ]);
    }

}
