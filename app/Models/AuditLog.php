<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'audit_logs';

    /** Only created_at is kept; an audit row is never updated. */
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'user_name', 'event', 'auditable_type', 'auditable_id',
        'old_values', 'new_values', 'ip_address', 'user_agent', 'url', 'created_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'auditable_id' => 'integer',
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }

    /** "App\Models\Sale" -> "Sale", for display and filtering. */
    public function getAuditableNameAttribute()
    {
        return class_basename($this->auditable_type);
    }
}
