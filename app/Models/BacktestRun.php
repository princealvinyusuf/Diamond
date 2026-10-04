<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BacktestRun extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    public function getRouteKeyName(): string
    {
        return 'run_key';
    }

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'cost_model' => 'array',
            'partitions' => 'array',
            'walk_forward' => 'array',
            'results_summary' => 'array',
            'user_id' => 'integer',
            'seed' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function trades(): HasMany
    {
        return $this->hasMany(BacktestTrade::class);
    }
}
