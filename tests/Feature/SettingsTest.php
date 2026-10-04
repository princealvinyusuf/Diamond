<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BrokerSymbolSpec;
use App\Models\TradingAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_local_paper_settings_are_saved_transactionally_and_audited(): void
    {
        $this->putJson('/api/v1/settings', [
            'account' => ['name' => 'Conservative paper', 'equity' => 25000],
            'risk' => [
                'riskPerTradePercent' => 0.5,
                'dailyLossCapPercent' => 1.5,
                'maxTradesPerDay' => 4,
                'lossCooldownMinutes' => 720,
                'minimumRiskReward' => 2.5,
                'dailyProfitTarget' => 250,
            ],
            'symbol' => [
                'symbol' => 'XAUUSD',
                'tickSize' => 0.01,
                'tickValue' => 1,
                'contractSize' => 100,
                'minimumVolume' => 0.01,
                'maximumVolume' => 50,
                'volumeStep' => 0.01,
            ],
        ])->assertOk()
            ->assertJsonPath('settings.account.mode', 'PAPER')
            ->assertJsonPath('settings.risk.martingaleEnabled', false)
            ->assertJsonPath('settings.symbol.provenance', 'manual-unverified');

        $account = TradingAccount::query()->with('riskProfile')->firstOrFail();
        self::assertSame('Conservative paper', $account->name);
        self::assertSame('0.5000', $account->riskProfile->risk_per_trade_percent);
        self::assertSame(720, $account->riskProfile->loss_cooldown_minutes);
        self::assertSame('50.00000000', BrokerSymbolSpec::query()->firstOrFail()->volume_max);
        self::assertTrue(AuditLog::query()->where('action', 'dashboard_settings.updated')->exists());
    }

    public function test_conservative_bounds_and_martingale_omission_are_enforced(): void
    {
        $this->putJson('/api/v1/settings', [
            'account' => ['name' => 'Risky', 'equity' => 10000],
            'risk' => [
                'riskPerTradePercent' => 5,
                'dailyLossCapPercent' => 1,
                'maxTradesPerDay' => 50,
                'lossCooldownMinutes' => 0,
                'minimumRiskReward' => 1,
                'dailyProfitTarget' => null,
                'martingaleEnabled' => true,
            ],
            'symbol' => [
                'symbol' => 'XAUUSD', 'tickSize' => 0.01, 'tickValue' => 1,
                'contractSize' => 100, 'minimumVolume' => 0.01,
                'maximumVolume' => 1, 'volumeStep' => 0.01,
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'risk.riskPerTradePercent',
                'risk.maxTradesPerDay',
                'risk.lossCooldownMinutes',
                'risk.minimumRiskReward',
                'risk.martingaleEnabled',
            ]);
    }
}
