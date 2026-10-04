<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class User extends Authenticatable
{
    protected $fillable = ['name', 'timezone'];
    protected function casts(): array { return ['preferences' => 'array']; }
    public function tradingAccounts(): HasMany { return $this->hasMany(TradingAccount::class); }
}
