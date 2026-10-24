<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\utils\helpers;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PermissionsController extends BaseController
{
    /** The role editor lists live permissions so new workflows are assignable. */
    public function catalog(Request $request)
    {
        abort_unless($request->user('api')->can('create', Role::class)
            || $request->user('api')->can('update', Role::class), 403);

        return response()->json(['permissions' => Permission::orderBy('name')
            ->get(['name', 'label', 'description'])]);
    }

    //----------- GET ALL Roles --------------\\

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Role::class);
        // How many items do you want to display.
        $perPage = \App\utils\ListLimit::of($request->limit);
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $helpers = new helpers();

        $roles = Role::where('deleted_at', '=', null)
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('name', 'LIKE', "%{$request->search}%")
                        ->orWhere('description', 'LIKE', "%{$request->search}%");
                });
            });
        $totalRows = $roles->count();
        $roles = $roles->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        return response()->json([
            'roles' => $roles,
            'totalRows' => $totalRows,
        ]);
    }

    //----------- Store new Role --------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Role::class);
        $data = $this->validatedRole($request);
        \DB::transaction(function () use ($data) {
            $role = Role::create([
                'name' => trim($data['role']['name']),
                'label' => trim($data['role']['name']),
                'status' => 0,
                'description' => $data['role']['description'] ?? null,
            ]);
            $role->permissions()->sync(Permission::whereIn('name', $data['permissions'])
                ->pluck('id')->all());
        }, 10);
        return response()->json(['success' => true]);
    }

    //------------ function show -----------\\

    public function show($id){
        //
        
        }

    //----------- Update Role --------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Role::class);
        $role = Role::whereNull('deleted_at')->findOrFail($id);
        $data = $this->validatedRole($request, $role->id);
        \DB::transaction(function () use ($role, $data) {
            $role->name = trim($data['role']['name']);
            $role->label = $role->name;
            $role->description = $data['role']['description'] ?? null;
            $role->save();
            $role->permissions()->sync(Permission::whereIn('name', $data['permissions'])
                ->pluck('id')->all());
        }, 10);
        return response()->json(['success' => true]);
    }

    private function validatedRole(Request $request, $id = null)
    {
        return $request->validate([
            'role.name' => ['required', 'string', 'max:191',
                Rule::unique('roles', 'name')->whereNull('deleted_at')->ignore($id)],
            'role.description' => 'nullable|string|max:1000',
            'permissions' => 'present|array',
            'permissions.*' => 'string|distinct|exists:permissions,name',
        ]);
    }

    //----------- Delete Role --------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Role::class);
        $role = Role::whereNull('deleted_at')->findOrFail($id);
        $this->assertUnassigned([$role->id]);
        Role::whereId($role->id)->update([
            'deleted_at' => Carbon::now(),
        ]);
        return response()->json(['success' => true]);
    }

    //-------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Role::class);
        $data = $request->validate(['selectedIds' => 'required|array|min:1',
            'selectedIds.*' => 'required|integer|distinct|exists:roles,id']);
        $ids = $data['selectedIds'];
        $this->assertUnassigned($ids);
        Role::whereIn('id', $ids)->whereNull('deleted_at')->update(['deleted_at' => Carbon::now()]);
        return response()->json(['success' => true]);
    }

    private function assertUnassigned(array $ids)
    {
        $assigned = DB::table('users')->whereNull('deleted_at')->whereIn('role_id', $ids)->exists()
            || DB::table('role_user as ru')->join('users as u', 'u.id', '=', 'ru.user_id')
                ->whereNull('u.deleted_at')->whereIn('ru.role_id', $ids)->exists();
        if ($assigned) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'role' => ['Remove this role from all users before deleting it.'],
            ]);
        }
    }

    //----------- Check Create Page --------------\\
    public function Check_Create_Page(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Role::class);
    }

    //----------- GET ALL Roles without paginate --------------\\

    public function getRoleswithoutpaginate()
    {
        $roles = Role::where('deleted_at', null)->get(['id', 'name']);
        return response()->json($roles);
    }

    //------------- Show Form Edit Permissions -----------\\

    public function edit(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'update', Role::class);

        $Role = Role::with('permissions')->where('deleted_at', '=', null)->findOrFail($id);
        if ($Role) {
            $item['name'] = $Role->name;
            $item['description'] = $Role->description;
            $data = [];
            if ($Role) {
                foreach ($Role->permissions as $permission) {
                    $data[] = $permission->name;
                }
            }
        }
        return response()->json([
            'permissions' => $data,
            'role' => $item,
        ]);
    }

}
