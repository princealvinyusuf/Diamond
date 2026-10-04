<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class BrokerSymbolSpec extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'contract_size' => 'decimal:8',
            'tick_size' => 'decimal:10',
            'tick_value' => 'decimal:8',
            'volume_min' => 'decimal:8',
            'volume_max' => 'decimal:8',
            'volume_step' => 'decimal:8',
            'effective_at' => 'immutable_datetime',
            'provenance' => 'array',
        ];
    }
}
