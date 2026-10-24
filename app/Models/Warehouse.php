<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'code', 'name', 'site_type', 'mobile', 'country', 'city', 'email', 'zip',
    ];

    /**
     * Every warehouse picker offers only the signed-in person's warehouses
     * (all of them for anyone on all warehouses). Code that must see every
     * branch -- a transfer's destination list, setting up a new product's
     * stock rows -- asks withoutGlobalScope(UserWarehouseScope::class).
     * See WarehouseAccessTest.
     */
    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\UserWarehouseScope(['id']));
    }

}
