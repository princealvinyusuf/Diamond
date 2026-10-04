<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class AnalysisRun extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'as_of' => 'immutable_datetime', 'market_snapshot' => 'array',
            'fundamental_snapshot' => 'array', 'block_reasons' => 'array',
            'is_mock' => 'boolean',
        ];
    }

    public function tradeSetups(): HasMany { return $this->hasMany(TradeSetup::class); }
}
