<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class GoodsReceiptPolicy
{
    use HandlesAuthorization;

    public function view(User $user)
    {
        return $this->allows($user, 'goods_receipt_view');
    }

    public function create(User $user)
    {
        return $this->allows($user, 'goods_receipt_add');
    }

    public function update(User $user)
    {
        return $this->allows($user, 'goods_receipt_edit');
    }

    public function delete(User $user)
    {
        return $this->allows($user, 'goods_receipt_delete');
    }

    /**
     * A permission that has not been seeded yet denies rather than crashing,
     * so a partially migrated install stays safe.
     */
    private function allows(User $user, $name)
    {
        $permission = Permission::where('name', $name)->first();

        if (! $permission) {
            return false;
        }

        return $user->hasRole($permission->roles);
    }
}
