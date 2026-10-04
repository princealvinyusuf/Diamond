<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use InvalidArgumentException;

class TradingAccount extends Model
{
    protected $fillable = ['user_id', 'name', 'currency', 'equity', 'mode', 'equity_as_of'];

    protected function casts(): array
    {
        return [
            'equity' => 'decimal:8',
            'equity_as_of' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $account): void {
            if ($account->mode !== 'PAPER') {
                throw new InvalidArgumentException('Trading accounts must remain in PAPER mode.');
            }
        });
    }

    public function riskProfile(): HasOne
    {
        return $this->hasOne(RiskProfile::class);
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function paperOrders(): HasMany { return $this->hasMany(PaperOrder::class); }
    public function positions(): HasMany { return $this->hasMany(Position::class); }
    public function riskLedgers(): HasMany { return $this->hasMany(DailyRiskLedger::class); }
}
