<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalLine extends Model
{
    protected $table = 'journal_lines';

    protected $fillable = [
        'journal_entry_id', 'account_id', 'debit', 'credit', 'memo',
    ];

    protected $casts = [
        'journal_entry_id' => 'integer',
        'account_id' => 'integer',
        'debit' => 'double',
        'credit' => 'double',
    ];

    public function entry()
    {
        return $this->belongsTo('App\Models\JournalEntry', 'journal_entry_id');
    }

    public function account()
    {
        return $this->belongsTo('App\Models\Account', 'account_id');
    }
}
