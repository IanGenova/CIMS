<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A customer or supplier email is optional, but must be a real address when
 * given (DATATEST K72; owner decision). A walk-in customer without one failed
 * with "Column 'email' cannot be null".
 */
class MakePartnerEmailOptional extends Migration
{
    public function up()
    {
        DB::statement('ALTER TABLE clients MODIFY email VARCHAR(192) NULL');
        DB::statement('ALTER TABLE providers MODIFY email VARCHAR(192) NULL');
    }

    public function down()
    {
        DB::statement("UPDATE clients SET email = '' WHERE email IS NULL");
        DB::statement("UPDATE providers SET email = '' WHERE email IS NULL");
        DB::statement('ALTER TABLE clients MODIFY email VARCHAR(192) NOT NULL');
        DB::statement('ALTER TABLE providers MODIFY email VARCHAR(192) NOT NULL');
    }
}
