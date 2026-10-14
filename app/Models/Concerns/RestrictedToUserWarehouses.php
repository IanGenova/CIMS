<?php

namespace App\Models\Concerns;

use App\Models\Scopes\UserWarehouseScope;

/**
 * A document that belongs to one branch: staff tied to other branches neither
 * see it in lists nor open it by its address (findOrFail answers 404).
 *
 * A model whose branch lives in other columns sets
 *   protected static $warehouseColumns = ['from_warehouse_id', 'to_warehouse_id'];
 */
trait RestrictedToUserWarehouses
{
    public static function bootRestrictedToUserWarehouses()
    {
        $columns = property_exists(static::class, 'warehouseColumns') ? static::$warehouseColumns : ['warehouse_id'];
        static::addGlobalScope(new UserWarehouseScope($columns));
    }
}
