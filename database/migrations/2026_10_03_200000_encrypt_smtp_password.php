<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * The SMTP password, encrypted at rest (App\Models\Server casts it).
 * The column grows to hold the encrypted form, and passwords saved before
 * this are encrypted in place.
 */
class EncryptSmtpPassword extends Migration
{
    public function up()
    {
        DB::statement('ALTER TABLE servers MODIFY password TEXT NULL');

        foreach (DB::table('servers')->get(['id', 'password']) as $row) {
            if ($row->password === null || $row->password === '') {
                continue;
            }
            try {
                Crypt::decryptString($row->password);   // already encrypted
            } catch (DecryptException $e) {
                DB::table('servers')->where('id', $row->id)
                    ->update(['password' => Crypt::encryptString($row->password)]);
            }
        }
    }

    public function down()
    {
        foreach (DB::table('servers')->get(['id', 'password']) as $row) {
            try {
                DB::table('servers')->where('id', $row->id)
                    ->update(['password' => Crypt::decryptString($row->password)]);
            } catch (DecryptException $e) {
                // left as it is
            }
        }
        DB::statement('ALTER TABLE servers MODIFY password VARCHAR(191) NOT NULL');
    }
}
