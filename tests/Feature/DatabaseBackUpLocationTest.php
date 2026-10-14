<?php

namespace Tests\Feature;

use App\Console\Commands\DatabaseBackUp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\GrantsPermissions;
use Tests\TestCase;

/**
 * Database copies are never kept where the website serves files.
 *
 * Found while explaining Settings: the old "Backup" screen wrote its dumps to
 * storage/app/public/backup, which public/storage links to -- so a backup was
 * downloadable by anyone who guessed its name (backup-YYYY-MM-DD.sql). The
 * placeholder found there answered HTTP 200. Copies now go to storage/app/backup,
 * which the web server never serves.
 */
class DatabaseBackUpLocationTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    /** @test */
    public function the_backup_folder_is_not_inside_the_public_storage()
    {
        $dir = str_replace('\\', '/', DatabaseBackUp::directory());
        $public = str_replace('\\', '/', storage_path('app/public'));

        $this->assertStringStartsNotWith($public, $dir);
        $this->assertSame(str_replace('\\', '/', storage_path('app/backup')), $dir);
    }

    /** @test */
    public function the_old_backup_screen_lists_and_deletes_only_private_copies()
    {
        $this->actAsUserWith(['backup']);
        @mkdir(DatabaseBackUp::directory(), 0755, true);
        $file = DatabaseBackUp::directory() . '/backup-2026-10-03.sql';
        file_put_contents($file, '-- test dump');

        try {
            $listed = $this->getJson('/api/GetBackup')->assertOk()->json('backups');
            $this->assertContains('backup-2026-10-03.sql', array_column($listed, 'date'));

            $this->deleteJson('/api/DeleteBackup/backup-2026-10-03.sql')->assertOk();
            $this->assertFileDoesNotExist($file);
        } finally {
            @unlink($file);
        }
    }

    /** @test */
    public function a_name_cannot_reach_outside_the_backup_folder()
    {
        $this->actAsUserWith(['backup']);
        $outside = storage_path('app/keep-me.txt');
        file_put_contents($outside, 'not a backup');

        try {
            $this->deleteJson('/api/DeleteBackup/..%2Fkeep-me.txt');
            $this->assertFileExists($outside);
        } finally {
            @unlink($outside);
        }
    }
}
