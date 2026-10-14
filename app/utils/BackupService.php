<?php

namespace App\utils;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Database backups: taken, recorded, verified, pruned, and optionally copied
 * offsite.
 *
 * Backup used to be a button that dumped SQL to local disk with no schedule,
 * no retention, no offsite copy and no way to know whether the file was any
 * good. Each of those is addressed here, and the verification step matters
 * most: a backup nobody has ever checked is a hope, not a backup.
 */
class BackupService
{
    public static function settings()
    {
        return DB::table('backup_settings')->first();
    }

    /**
     * Take a backup and record it.
     *
     * @param  string  $trigger  manual | scheduled
     */
    public static function run($trigger = 'manual', $userId = null)
    {
        $settings = self::settings();
        $filename = 'backup-' . date('Y-m-d-His') . '.sql';

        $id = DB::table('backups')->insertGetId([
            'filename' => $filename,
            'disk' => 'local',
            'trigger' => $trigger,
            'status' => 'pending',
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $sql = self::dump();
            $path = 'backups/' . $filename;
            Storage::disk('local')->put($path, $sql);

            $size = strlen($sql);
            $checksum = hash('sha256', $sql);

            DB::table('backups')->where('id', $id)->update([
                'path' => $path,
                'size_bytes' => $size,
                'checksum' => $checksum,
                'status' => 'completed',
                'updated_at' => now(),
            ]);

            if ($settings && $settings->verify_after_backup) {
                self::verify($id);
            }

            if ($settings && $settings->offsite_enabled && $settings->offsite_disk) {
                self::copyOffsite($id, $settings->offsite_disk);
            }

            self::prune();

            return $id;
        } catch (\Throwable $e) {
            DB::table('backups')->where('id', $id)->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'updated_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * Re-read the stored file and check it still hashes to what was recorded,
     * and that it actually looks like a dump rather than an empty file.
     */
    public static function verify($backupId)
    {
        $b = DB::table('backups')->where('id', $backupId)->first();
        if (! $b || ! $b->path) {
            return false;
        }

        if (! Storage::disk($b->disk)->exists($b->path)) {
            DB::table('backups')->where('id', $backupId)->update([
                'error' => 'The backup file is missing from disk.',
                'verified_at' => null,
                'updated_at' => now(),
            ]);

            return false;
        }

        $content = Storage::disk($b->disk)->get($b->path);
        $ok = hash('sha256', $content) === $b->checksum
            && strpos($content, 'CREATE TABLE') !== false;

        DB::table('backups')->where('id', $backupId)->update([
            'verified_at' => $ok ? now() : null,
            'error' => $ok ? null : 'Verification failed: the file does not match its checksum or contains no tables.',
            'updated_at' => now(),
        ]);

        return $ok;
    }

    public static function copyOffsite($backupId, $disk)
    {
        $b = DB::table('backups')->where('id', $backupId)->first();
        if (! $b || ! $b->path) {
            return false;
        }

        try {
            $content = Storage::disk($b->disk)->get($b->path);
            Storage::disk($disk)->put($b->path, $content);

            DB::table('backups')->where('id', $backupId)->update([
                'offsite' => true,
                'offsite_disk' => $disk,
                'updated_at' => now(),
            ]);

            return true;
        } catch (\Throwable $e) {
            // An offsite failure must not lose the local backup that succeeded.
            DB::table('backups')->where('id', $backupId)->update([
                'offsite' => false,
                'error' => 'Offsite copy failed: ' . $e->getMessage(),
                'updated_at' => now(),
            ]);

            return false;
        }
    }

    /** Keep only the newest N, deleting the files as well as the rows. */
    public static function prune()
    {
        $settings = self::settings();
        $keep = $settings ? (int) $settings->retention_count : 7;
        if ($keep <= 0) {
            return 0;
        }

        $keepIds = DB::table('backups')->where('status', 'completed')
            ->orderByDesc('id')->limit($keep)->pluck('id')->all();

        $stale = DB::table('backups')
            ->where('status', 'completed')
            ->whereNotIn('id', $keepIds ?: [0])
            ->get();

        $removed = 0;
        foreach ($stale as $b) {
            try {
                if ($b->path && Storage::disk($b->disk)->exists($b->path)) {
                    Storage::disk($b->disk)->delete($b->path);
                }
                if ($b->offsite && $b->offsite_disk && Storage::disk($b->offsite_disk)->exists($b->path)) {
                    Storage::disk($b->offsite_disk)->delete($b->path);
                }
            } catch (\Throwable $e) {
                // A file that has already gone is not a reason to keep the row.
            }

            DB::table('backups')->where('id', $b->id)->delete();
            $removed++;
        }

        return $removed;
    }

    /** Whether the schedule says a backup is due now. */
    public static function isDue()
    {
        $s = self::settings();
        if (! $s || ! $s->scheduled_enabled) {
            return false;
        }

        if (! $s->last_run_at) {
            return true;
        }

        $last = strtotime($s->last_run_at);
        $intervals = ['hourly' => 3600, 'daily' => 86400, 'weekly' => 604800];
        $interval = isset($intervals[$s->frequency]) ? $intervals[$s->frequency] : 86400;

        return (time() - $last) >= $interval;
    }

    public static function markRun()
    {
        DB::table('backup_settings')->update([
            'last_run_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------

    /**
     * A portable SQL dump built through PDO, so it does not depend on mysqldump
     * being installed or on PATH -- which on a Windows/WAMP box it often isn't.
     */
    private static function dump()
    {
        $database = DB::getDatabaseName();
        $out = "-- Backup of `{$database}`\n";
        $out .= "-- Taken " . date('Y-m-d H:i:s') . "\n";
        $out .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach (DB::select('SHOW TABLES') as $row) {
            $table = array_values((array) $row)[0];

            $create = DB::select("SHOW CREATE TABLE `{$table}`");
            $createSql = array_values((array) $create[0])[1];

            $out .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $out .= $createSql . ";\n\n";

            // Stream the rows in pages so a large table does not have to be
            // held in memory all at once.
            $offset = 0;
            $chunk = 500;
            while (true) {
                $rows = DB::select("SELECT * FROM `{$table}` LIMIT {$chunk} OFFSET {$offset}");
                if (empty($rows)) {
                    break;
                }

                foreach ($rows as $r) {
                    $values = [];
                    foreach ((array) $r as $v) {
                        if ($v === null) {
                            $values[] = 'NULL';
                        } elseif (is_numeric($v) && ! is_string($v)) {
                            $values[] = $v;
                        } else {
                            $values[] = DB::getPdo()->quote((string) $v);
                        }
                    }
                    $out .= "INSERT INTO `{$table}` VALUES (" . implode(',', $values) . ");\n";
                }

                $offset += $chunk;
            }

            $out .= "\n";
        }

        $out .= "SET FOREIGN_KEY_CHECKS=1;\n";

        return $out;
    }
}
