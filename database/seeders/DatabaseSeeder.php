<?php

namespace Database\Seeders;

use App\Models\RiskProfile;
use App\Models\TradingAccount;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $account = TradingAccount::query()->create([
            'name' => 'Foundation paper account',
            'currency' => 'USD',
            'equity' => 1000,
            'mode' => 'PAPER',
            'equity_as_of' => now(),
        ]);

        RiskProfile::query()->create([
            'trading_account_id' => $account->id,
            'risk_per_trade_percent' => 0.5,
            'daily_loss_cap_percent' => 2,
            'max_trades_per_day' => 3,
            'loss_streak_limit' => 3,
            'minimum_rr' => 2,
            'daily_profit_target' => 10,
            'martingale_enabled' => false,
        ]);
    }
}
