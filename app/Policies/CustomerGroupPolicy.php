<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Permission;
use Illuminate\Auth\Access\HandlesAuthorization;

class CustomerGroupPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user)
    {
        //
    }

    public function view(User $user)
    {
        return $this->allowed($user);
    }

    public function create(User $user)
    {
        return $this->allowed($user);
    }

    public function update(User $user)
    {
        return $this->allowed($user);
    }

    public function delete(User $user)
    {
        return $this->allowed($user);
    }

    public function restore(User $user)
    {
        //
    }

    public function forceDelete(User $user)
    {
        //
    }

    /**
     * A missing permission row would otherwise be a fatal error on a null,
     * so an absent permission is treated as "nobody", not as a crash.
     */
    private function allowed(User $user)
    {
        $permission = Permission::where('name', 'customer_group')->first();

        if (! $permission) {
            return false;
        }

        return $user->hasRole($permission->roles);
    }
}
