<?php

namespace App\Models\Scopes;

use App\utils\WarehouseAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Only the rows in the signed-in person's warehouses.
 *
 * Does nothing for anyone on all warehouses, or with nobody signed in
 * (scheduler, console). A row matches when ANY of the given columns is one of
 * their warehouses -- a transfer is theirs if it leaves from or arrives at
 * one of them. See WarehouseAccessTest.
 */
class UserWarehouseScope implements Scope
{
    // Laravel names a scope object by its class: withoutGlobalScope(UserWarehouseScope::class).
    const NAME = self::class;

    private $columns;

    public function __construct(array $columns)
    {
        $this->columns = $columns;
    }

    public function apply(Builder $builder, Model $model)
    {
        $ids = WarehouseAccess::allowedIds();
        if ($ids === null) {
            return;
        }

        $builder->where(function ($q) use ($model, $ids) {
            foreach ($this->columns as $column) {
                $q->orWhereIn($model->qualifyColumn($column), $ids ?: [0]);
            }
        });
    }
}
