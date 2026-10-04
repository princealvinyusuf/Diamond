<?php

namespace Tests\Feature;

use App\Models\TradingAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PerformanceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_performance_endpoint_is_paper_scoped_and_handles_empty_sample(): void
    {
        $account = TradingAccount::query()->create([
            'name' => 'Analytics paper account',
            'currency' => 'USD',
            'equity' => 10000,
            'mode' => 'PAPER',
        ]);

        $this->getJson("/api/v1/performance?accountId={$account->id}&strategyVersion=test-v1")
            ->assertOk()
            ->assertJsonPath('scope.executionMode', 'PAPER')
            ->assertJsonPath('scope.strategyVersion', 'test-v1')
            ->assertJsonPath('metrics.tradeCount', 0)
            ->assertJsonPath('metrics.netPl', 0)
            ->assertJsonPath('metrics.winRate', null)
            ->assertJsonStructure([
                'metrics' => [
                    'netReturn', 'expectancyR', 'profitFactor', 'maxDrawdown',
                    'averageWin', 'averageLoss', 'payoffRatio',
                    'maxConsecutiveLosses', 'exposure', 'breakdowns',
                ],
            ]);
    }
}
