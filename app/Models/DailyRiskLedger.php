<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DailyRiskLedger extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'session_date' => 'immutable_date', 'starting_equity' => 'decimal:8',
            'realized_pl' => 'decimal:8', 'open_risk' => 'decimal:8',
            'is_locked' => 'boolean', 'cooldown_until' => 'immutable_datetime',
        ];
    }

    public function account(): BelongsTo { return $this->belongsTo(TradingAccount::class, 'trading_account_id'); }
}
