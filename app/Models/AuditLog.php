<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AuditLog extends Model
{
    protected $table = 'audit_log';
    public $timestamps = false;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'before' => 'array', 'after' => 'array', 'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
