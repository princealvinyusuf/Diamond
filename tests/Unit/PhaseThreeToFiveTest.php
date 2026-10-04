<?php

namespace Tests\Unit;

use App\Domain\Decision\DecisionPipeline;
use App\Domain\Fundamental\DriverAggregator;
use App\Domain\Fundamental\EventWindowEvaluator;
use App\Domain\MarketData\MarketDataQualityGate;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PhaseThreeToFiveTest extends TestCase
{
    public function test_market_quality_reports_machine_readable_failures(): void
    {
        $result = (new MarketDataQualityGate)->evaluate([
            'bid' => 2650, 'ask' => 2652, 'timestamp' => '2026-10-04T00:00:00Z',
        ], [[
            'open' => 2650, 'high' => 2640, 'low' => 2630, 'close' => 2655,
        ]], new DateTimeImmutable('2026-10-04T00:10:00Z'), 300, 1.0);

        self::assertSame('STALE', $result['status']);
        self::assertSame(['QUOTE_STALE', 'SPREAD_TOO_WIDE', 'INVALID_OHLC_0'], $result['reasons']);
    }

    public function test_event_windows_are_deterministic(): void
    {
        $result = (new EventWindowEvaluator)->evaluate([[
            'id' => 'nfp', 'importance' => 'HIGH', 'status' => 'SCHEDULED',
            'scheduledAt' => '2026-10-04T11:00:00Z',
        ]], new DateTimeImmutable('2026-10-04T10:30:00Z'));

        self::assertSame('HIGH', $result['state']);
        self::assertTrue($result['blocking']);
    }

    public function test_driver_aggregation_preserves_missing_and_provenance(): void
    {
        $result = (new DriverAggregator)->aggregate([[
            'key' => 'real_yields', 'score' => 30, 'weight' => 2,
            'provenance' => ['source' => 'fixture'],
        ]], ['real_yields', 'usd']);

        self::assertSame(30, $result['score']);
        self::assertSame(50, $result['confidence']);
        self::assertSame(['usd'], $result['missing']);
        self::assertSame([['source' => 'fixture']], $result['provenance']);
    }

    public function test_pipeline_stops_in_order_and_never_executes(): void
    {
        $result = (new DecisionPipeline)->decide($this->validInput([
            'event' => ['blocking' => true, 'state' => 'HIGH', 'reasons' => ['EVENT_WINDOW_HIGH']],
        ]));

        self::assertSame('NO_TRADE', $result['finalAction']);
        self::assertSame(['data', 'risk', 'event'], array_column($result['pipelineTrace'], 'stage'));
        self::assertSame('PAPER', $result['executionMode']);
    }

    public function test_pipeline_returns_all_four_decision_states(): void
    {
        $pipeline = new DecisionPipeline;
        $buy = $pipeline->decide($this->validInput());
        self::assertSame('BUY', $buy['finalAction']);
        self::assertSame('VALID', $buy['tradeSetup']);
        self::assertSame('SELL', $pipeline->decide($this->validInput([
            'bias' => 'BEARISH', 'setup' => ['direction' => 'SELL', 'qualityScore' => 80, 'minimumScore' => 60, 'confirmed' => true],
        ]))['finalAction']);
        $waiting = $pipeline->decide($this->validInput([
            'setup' => ['direction' => 'BUY', 'qualityScore' => 80, 'minimumScore' => 60, 'confirmed' => false],
        ]));
        self::assertSame('WAIT_FOR_CONFIRMATION', $waiting['finalAction']);
        self::assertSame('ARMED', $waiting['tradeSetup']);
        self::assertSame('NO_TRADE', $pipeline->decide($this->validInput([
            'data' => ['quality' => 'STALE'],
        ]))['finalAction']);
    }

    private function validInput(array $overrides = []): array
    {
        return array_replace([
            'asOf' => '2026-10-04T00:00:00Z', 'isMock' => true,
            'data' => ['quality' => 'FRESH'],
            'risk' => ['allowed' => true, 'reasons' => []],
            'event' => ['blocking' => false, 'state' => 'LOW', 'reasons' => []],
            'regime' => 'TREND', 'bias' => 'BULLISH',
            'setup' => ['direction' => 'BUY', 'qualityScore' => 80, 'minimumScore' => 60, 'confirmed' => true],
            'economics' => ['netRiskReward' => 2.5, 'minimumRiskReward' => 2],
            'sizing' => ['safeVolume' => 0.05],
        ], $overrides);
    }
}
