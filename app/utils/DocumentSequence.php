<?php

namespace App\utils;

use Illuminate\Support\Facades\DB;

/**
 * Allocates document reference numbers.
 *
 * Every reference in this system used to be produced by reading the last row,
 * adding one, and hoping. Two tills ringing up at the same moment both read the
 * same last row and both got the same invoice number, and nothing in the schema
 * stopped both from being stored. Sequential, unique numbering is a condition of
 * BIR registration, so this had to become something the database enforces rather
 * than something the application intends.
 *
 * A counter row is claimed with a single atomic statement:
 *
 *     UPDATE document_sequences SET next_number = LAST_INSERT_ID(next_number + 1) ...
 *
 * MySQL's LAST_INSERT_ID(expr) sets and returns the value in one operation, per
 * connection, so two requests cannot come away holding the same number -- the
 * second waits on the row lock and reads the incremented value. Laravel uses one
 * PDO connection per request, so the session-local read is safe.
 *
 * The allocation runs inside whatever transaction the caller is already in. That
 * is deliberate: a sale that rolls back gives its number back rather than leaving
 * a hole in the sequence, which is the behaviour an examiner expects.
 */
class DocumentSequence
{
    /** Give up rather than spin if the table is somehow full of collisions. */
    private const MAX_PROBES = 100;

    /**
     * The next reference for a document table.
     *
     * @param  string  $table   the table the reference is stored in
     * @param  string  $prefix  everything before the number, e.g. "SL_" or "PRD-"
     * @param  int     $pad     zero-pad the number to this width; 0 for none
     * @param  int     $start   the first number to issue on an empty table
     */
    public static function next($table, $prefix, $pad = 0, $start = 1)
    {
        $key = $table . ':' . $prefix;

        self::seed($key, $table, $prefix, $start);

        for ($probe = 0; $probe < self::MAX_PROBES; $probe++) {
            $number = self::claim($key);

            if ($number === null) {
                // The counter row disappeared -- another process pruning, or a
                // restored database. Re-seed and try again.
                self::seed($key, $table, $prefix, $start, true);
                continue;
            }

            $ref = $prefix . ($pad > 0 ? str_pad($number, $pad, '0', STR_PAD_LEFT) : $number);

            // A reference imported from elsewhere, or carried over from before
            // this table had a counter, can already occupy the number. Step past
            // it rather than handing the caller a reference the unique index is
            // about to reject.
            if (! self::taken($table, $ref)) {
                return $ref;
            }
        }

        throw new \RuntimeException(
            'Could not allocate a free reference for ' . $table . ' after ' . self::MAX_PROBES . ' attempts.'
        );
    }

    /**
     * What the next reference would be, without consuming it.
     *
     * The payment forms fetch a number to show before the user has committed to
     * anything. Allocating there would burn a number every time somebody opened
     * a dialog and changed their mind, so this reads without claiming. The number
     * actually stored is allocated by next() when the payment is saved, and under
     * concurrency the two can differ -- which is honest: the form is showing an
     * expectation, not a reservation.
     */
    public static function peek($table, $prefix, $pad = 0, $start = 1)
    {
        $key = $table . ':' . $prefix;

        self::seed($key, $table, $prefix, $start);

        $last = DB::table('document_sequences')->where('sequence_key', $key)->value('next_number');
        $number = ($last === null ? $start - 1 : (int) $last) + 1;

        for ($probe = 0; $probe < self::MAX_PROBES; $probe++) {
            $ref = $prefix . ($pad > 0 ? str_pad($number, $pad, '0', STR_PAD_LEFT) : $number);
            if (! self::taken($table, $ref)) {
                return $ref;
            }
            $number++;
        }

        return $prefix . ($pad > 0 ? str_pad($number, $pad, '0', STR_PAD_LEFT) : $number);
    }

    /**
     * The next plain numeric code, for tables that identify a record by a bare
     * integer rather than a prefixed reference -- customers and suppliers.
     *
     * These had the same race, and a worse variant of it: the old version read
     * the code of the most recently *inserted* row rather than the highest one,
     * so an import that happened to end on a low code would hand the next
     * customer a number already in use.
     */
    public static function nextCode($table, $column = 'code', $start = 1)
    {
        $key = $table . ':#' . $column;

        self::seed($key, $table, null, $start, false, $column);

        for ($probe = 0; $probe < self::MAX_PROBES; $probe++) {
            $number = self::claim($key);

            if ($number === null) {
                self::seed($key, $table, null, $start, true, $column);
                continue;
            }

            if (! self::taken($table, $number, $column)) {
                return $number;
            }
        }

        throw new \RuntimeException(
            'Could not allocate a free code for ' . $table . ' after ' . self::MAX_PROBES . ' attempts.'
        );
    }

    /**
     * Claim the next number. Returns null if there is no counter row to claim.
     */
    private static function claim($key)
    {
        $updated = DB::update(
            'UPDATE document_sequences SET next_number = LAST_INSERT_ID(next_number + 1), updated_at = ? WHERE sequence_key = ?',
            [now(), $key]
        );

        if ($updated === 0) {
            return null;
        }

        return (int) DB::selectOne('SELECT LAST_INSERT_ID() AS n')->n;
    }

    /**
     * Create the counter if it is not there yet, starting from the highest number
     * the table already contains so an existing deployment carries on where it
     * left off instead of colliding with its own history.
     */
    private static function seed($key, $table, $prefix, $start, $force = false, $column = 'Ref')
    {
        if (! $force && DB::table('document_sequences')->where('sequence_key', $key)->exists()) {
            return;
        }

        $highest = $prefix === null
            ? self::highestNumeric($table, $column)
            : self::highestExisting($table, $prefix);

        // next_number holds the last number issued, so the first claim returns
        // one past it.
        $last = $highest !== null ? $highest : ($start - 1);

        // insertOrIgnore, so two requests seeding at once produce one row rather
        // than a duplicate-key error.
        DB::table('document_sequences')->insertOrIgnore([
            'sequence_key' => $key,
            'next_number' => $last,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The largest number already used on this table for this prefix, ignoring
     * anything that does not end in digits. Soft-deleted rows count: a deleted
     * invoice must not have its number reissued.
     */
    private static function highestExisting($table, $prefix)
    {
        if (! DB::getSchemaBuilder()->hasTable($table)) {
            return null;
        }

        // The offset is derived from the prefix we were handed, not from input.
        $offset = strlen($prefix) + 1;

        $row = DB::table($table)
            ->where('Ref', 'like', str_replace(['%', '_'], ['\%', '\_'], $prefix) . '%')
            ->selectRaw('MAX(CAST(SUBSTRING(Ref, ' . (int) $offset . ') AS UNSIGNED)) AS m')
            ->first();

        return ($row && $row->m !== null) ? (int) $row->m : null;
    }

    /** The largest value already in a plain numeric column. */
    private static function highestNumeric($table, $column)
    {
        if (! DB::getSchemaBuilder()->hasTable($table)) {
            return null;
        }

        $max = DB::table($table)->max($column);

        return $max === null ? null : (int) $max;
    }

    private static function taken($table, $ref, $column = 'Ref')
    {
        return DB::table($table)->where($column, $ref)->exists();
    }
}
