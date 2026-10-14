<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosSetting extends Model
{
    protected $fillable = [
        'note_customer', 'show_note', 'show_barcode', 'show_discount', 'show_customer',
        'show_email', 'show_phone', 'show_address',
        'paper_size', 'auto_print', 'printer_name', 'open_cash_drawer',
        'drawer_pin', 'auto_cut', 'require_till_session',
    ];

    /**
     * Cast as booleans, not integers.
     *
     * MySQL hands a tinyint back as the integer 1, and a checkbox bound to 1
     * renders unchecked -- so every one of these toggles showed OFF while the
     * database said ON, and saving the form then turned them off for real. The
     * same fault was found earlier on the password policy and backup screens.
     */
    protected $casts = [
        'show_note' => 'boolean',
        'show_barcode' => 'boolean',
        'show_discount' => 'boolean',
        'show_customer' => 'boolean',
        'show_email' => 'boolean',
        'show_phone' => 'boolean',
        'show_address' => 'boolean',
        'auto_print' => 'boolean',
        'open_cash_drawer' => 'boolean',
        'auto_cut' => 'boolean',
        'require_till_session' => 'boolean',
        'drawer_pin' => 'integer',
    ];
}
