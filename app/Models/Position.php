<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Position extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'average_entry' => 'decimal:8', 'volume' => 'decimal:8',
            'stop_price' => 'decimal:8', 'targets' => 'array',
            'unrealized_pl' => 'decimal:8', 'initial_risk_amount' => 'decimal:8',
            'commission_paid' => 'decimal:8', 'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function account(): BelongsTo { return $this->belongsTo(TradingAccount::class, 'trading_account_id'); }
    public function order(): BelongsTo { return $this->belongsTo(PaperOrder::class, 'paper_order_id'); }
    public function trade(): HasOne { return $this->hasOne(Trade::class); }
}
