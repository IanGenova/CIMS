<?php

namespace Tests;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A signed-in API user who holds exactly the permissions a test names.
 *
 * Every policy in this application looks a permission up by name and asks
 * whether the user's role holds it. A freshly migrated test database has no
 * permissions at all, so the lookup returns null and the policy dies on
 * `$permission->roles` -- which reads like an authentication failure and is
 * not one. (It was mistaken for exactly that once already.)
 */
trait GrantsPermissions
{
    protected function actAsUserWith(array $permissions): User
    {
        $roleId = DB::table('roles')->insertGetId(['name' => 'probe_role', 'label' => 'Probe Role']);

        foreach ($permissions as $name) {
            $permissionId = DB::table('permissions')->where('name', $name)->value('id')
                ?? DB::table('permissions')->insertGetId(['name' => $name, 'label' => $name]);
            DB::table('permission_role')->insert(['permission_id' => $permissionId, 'role_id' => $roleId]);
        }

        $user = User::create([
            'firstname' => 'Probe', 'lastname' => 'User', 'username' => 'probe_user',
            'email' => 'probe.user@example.invalid', 'password' => bcrypt('Str0ngEnough'),
            'statut' => 1, 'role_id' => $roleId,
        ]);
        DB::table('role_user')->insert(['role_id' => $roleId, 'user_id' => $user->id]);

        $this->actingAs($user, 'api');

        return $user;
    }
}
