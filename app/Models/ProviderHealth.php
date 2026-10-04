<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ProviderHealth extends Model
{
    protected $table = 'provider_health';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'latency_ms' => 'integer',
            'last_success_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
            'last_failure_at' => 'immutable_datetime',
            'source_at' => 'immutable_datetime',
            'is_stale' => 'boolean',
            'consecutive_failures' => 'integer',
        ];
    }
}
