<?php

namespace App\Models;

use LogicException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Trade extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];
    protected $hidden = ['close_idempotency_key'];

    protected function casts(): array
    {
        return [
            'opened_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime',
            'entry_price' => 'decimal:8', 'exit_price' => 'decimal:8',
            'initial_risk_amount' => 'decimal:8', 'realized_pl' => 'decimal:8',
            'r_multiple' => 'decimal:6', 'entry_snapshot' => 'array',
            'exit_bid' => 'decimal:8', 'exit_ask' => 'decimal:8',
            'gross_pl' => 'decimal:8', 'total_costs' => 'decimal:8',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $trade): void {
            if ($trade->isDirty(['entry_snapshot', 'entry_snapshot_hash', 'snapshot_version'])) {
                throw new LogicException('Entry snapshots are immutable; create a new versioned record instead.');
            }
        });
    }

    public function position(): BelongsTo { return $this->belongsTo(Position::class); }
}
