<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class RiskProfile extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'risk_per_trade_percent' => 'decimal:4',
            'daily_loss_cap_percent' => 'decimal:4',
            'minimum_rr' => 'decimal:4',
            'martingale_enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $profile): void {
            if ($profile->martingale_enabled) {
                throw new InvalidArgumentException('Martingale and recovery sizing are prohibited.');
            }
        });
    }

    public function tradingAccount(): BelongsTo
    {
        return $this->belongsTo(TradingAccount::class);
    }
}
