<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

final class ApiDecisionSupportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $features = array_fill_keys(
            ['ema20', 'ema50', 'ema200', 'rsi14', 'macd', 'macdSignal', 'macdHistogram', 'atr14', 'adx14'],
            null,
        );
        Process::fake(['*' => Process::result(output: json_encode([
            'contractVersion' => '1.0.0', 'symbol' => 'XAUUSD', 'asOf' => '2026-10-04T00:00:00+00:00',
            'features' => ['D1' => $features, 'H4' => $features, 'H1' => $features],
            'structure' => ['labels' => [], 'zones' => []],
            'alignment' => [
                'biases' => ['D1' => 'NEUTRAL', 'H4' => 'NEUTRAL', 'H1' => 'NEUTRAL'],
                'aligned' => false, 'direction' => 'NEUTRAL',
            ],
            'regime' => 'RANGE', 'bias' => 'NEUTRAL',
            'setup' => [
                'direction' => null, 'family' => 'NONE', 'confirmed' => false,
                'evidence' => [
                    'alignment' => false, 'trend' => false, 'momentum' => false,
                    'structure' => false, 'breakout' => false, 'retest' => false,
                    'warmedUp' => false,
                ],
                'qualityScore' => 0,
            ],
        ], JSON_THROW_ON_ERROR))]);
    }

    public function test_fixture_market_and_research_endpoints_are_explicitly_mock(): void
    {
        $this->getJson('/api/v1/market/quote')
            ->assertOk()
            ->assertJsonPath('symbol', 'XAUUSD')
            ->assertJsonPath('isMock', true);

        $this->getJson('/api/v1/fundamentals')
            ->assertOk()
            ->assertJsonPath('isMock', true)
            ->assertJsonPath('aggregate.missing.0', 'inflation');
    }

    public function test_analysis_run_is_paper_only_and_requires_account_risk_review(): void
    {
        $this->postJson('/api/v1/analysis/run')
            ->assertOk()
            ->assertJsonPath('finalAction', 'NO_TRADE')
            ->assertJsonPath('executionMode', 'PAPER')
            ->assertJsonPath('isMock', true)
            ->assertJsonPath('riskPermission', 'BLOCKED')
            ->assertJsonPath('newsRisk', 'HIGH')
            ->assertJsonPath('blockReasons.0', 'ACCOUNT_RISK_REVIEW_REQUIRED')
            ->assertJsonPath('technicalAnalysis.bias', 'NEUTRAL');
    }

    public function test_risk_quote_sizes_only_and_does_not_create_an_order(): void
    {
        $this->postJson('/api/v1/risk/quote', [
            'equity' => 10000, 'riskPercent' => 1,
            'entry' => 2650, 'stopLoss' => 2640, 'target' => 2670,
        ])->assertOk()
            ->assertJsonPath('safeVolume', 0.1)
            ->assertJsonPath('executionMode', 'PAPER')
            ->assertJsonMissing(['orderId']);
    }
}
