<?php

namespace App\utils;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The date a document may carry (DATATEST K55/K37; owner decision: at most
 * 30 days back, never in the future).
 *
 * Sales were saved dated 9999-12-31 and 1900-01-01, a payment 0001-01-01, an
 * adjustment 30 February -- each lands in a report period nobody looks at, or
 * none at all. Till sales are always "now" inside an open session, so they
 * can never land in a closed Z-reading; the back office does not post to one.
 */
class BusinessDate
{
    const DAYS_BACK = 30;

    /** Refuse a date that is not a real calendar day inside the window. */
    public static function check($value, $field = 'date')
    {
        $value = trim((string) $value);
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw ValidationException::withMessages([$field => ['Enter a real date, such as ' . date('Y-m-d') . '.']]);
        }

        $day = "{$m[1]}-{$m[2]}-{$m[3]}";
        if ($day > date('Y-m-d')) {
            throw ValidationException::withMessages([$field => ['A document cannot be dated in the future.']]);
        }
        $earliest = date('Y-m-d', strtotime('-' . self::DAYS_BACK . ' days'));
        if ($day < $earliest) {
            throw ValidationException::withMessages([$field => [
                'A document can be dated at most ' . self::DAYS_BACK . ' days back (' . $earliest . ' or later).',
            ]]);
        }
    }

    /**
     * On an edit, only a changed date is checked: an old document keeps the
     * date it has, and can still be corrected.
     */
    public static function checkIfChanged($table, $id, $value, $field = 'date')
    {
        $stored = DB::table($table)->where('id', $id)->value('date');
        if ($stored !== null && substr((string) $stored, 0, 10) === substr(trim((string) $value), 0, 10)) {
            return;
        }
        self::check($value, $field);
    }
}
