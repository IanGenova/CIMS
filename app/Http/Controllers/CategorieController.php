<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\utils\helpers;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategorieController extends BaseController
{

    //-------------- Get All Categories ---------------\\

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Category::class);
        // How many items do you want to display.
        $perPage = \App\utils\ListLimit::of($request->limit);
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $helpers = new helpers();

        $categories = Category::where('deleted_at', '=', null)

        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('name', 'LIKE', "%{$request->search}%")
                        ->orWhere('code', 'LIKE', "%{$request->search}%");
                });
            });
        $totalRows = $categories->count();
        $categories = $categories->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        return response()->json([
            'categories' => $categories,
            'totalRows' => $totalRows,
        ]);
    }

    //-------------- Store New Category ---------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Category::class);

        request()->validate([
            'name' => 'required',
            // A category code is what an import matches on and what a person
            // reads on a report. Two categories answering to one code makes
            // either of those a coin toss. Deleted rows are excluded, so a
            // code freed by a deletion can be used again.
            'code' => ['required', Rule::unique('categories', 'code')->whereNull('deleted_at')],
        ]);

        Category::create([
            'code' => $request['code'],
            'name' => $request['name'],
        ]);
        return response()->json(['success' => true]);
    }

     //------------ function show -----------\\

    public function show($id){
        //
    
    }

    //-------------- Update Category ---------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Category::class);

        request()->validate([
            'name' => 'required',
            // Unique among the others, so renaming a category to the code it
            // already holds is not treated as a clash with itself.
            'code' => ['required', Rule::unique('categories', 'code')
                ->ignore($id)->whereNull('deleted_at')],
        ]);

        Category::whereId($id)->update([
            'code' => $request['code'],
            'name' => $request['name'],
        ]);
        return response()->json(['success' => true]);

    }

    //-------------- Remove Category ---------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Category::class);

        if ($reason = \App\utils\DeleteGuard::category($id)) {
            return response()->json(['message' => $reason], 422);
        }

        Category::whereId($id)->update([
            'deleted_at' => Carbon::now(),
        ]);
        return response()->json(['success' => true]);
    }

    //-------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Category::class);
        $selectedIds = $request->selectedIds;

        if ($reason = \App\utils\DeleteGuard::firstReason('category', (array) $selectedIds)) {
            return response()->json(['message' => $reason], 422);
        }

        foreach ($selectedIds as $category_id) {
            Category::whereId($category_id)->update([
                'deleted_at' => Carbon::now(),
            ]);
        }

        return response()->json(['success' => true]);
    }

}
