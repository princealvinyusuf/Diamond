<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BacktestTrade extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'setup_snapshot' => 'array',
            'entered_at' => 'immutable_datetime',
            'exited_at' => 'immutable_datetime',
            'entry_price' => 'decimal:8',
            'exit_price' => 'decimal:8',
            'realized_pl' => 'decimal:8',
            'r_multiple' => 'decimal:6',
            'score' => 'integer',
            'bars_held' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BacktestRun::class, 'backtest_run_id');
    }
}
