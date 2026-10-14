<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Move the stored timestamps to the timezone the shop actually trades in.
 *
 * WHY THEY HAVE TO MOVE AT ALL
 *
 * MySQL stores a DATETIME as the digits it was handed; it does not remember a
 * zone. Laravel was writing UTC while the shop, the till and the clock on the
 * wall were eight hours ahead, so a sale rung at two in the afternoon was
 * recorded as six in the morning. Simply changing the application's timezone
 * would fix everything written from then on and quietly re-label everything
 * already there -- the same digits, now read as Manila time, denoting an instant
 * eight hours earlier than the one they were written for.
 *
 * So the digits are moved to match: every stored moment is converted from the
 * zone it was written in to the zone it will now be read in, and goes on
 * denoting the same instant.
 *
 * WHY A FLAT OFFSET IS SAFE HERE
 *
 * The Philippines has had no daylight saving since 1978 and sits at UTC+8 all
 * year, so the conversion is the same for every row whatever its date. A zone
 * with summer time would need CONVERT_TZ with named zones and the timezone
 * tables loaded in MySQL; this deliberately does not, so it cannot fail on a
 * server where those tables were never populated.
 *
 * ON A FRESH INSTALL THIS DOES NOTHING
 *
 * Migrations run before seeders and only once per database, so a new
 * installation runs this against empty tables and everything seeded afterwards
 * is already written in the new zone.
 */
class ShiftTimestampsToLocalTime extends Migration
{
    /** Written in this zone. */
    const FROM = '+00:00';

    /** Read in this one from now on. */
    const TO = '+08:00';

    /**
     * Transient tables. Their contents are queues, caches and login sessions
     * that are rewritten constantly and mean nothing an hour later, so moving
     * them buys nothing and risks confusing a worker mid-flight.
     */
    const SKIP = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'failed_jobs'];

    public function up()
    {
        $this->shift(self::FROM, self::TO);
    }

    public function down()
    {
        $this->shift(self::TO, self::FROM);
    }

    private function shift($from, $to)
    {
        $database = DB::connection()->getDatabaseName();

        $columns = DB::select("
            SELECT TABLE_NAME AS t, COLUMN_NAME AS c
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = ?
              AND DATA_TYPE IN ('timestamp', 'datetime')
            ORDER BY TABLE_NAME, COLUMN_NAME
        ", [$database]);

        $moved = 0;
        $tables = [];

        foreach ($columns as $column) {
            if (in_array($column->t, self::SKIP, true)) {
                continue;
            }

            $affected = DB::update(sprintf(
                'UPDATE `%s` SET `%s` = CONVERT_TZ(`%s`, ?, ?) WHERE `%s` IS NOT NULL',
                $column->t, $column->c, $column->c, $column->c
            ), [$from, $to]);

            if ($affected > 0) {
                $moved += $affected;
                $tables[$column->t] = true;
            }
        }

        if (app()->runningInConsole()) {
            echo sprintf("  %d timestamp value(s) across %d table(s) moved from %s to %s\n",
                $moved, count($tables), $from, $to);
        }
    }
}
