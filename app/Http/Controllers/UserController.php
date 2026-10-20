<?php

namespace App\Http\Controllers;

use App\Exports\UsersExport;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\role_user;
use App\Models\product_warehouse;
use App\utils\helpers;
use App\utils\PasswordPolicy;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Intervention\Image\ImageManagerStatic as Image;
use Maatwebsite\Excel\Facades\Excel;

class UserController extends BaseController
{

    //------------- GET ALL USERS---------\\

    public function index(request $request)
    {

        $this->authorizeForUser($request->user('api'), 'view', User::class);
        // How many items do you want to display.
        $perPage = \App\utils\ListLimit::of($request->limit);
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $helpers = new helpers();
        // Filter fields With Params to retrieve
        $columns = array(0 => 'username', 1 => 'statut', 2 => 'phone', 3 => 'email');
        $param = array(0 => 'like', 1 => '=', 2 => 'like', 3 => 'like');
        $data = array();

        $Role = Auth::user()->roles()->first();
        $ShowRecord = Role::findOrFail($Role->id)->inRole('record_view');

        $users = User::where(function ($query) use ($ShowRecord) {
            if (!$ShowRecord) {
                return $query->where('id', '=', Auth::user()->id);
            }
        })->when($request->filled('role_id'), function ($query) use ($request) {
            return $query->where('role_id', $request->role_id);
        });

        //Multiple Filter
        $Filtred = $helpers->filter($users, $columns, $param, $request)
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('username', 'LIKE', "%{$request->search}%")
                        ->orWhere('firstname', 'LIKE', "%{$request->search}%")
                        ->orWhere('lastname', 'LIKE', "%{$request->search}%")
                        ->orWhere('email', 'LIKE', "%{$request->search}%")
                        ->orWhere('phone', 'LIKE', "%{$request->search}%");
                });
            });
        $totalRows = $Filtred->count();
        $users = $Filtred->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        $roles = Role::where('deleted_at', null)->get(['id', 'name']);
        $roleNames = $roles->pluck('name', 'id');

        // Which warehouses each person may work in (UserWarehouseTest).
        $assigned = \DB::table('user_warehouse')->whereIn('user_id', $users->pluck('id'))->get()->groupBy('user_id');
        foreach ($users as $u) {
            $u->role_name = $roleNames->get($u->role_id, 'Unassigned');
            $u->all_warehouses = (bool) ($u->all_warehouses ?? true);
            $u->warehouse_ids = $assigned->get($u->id, collect())->pluck('warehouse_id')->map(fn ($id) => (int) $id)->values()->all();
        }

        return response()->json([
            'users' => $users,
            'roles' => $roles,
            'totalRows' => $totalRows,
            // Every branch, to choose from when assigning.
            'warehouses' => \App\Models\Warehouse::withoutGlobalScope(\App\Models\Scopes\UserWarehouseScope::class)
                ->where('deleted_at', null)->get(['id', 'name']),
        ]);
    }

    /**
     * Put a user on all warehouses, or only on the ones chosen. Refuses an
     * empty choice: someone on no warehouse could do nothing at all.
     */
    private function assignWarehouses($userId, Request $request)
    {
        $all = ! $request->has('all_warehouses') || filter_var($request->input('all_warehouses'), FILTER_VALIDATE_BOOLEAN);
        $ids = collect((array) $request->input('warehouse_ids', []))->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id)->unique();

        if (! $all) {
            $ids = $ids->intersect(\DB::table('warehouses')->whereNull('deleted_at')->pluck('id')->map(fn ($id) => (int) $id));
            if ($ids->isEmpty()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'warehouse_ids' => ['Choose at least one warehouse, or tick All warehouses.'],
                ]);
            }
        }

        \DB::table('users')->where('id', $userId)->update(['all_warehouses' => $all ? 1 : 0]);
        \DB::table('user_warehouse')->where('user_id', $userId)->delete();
        if (! $all) {
            \DB::table('user_warehouse')->insert($ids->map(fn ($id) => ['user_id' => $userId, 'warehouse_id' => $id])->values()->all());
        }
        \App\utils\WarehouseAccess::forget();
    }

    //------------- GET USER Auth ---------\\

    public function GetUserAuth(Request $request)
    {
        $helpers = new helpers();
        // Screens that need to know whether the signed-in user is the same
        // person as the one on a record — approving your own purchase, for
        // instance — need the id, not just the display name.
        $user['id'] = Auth::user()->id;
        $user['avatar'] = Auth::user()->avatar;
        $user['username'] = Auth::user()->username;
        $user['currency'] = $helpers->Get_Currency();
        // So the till can show a VAT breakdown without asking again.
        $user['vat_rate'] = \App\utils\VatService::rate();
        $user['prices_include_vat'] = \App\utils\VatService::pricesIncludeVat();
        $user['logo'] = Setting::first()->logo;
        $user['default_language'] = Setting::first()->default_language;
        $user['footer'] = Setting::first()->footer;
        $user['developed_by'] = Setting::first()->developed_by;
        $permissions = Auth::user()->roles()->first()->permissions->pluck('name');
        // The screens can preselect a branch-tied person's only warehouse.
        $allowed = \App\utils\WarehouseAccess::allowedIds();
        $user['all_warehouses'] = $allowed === null;
        $user['warehouse_ids'] = $allowed ?? [];
        $products_alerts = product_warehouse::join('products', 'product_warehouse.product_id', '=', 'products.id')
            ->whereRaw('qte <= stock_alert')
            ->where('product_warehouse.deleted_at', null)
            ->count();

        return response()->json([
            'success' => true,
            'user' => $user,
            'notifs' => $products_alerts,
            'permissions' => $permissions,
        ]);
    }

    //------------- GET USER ROLES ---------\\

    public function GetUserRole(Request $request)
    {

        $roles = Auth::user()->roles()->with('permissions')->first();

        $data = [];
        if ($roles) {
            foreach ($roles->permissions as $permission) {
                $data[] = $permission->name;

            }
            return response()->json(['success' => true, 'data' => $data]);
        }

    }

    //------------- STORE NEW USER ---------\\

    /** What every user record must hold: what its columns can store (K37/K58). */
    private static function rules()
    {
        return [
            'firstname' => 'required|string|max:191',
            'lastname' => 'required|string|max:191',
            'username' => 'required|string|max:192',
            'email' => 'required|email|max:192',
            'phone' => 'required|string|max:192',
        ];
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', User::class);
        // A user needs a role -- without one the insert failed with a 500 (K68);
        // username and email are one of a kind, whatever their case (K58).
        $this->validate($request, array_merge(self::rules(), [
            'password' => 'required|string',
            'role' => 'required|integer|exists:roles,id',
        ]));
        \App\utils\Uniqueness::assertFree('users', 'username', $request['username'], null, 'username', 'username');
        \App\utils\Uniqueness::assertFree('users', 'email', $request['email'], null, 'email', 'email');

        // The password rules bound a user changing their own password and
        // nothing else, so an administrator creating an account could set
        // "123" and the policy screen would still say the rules were enforced.
        // An account is only as strong as the password it was born with.
        PasswordPolicy::assertAcceptable($request['password']);
        \DB::transaction(function () use ($request) {
            if ($request->hasFile('avatar')) {

                $image = $request->file('avatar');
                $filename = rand(11111111, 99999999) . $image->getClientOriginalName();

                $image_resize = Image::make($image->getRealPath());
                $image_resize->resize(128, 128);
                $image_resize->save(public_path('/images/avatar/' . $filename));

            } else {
                $filename = 'no_avatar.png';
            }

            $User = new User;
            $User->firstname = $request['firstname'];
            $User->lastname  = $request['lastname'];
            $User->username  = $request['username'];
            $User->email     = $request['email'];
            $User->phone     = $request['phone'];
            $User->password  = Hash::make($request['password']);
            $User->avatar    = $filename;
            $User->role_id   = $request['role'];
            $User->save();

            $role_user = new role_user;
            $role_user->user_id = $User->id;
            $role_user->role_id = $request['role'];
            $role_user->save();

            $this->assignWarehouses($User->id, $request);

        }, 10);

        return response()->json(['success' => true]);
    }

    //------------ function show -----------\\

    public function show($id){
        //
        
        }

    //------------- UPDATE  USER ---------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', User::class);
        
        $this->validate($request, array_merge(self::rules(), [
            'role' => 'required|integer|exists:roles,id',
        ]));
        \App\utils\Uniqueness::assertFree('users', 'username', $request['username'], $id, 'username', 'username');
        \App\utils\Uniqueness::assertFree('users', 'email', $request['email'], $id, 'email', 'email');

        \DB::transaction(function () use ($id ,$request) {
            $user = User::findOrFail($id);
            $current = $user->password;

            if ($request->NewPassword != 'null') {
                if ($request->NewPassword != $current) {
                    // Same rules as the user's own change screen, and the same
                    // history, so a reset cannot quietly reinstate a password
                    // the policy has already retired.
                    PasswordPolicy::assertAcceptable($request->NewPassword, $user->id);
                    $pass = Hash::make($request->NewPassword);
                    PasswordPolicy::remember($user->id, $pass);
                } else {
                    $pass = $user->password;
                }

            } else {
                $pass = $user->password;
            }

            $currentAvatar = $user->avatar;
            if ($request->avatar != $currentAvatar) {

                $image = $request->file('avatar');
                $path = public_path() . '/images/avatar';
                $filename = rand(11111111, 99999999) . $image->getClientOriginalName();

                $image_resize = Image::make($image->getRealPath());
                $image_resize->resize(128, 128);
                $image_resize->save(public_path('/images/avatar/' . $filename));

                $userPhoto = $path . '/' . $currentAvatar;
                if (file_exists($userPhoto)) {
                    if ($user->avatar != 'no_avatar.png') {
                        @unlink($userPhoto);
                    }
                }
            } else {
                $filename = $currentAvatar;
            }

            User::whereId($id)->update([
                'firstname' => $request['firstname'],
                'lastname' => $request['lastname'],
                'username' => $request['username'],
                'email' => $request['email'],
                'phone' => $request['phone'],
                'password' => $pass,
                'avatar' => $filename,
                'statut' => $request['statut'],
                'role_id' => $request['role'],
            ]);

            role_user::where('user_id' , $id)->update([
                'user_id' => $id,
                'role_id' => $request['role'],
            ]);

            // Only when the form sent it, so an edit from anywhere else never
            // quietly puts someone back on all warehouses.
            if ($request->has('all_warehouses')) {
                $this->assignWarehouses($id, $request);
            }

        }, 10);
        
        return response()->json(['success' => true]);

    }

    //------------- Export USERS to EXCEL ---------\\

    public function exportExcel(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', User::class);

        return Excel::download(new UsersExport, 'Users.xlsx');
    }

    //------------- UPDATE PROFILE ---------\\

    public function updateProfile(Request $request)
    {
        $id = Auth::user()->id;
        $user = User::findOrFail($id);
        $current = $user->password;

        // Not another user's username or email, whatever the case (DATATEST K58).
        $this->validate($request, self::rules());
        \App\utils\Uniqueness::assertFree('users', 'username', $request['username'], $id, 'username', 'username');
        \App\utils\Uniqueness::assertFree('users', 'email', $request['email'], $id, 'email', 'email');

        if ($request->NewPassword != 'undefined' && $request->NewPassword !== null && $request->NewPassword !== 'null') {
            if ($request->NewPassword != $current) {
                // The same password rules and history as every other screen.
                PasswordPolicy::assertAcceptable($request->NewPassword, $id);
                $pass = Hash::make($request->NewPassword);
                PasswordPolicy::remember($id, $pass);
            } else {
                $pass = $user->password;
            }

        } else {
            $pass = $user->password;
        }

        $currentAvatar = $user->avatar;
        if ($request->avatar != $currentAvatar) {

            $image = $request->file('avatar');
            $path = public_path() . '/images/avatar';
            $filename = rand(11111111, 99999999) . $image->getClientOriginalName();

            $image_resize = Image::make($image->getRealPath());
            $image_resize->resize(128, 128);
            $image_resize->save(public_path('/images/avatar/' . $filename));

            $userPhoto = $path . '/' . $currentAvatar;

            if (file_exists($userPhoto)) {
                if ($user->avatar != 'no_avatar.png') {
                    @unlink($userPhoto);
                }
            }
        } else {
            $filename = $currentAvatar;
        }

        User::whereId($id)->update([
            'firstname' => $request['firstname'],
            'lastname' => $request['lastname'],
            'username' => $request['username'],
            'email' => $request['email'],
            'phone' => $request['phone'],
            'password' => $pass,
            'avatar' => $filename,

        ]);

        return response()->json(['avatar' => $filename, 'user' => $request['username']]);

    }

    //----------- IsActivated (Update Statut User) -------\\

    public function IsActivated(request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'update', User::class);

        // The route id is the account being changed. The body id was compared
        // instead, and the screen never sends one, so anyone could switch
        // themselves off (DATATEST K64).
        $user = Auth::user();
        if ((int) $id === (int) $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot deactivate your own account.',
            ], 422);
        }

        // Nor may the last active user who can manage users be switched off:
        // nobody would be left to switch anyone back on.
        if (! (int) $request['statut']) {
            $managers = \DB::table('users as u')
                ->join('role_user as ru', 'ru.user_id', '=', 'u.id')
                ->join('permission_role as pr', 'pr.role_id', '=', 'ru.role_id')
                ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
                ->where('p.name', 'users_edit')->where('u.statut', 1)->whereNull('u.deleted_at')
                ->where('u.id', '!=', (int) $id)
                ->distinct()->count('u.id');
            if ($managers === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'This is the last active account that can manage users.',
                ], 422);
            }
        }

        $before = User::whereId($id)->value('statut');
        User::whereId($id)->update([
            'statut' => $request['statut'],
        ]);
        // Who switched whom on or off (DATATEST K60).
        \App\Observers\AuditObserver::record(User::class, $id, (int) $request['statut'] ? 'activated' : 'deactivated',
            ['statut' => $before], ['statut' => (int) $request['statut']]);

        return response()->json([
            'success' => true,
        ]);
    }

    public function GetPermissions()
    {
        $roles = Auth::user()->roles()->with('permissions')->first();
        $data = [];
        if ($roles) {
            foreach ($roles->permissions as $permission) {
                $item[$permission->name]['slug'] = $permission->name;
                $item[$permission->name]['id'] = $permission->id;

            }
            $data[] = $item;
        }
        return $data[0];

    }

    //------------- GET USER Auth ---------\\

    public function GetInfoProfile(Request $request)
    {
        $data = Auth::user();
        return response()->json(['success' => true, 'user' => $data]);
    }

}
