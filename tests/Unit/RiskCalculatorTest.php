<?php

namespace Tests\Unit;

use App\Domain\Risk\BrokerTickMath;
use App\Domain\Risk\NetRiskReward;
use App\Domain\Risk\RiskCalculator;
use App\Domain\Risk\RiskGateEvaluator;
use App\Domain\Risk\VolumeNormalizer;
use PHPUnit\Framework\TestCase;

final class RiskCalculatorTest extends TestCase
{
    private const BROKER = [
        'tickSize' => '0.01',
        'tickValue' => '1.00',
        'minimumVolume' => '0.01',
        'maximumVolume' => '1.00',
        'volumeStep' => '0.01',
    ];

    public function test_equity_risk_and_broker_tick_math_size_position(): void
    {
        $result = (new RiskCalculator)->calculate([
            'equity' => '10000.00',
            'riskPercent' => '1.00',
            'entry' => '2650.00',
            'stopLoss' => '2640.00',
            'target' => '2670.00',
        ], self::BROKER);

        self::assertSame('accepted', $result['status']);
        self::assertSame(100.0, $result['riskBudget']);
        self::assertSame(1000.0, $result['grossRiskPerLot']);
        self::assertSame(0.1, $result['safeVolume']);
        self::assertSame(100.0, $result['actualRisk']);
        self::assertSame(2.0, $result['netRiskReward']['ratio']);
    }

    public function test_volume_is_floored_to_broker_step(): void
    {
        $normalizer = new VolumeNormalizer;

        self::assertSame(0.12, $normalizer->normalize('0.12999999', '0.01', '1', '0.01')['volume']);
        self::assertSame(0.125, $normalizer->normalize('0.129', '0.005', '1', '0.005')['volume']);
    }

    public function test_position_is_rejected_when_risk_budget_cannot_fund_minimum_lot(): void
    {
        $result = (new RiskCalculator)->calculate([
            'equity' => '1000',
            'riskPercent' => '0.10',
            'entry' => '2650',
            'stopLoss' => '2640',
        ], self::BROKER);

        self::assertSame('rejected', $result['status']);
        self::assertSame('BELOW_MINIMUM_VOLUME', $result['code']);
        self::assertNull($result['safeVolume']);
        self::assertNull($result['actualRisk']);
    }

    public function test_maximum_volume_is_capped_and_reported(): void
    {
        $result = (new RiskCalculator)->calculate([
            'equity' => '100000',
            'riskPercent' => '2',
            'entry' => '2650',
            'stopLoss' => '2640',
        ], [...self::BROKER, 'maximumVolume' => '0.50']);

        self::assertSame(0.5, $result['safeVolume']);
        self::assertTrue($result['isMaximumVolumeCapped']);
        self::assertSame('CAPPED_AT_MAXIMUM_VOLUME', $result['code']);
    }

    public function test_round_trip_costs_reduce_safe_volume_and_net_reward(): void
    {
        $result = (new RiskCalculator)->calculate([
            'equity' => '10000',
            'riskPercent' => '1',
            'entry' => '2650',
            'stopLoss' => '2640',
            'target' => '2670',
        ], self::BROKER, [
            'spreadPerLot' => '4',
            'commissionPerLot' => '4',
            'slippagePerLot' => '2',
        ]);

        self::assertSame(10.0, $result['costPerLot']);
        self::assertSame(1010.0, $result['netRiskPerLot']);
        self::assertSame(0.09, $result['safeVolume']);
        self::assertSame(1990.0, $result['netRiskReward']['netRewardPerLot']);
    }

    public function test_target_does_not_affect_position_sizing(): void
    {
        $calculator = new RiskCalculator;
        $position = [
            'equity' => '10000',
            'riskPercent' => '0.5',
            'entry' => '2650',
            'stopLoss' => '2642',
        ];

        $nearTarget = $calculator->calculate([...$position, 'target' => '2654'], self::BROKER);
        $farTarget = $calculator->calculate([...$position, 'target' => '2682'], self::BROKER);
        $noTarget = $calculator->calculate($position, self::BROKER);

        self::assertSame($nearTarget['rawVolume'], $farTarget['rawVolume']);
        self::assertSame($nearTarget['safeVolume'], $farTarget['safeVolume']);
        self::assertSame($nearTarget['safeVolume'], $noTarget['safeVolume']);
        self::assertNotSame(
            $nearTarget['netRiskReward']['ratio'],
            $farTarget['netRiskReward']['ratio'],
        );
    }

    public function test_tick_math_and_net_rr_are_direction_agnostic(): void
    {
        $ticks = new BrokerTickMath;
        $riskReward = new NetRiskReward;

        self::assertSame(25.0, $ticks->ticksBetween('2650.25', '2650.00', '0.01'));
        self::assertSame(
            2.3333,
            $riskReward->calculate('100', '90', '130', '1', '1', '2')['ratio'],
        );
    }

    public function test_daily_loss_trade_count_and_cooldown_gates_are_machine_readable(): void
    {
        $result = (new RiskGateEvaluator)->evaluate([
            'equity' => '10000',
            'realizedDailyPnl' => '-200',
            'tradesToday' => 3,
            'lastTradeAt' => '2026-10-04T10:00:00+00:00',
            'evaluatedAt' => '2026-10-04T10:15:00+00:00',
        ], [
            'maxDailyLossPercent' => '2',
            'maxTradesPerDay' => 3,
            'cooldownMinutes' => 30,
        ]);

        self::assertFalse($result['allowed']);
        self::assertSame('blocked', $result['status']);
        self::assertSame([
            'DAILY_LOSS_LIMIT_REACHED',
            'DAILY_TRADE_LIMIT_REACHED',
            'TRADE_COOLDOWN_ACTIVE',
        ], array_column($result['blockers'], 'code'));
        self::assertSame(900, $result['blockers'][2]['context']['remainingSeconds']);
    }

    public function test_gates_allow_when_all_limits_have_headroom(): void
    {
        $result = (new RiskGateEvaluator)->evaluate([
            'equity' => '10000',
            'realizedDailyPnl' => '-50',
            'tradesToday' => 1,
            'lastTradeAt' => '2026-10-04T09:00:00+00:00',
            'evaluatedAt' => '2026-10-04T10:00:00+00:00',
        ], [
            'maxDailyLossPercent' => '2',
            'maxTradesPerDay' => 3,
            'cooldownMinutes' => 30,
        ]);

        self::assertTrue($result['allowed']);
        self::assertSame([], $result['blockers']);
    }
}
