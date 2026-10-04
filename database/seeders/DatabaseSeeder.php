<?php

namespace Database\Seeders;

use App\Models\BrokerSymbolSpec;
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

        $account = TradingAccount::query()->updateOrCreate(['name' => 'Foundation paper account'], [
            'name' => 'Foundation paper account',
            'currency' => 'USD',
            'equity' => 10000,
            'mode' => 'PAPER',
            'equity_as_of' => now(),
        ]);

        RiskProfile::query()->updateOrCreate(['trading_account_id' => $account->id], [
            'risk_per_trade_percent' => 0.5,
            'daily_loss_cap_percent' => 2,
            'max_trades_per_day' => 3,
            'loss_streak_limit' => 3,
            'loss_cooldown_minutes' => 1440,
            'minimum_rr' => 2,
            'daily_profit_target' => 10,
            'martingale_enabled' => false,
        ]);

        BrokerSymbolSpec::query()->updateOrCreate([
            'provider' => 'manual',
            'symbol' => 'XAUUSD',
        ], [
            'currency' => 'USD',
            'contract_size' => 100,
            'tick_size' => 0.01,
            'tick_value' => 1,
            'volume_min' => 0.01,
            'volume_max' => 100,
            'volume_step' => 0.01,
            'effective_at' => now(),
            'provenance' => ['source' => 'local-seed', 'verified' => false],
        ]);
    }
}
