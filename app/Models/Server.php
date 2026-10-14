<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Server extends Model
{

    protected $fillable = [
        'host', 'port', 'username', 'password', 'encryption',
    ];

    protected $casts = [
        'port' => 'integer',
    ];

    /** Never in a JSON reply (ServerSmtpPasswordTest). */
    protected $hidden = ['password'];

    /** Stored encrypted with the app key. */
    public function setPasswordAttribute($value)
    {
        $this->attributes['password'] = ($value === null || $value === '')
            ? $value : \Illuminate\Support\Facades\Crypt::encryptString($value);
    }

    /**
     * Read back decrypted. A password saved before encryption (an older
     * install, a restored backup) is still plain text: use it as it is rather
     * than failing every email.
     */
    public function getPasswordAttribute($value)
    {
        if ($value === null || $value === '') {
            return $value;
        }
        try {
            return \Illuminate\Support\Facades\Crypt::decryptString($value);
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            return $value;
        }
    }

}
