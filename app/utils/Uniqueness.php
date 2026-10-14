<?php

namespace App\utils;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Names and codes that must be one of a kind, compared the way a person
 * reads them: "USD", "usd " and "U SD" are the same code (DATATEST K58;
 * owner decision: exact duplicates refused, ignoring case and spaces).
 *
 * The database's own unique checks compared bytes, and some screens had none:
 * the profile screen took another user's username, a second USD was saved.
 */
class Uniqueness
{
    public static function normalise($value)
    {
        return mb_strtolower(preg_replace('/\s+/u', '', (string) $value));
    }

    /** Refuse $value if another live row already has it. Empty values are not checked. */
    public static function assertFree($table, $column, $value, $ignoreId = null, $field = null, $label = null)
    {
        $norm = self::normalise($value);
        if ($norm === '') {
            return;
        }

        $q = DB::table($table)
            ->whereRaw("LOWER(REPLACE(REPLACE(REPLACE(`{$column}`, ' ', ''), '\t', ''), '\n', '')) = ?", [$norm]);
        if ($ignoreId) {
            $q->where('id', '!=', $ignoreId);
        }
        if (Schema::hasColumn($table, 'deleted_at')) {
            $q->whereNull('deleted_at');
        }

        if ($q->exists()) {
            $what = $label ?: str_replace('_', ' ', $column);
            throw ValidationException::withMessages([($field ?: $column) => [
                'That ' . $what . ' is already in use.',
            ]]);
        }
    }
}
