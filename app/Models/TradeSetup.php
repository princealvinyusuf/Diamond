<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class TradeSetup extends Model
{
    protected $fillable = [
        'analysis_run_id', 'direction', 'state', 'entry_low', 'entry_high',
        'stop_price', 'targets', 'quality_score', 'expires_at', 'reasons',
    ];

    protected function casts(): array
    {
        return [
            'entry_low' => 'decimal:8', 'entry_high' => 'decimal:8',
            'stop_price' => 'decimal:8', 'targets' => 'array', 'reasons' => 'array',
            'expires_at' => 'immutable_datetime', 'state_changed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function analysisRun(): BelongsTo { return $this->belongsTo(AnalysisRun::class); }
    public function paperOrders(): HasMany { return $this->hasMany(PaperOrder::class); }
}
