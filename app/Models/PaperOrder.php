<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class PaperOrder extends Model
{
    protected $fillable = [
        'order_key', 'trading_account_id', 'trade_setup_id', 'order_type', 'side',
        'volume', 'requested_price', 'cost_assumptions', 'state', 'create_idempotency_key',
    ];

    protected $hidden = ['create_idempotency_key', 'confirm_idempotency_key', 'cancel_idempotency_key'];

    protected function casts(): array
    {
        return [
            'volume' => 'decimal:8', 'requested_price' => 'decimal:8',
            'filled_price' => 'decimal:8', 'quote_bid' => 'decimal:8', 'quote_ask' => 'decimal:8',
            'slippage_amount' => 'decimal:8', 'commission_amount' => 'decimal:8',
            'cost_assumptions' => 'array', 'filled_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function account(): BelongsTo { return $this->belongsTo(TradingAccount::class, 'trading_account_id'); }
    public function setup(): BelongsTo { return $this->belongsTo(TradeSetup::class, 'trade_setup_id'); }
    public function position(): HasOne { return $this->hasOne(Position::class); }
}
