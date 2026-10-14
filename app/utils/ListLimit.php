<?php

namespace App\utils;

/**
 * Rows per page for a list (DATATEST K65).
 *
 * The lists' "All" choice sends limit=-1, which went straight into
 * LIMIT -1 OFFSET ... and every list answered with a SQL error.
 */
class ListLimit
{
    /** Enough for "every row" without an unbounded query. */
    const ALL = 100000;

    public static function of($limit, $default = null)
    {
        $n = (int) $limit;
        if ($n > 0) {
            return $n;
        }
        if ($n < 0) {
            return self::ALL;                 // "All"
        }

        return $default ?? self::ALL;         // not sent: as before (no limit, or the list's default)
    }
}
