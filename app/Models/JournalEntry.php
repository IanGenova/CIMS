<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    protected $table = 'journal_entries';

    protected $fillable = [
        'Ref', 'date', 'memo', 'source_type', 'source_id',
        'reverses_entry_id', 'user_id',
    ];

    protected $casts = [
        'date' => 'date',
        'source_id' => 'integer',
        'reverses_entry_id' => 'integer',
        'user_id' => 'integer',
    ];

    public function lines()
    {
        return $this->hasMany('App\Models\JournalLine', 'journal_entry_id');
    }
}
