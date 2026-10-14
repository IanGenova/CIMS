<?php

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run()
    {
        DB::table('users')->insert([
            'id' => 1,
            'firstname' => env('CIMS_ADMIN_FIRSTNAME', 'System'),
            'lastname' => env('CIMS_ADMIN_LASTNAME', 'Administrator'),
            'username' => env('CIMS_ADMIN_NAME', 'System Administrator'),
            'email' => env('CIMS_ADMIN_EMAIL', 'admin@example.com'),
            'password' => Hash::make(env('CIMS_ADMIN_PASSWORD', 'ChangeMe123!')),
            'avatar' => 'no_avatar.png',
            'phone' => env('CIMS_ADMIN_PHONE', ''),
            'role_id' => 1,
            'statut' => 1,
        ]);
    }
}
