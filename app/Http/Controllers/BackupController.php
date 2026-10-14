<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\utils\BackupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class BackupController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', User::class);

        $backups = DB::table('backups')->orderByDesc('id')->limit(50)->get();

        $rows = [];
        foreach ($backups as $b) {
            $rows[] = [
                'id' => $b->id,
                'filename' => $b->filename,
                'size_bytes' => (int) $b->size_bytes,
                'size_human' => $this->human((int) $b->size_bytes),
                'trigger' => $b->trigger,
                'status' => $b->status,
                'offsite' => (bool) $b->offsite,
                'offsite_disk' => $b->offsite_disk,
                'verified' => $b->verified_at !== null,
                'verified_at' => $b->verified_at,
                'error' => $b->error,
                'created_at' => $b->created_at,
                'exists' => $b->path ? Storage::disk($b->disk)->exists($b->path) : false,
            ];
        }

        // Same tinyint-to-checkbox trap as the security policy.
        $settings = (array) BackupService::settings();
        foreach (['scheduled_enabled', 'offsite_enabled', 'verify_after_backup'] as $flag) {
            if (array_key_exists($flag, $settings)) {
                $settings[$flag] = (bool) $settings[$flag];
            }
        }
        if (array_key_exists('retention_count', $settings)) {
            $settings['retention_count'] = (int) $settings['retention_count'];
        }

        return response()->json([
            'backups' => $rows,
            'settings' => $settings,
            'available_disks' => array_keys(config('filesystems.disks', [])),
            // A schedule only runs if the OS cron is calling schedule:run.
            'scheduler_hint' => 'The schedule only fires if your server runs '
                . '"php artisan schedule:run" every minute via cron or Task Scheduler.',
        ]);
    }

    public function run(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', User::class);

        try {
            $id = BackupService::run('manual', Auth::user()->id);
            $b = DB::table('backups')->where('id', $id)->first();

            return response()->json([
                'success' => true,
                'id' => $id,
                'filename' => $b->filename,
                'size_human' => $this->human((int) $b->size_bytes),
                'verified' => $b->verified_at !== null,
            ]);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'backup' => ['The backup failed: ' . $e->getMessage()],
            ]);
        }
    }

    /** Re-check a stored file against its recorded checksum. */
    public function verify(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', User::class);

        $ok = BackupService::verify($id);
        $b = DB::table('backups')->where('id', $id)->first();

        return response()->json([
            'success' => true,
            'verified' => $ok,
            'error' => $b ? $b->error : null,
        ]);
    }

    public function download(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', User::class);

        $b = DB::table('backups')->where('id', $id)->first();
        if (! $b || ! $b->path || ! Storage::disk($b->disk)->exists($b->path)) {
            throw ValidationException::withMessages([
                'backup' => ['That backup file is no longer on disk.'],
            ]);
        }

        return response(Storage::disk($b->disk)->get($b->path), 200, [
            'Content-Type' => 'application/sql',
            'Content-Disposition' => 'attachment; filename="' . $b->filename . '"',
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', User::class);

        $b = DB::table('backups')->where('id', $id)->first();
        if ($b && $b->path && Storage::disk($b->disk)->exists($b->path)) {
            Storage::disk($b->disk)->delete($b->path);
        }
        DB::table('backups')->where('id', $id)->delete();

        return response()->json(['success' => true]);
    }

    public function updateSettings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', User::class);

        $request->validate([
            'frequency' => 'required|in:hourly,daily,weekly',
            'retention_count' => 'required|integer|min:1|max:365',
            'run_at' => 'nullable|string|max:8',
        ]);

        // Pointing offsite at a disk that isn't configured would fail silently
        // at 2am, which is the worst time to find out.
        $disk = $request['offsite_disk'];
        if ($request['offsite_enabled'] && (! $disk || ! array_key_exists($disk, config('filesystems.disks', [])))) {
            throw ValidationException::withMessages([
                'offsite_disk' => ['Choose a configured filesystem disk for the offsite copy.'],
            ]);
        }

        DB::table('backup_settings')->update([
            'scheduled_enabled' => (bool) $request['scheduled_enabled'],
            'frequency' => $request->frequency,
            'run_at' => $request['run_at'] ?: '02:00',
            'retention_count' => $request->retention_count,
            'offsite_enabled' => (bool) $request['offsite_enabled'],
            'offsite_disk' => $disk,
            'verify_after_backup' => (bool) $request['verify_after_backup'],
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    private function human($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }
}
