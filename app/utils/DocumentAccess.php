<?php

namespace App\utils;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Who may open a printed document (DATATEST K84/K96).
 *
 * The PDF and print endpoints checked nothing: a user with no permissions, a
 * stockman and a purchaser all downloaded customer invoices with the
 * customer's name, phone, e-mail and address. Each document now needs one of
 * the permissions that would let the user see it on screen.
 */
class DocumentAccess
{
    const NEEDS = [
        'sale' => ['Sales_view', 'Pos_view'],
        'quotation' => ['Quotations_view'],
        'purchase' => ['Purchases_view'],
        'sale_return' => ['Sale_Returns_view'],
        'purchase_return' => ['Purchase_Returns_view'],
        // A receipt for a payment: whoever may see the payments, or the
        // document they were taken against (a cashier reprinting one).
        'payment_sale' => ['payment_sales_view', 'Sales_view', 'Pos_view'],
        'payment_purchase' => ['payment_purchases_view', 'Purchases_view'],
        'payment_sale_return' => ['payment_returns_view', 'Sale_Returns_view'],
        'payment_purchase_return' => ['payment_returns_view', 'Purchase_Returns_view'],
    ];

    /** Refuse with 403 unless the signed-in user holds one of the kind's permissions. */
    public static function assert($kind)
    {
        if (! self::hasAny(self::NEEDS[$kind])) {
            throw new HttpResponseException(response()->json(['message' => 'You do not have permission to view this document.'], 403));
        }
    }

    /**
     * A payment's document is hidden from a user outside its warehouse, so it
     * loads as null. That is "not yours" (403), not a crash (K96 gave a 500).
     */
    public static function assertParent($parent)
    {
        if (! $parent) {
            throw new HttpResponseException(response()->json(['message' => 'This payment belongs to a warehouse you do not have access to.'], 403));
        }
    }

    /** Refuse with 403 unless the signed-in user holds this permission. */
    public static function need($permission)
    {
        if (! self::hasAny([$permission])) {
            throw new HttpResponseException(response()->json(['message' => 'You do not have permission to do this.'], 403));
        }
    }

    public static function hasAny(array $permissions)
    {
        $user = Auth::user();
        if (! $user) {
            return false;
        }

        return DB::table('permissions as p')
            ->join('permission_role as pr', 'pr.permission_id', '=', 'p.id')
            ->join('role_user as ru', 'ru.role_id', '=', 'pr.role_id')
            ->whereIn('p.name', $permissions)
            ->where('ru.user_id', $user->id)
            ->exists();
    }
}
