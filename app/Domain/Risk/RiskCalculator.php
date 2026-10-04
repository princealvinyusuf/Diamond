<?php

namespace App\Domain\Risk;

use InvalidArgumentException;

final class RiskCalculator
{
    public function __construct(
        private readonly BrokerTickMath $tickMath = new BrokerTickMath,
        private readonly TradingCost $tradingCost = new TradingCost,
        private readonly VolumeNormalizer $volumeNormalizer = new VolumeNormalizer,
        private readonly NetRiskReward $riskReward = new NetRiskReward,
    ) {
    }

    /**
     * Deterministic position sizing for paper decision support.
     *
     * @param array{equity: numeric-string|int|float, riskPercent: numeric-string|int|float, entry: numeric-string|int|float, stopLoss: numeric-string|int|float, target?: numeric-string|int|float|null} $position
     * @param array{tickSize: numeric-string|int|float, tickValue: numeric-string|int|float, minimumVolume: numeric-string|int|float, maximumVolume: numeric-string|int|float, volumeStep: numeric-string|int|float} $broker
     * @param array{spreadPerLot?: numeric-string|int|float, commissionPerLot?: numeric-string|int|float, slippagePerLot?: numeric-string|int|float} $costs
     * @return array<string, mixed>
     */
    public function calculate(array $position, array $broker, array $costs = []): array
    {
        $equity = Decimal::value($position['equity'], 'equity');
        $riskPercent = Decimal::value($position['riskPercent'], 'riskPercent');
        $entry = Decimal::value($position['entry'], 'entry');
        $stopLoss = Decimal::value($position['stopLoss'], 'stopLoss');

        if ($equity <= 0.0 || $riskPercent <= 0.0 || $riskPercent > 100.0) {
            throw new InvalidArgumentException('Equity must be positive and riskPercent must be in (0, 100].');
        }

        if ($entry === $stopLoss) {
            throw new InvalidArgumentException('Entry and stop loss must differ.');
        }

        $costPerLot = $this->tradingCost->roundTripPerLot(
            $costs['spreadPerLot'] ?? 0,
            $costs['commissionPerLot'] ?? 0,
            $costs['slippagePerLot'] ?? 0,
        );
        $riskBudget = Decimal::multiply($equity, Decimal::divide($riskPercent, 100.0));
        $grossRiskPerLot = $this->tickMath->grossValuePerLot(
            $entry,
            $stopLoss,
            $broker['tickSize'],
            $broker['tickValue'],
        );
        $netRiskPerLot = round($grossRiskPerLot + $costPerLot, 8, PHP_ROUND_HALF_UP);
        $rawVolume = Decimal::divide($riskBudget, $netRiskPerLot);
        $normalized = $this->volumeNormalizer->normalize(
            $rawVolume,
            $broker['minimumVolume'],
            $broker['maximumVolume'],
            $broker['volumeStep'],
        );
        $riskReward = $this->riskReward->calculate(
            $entry,
            $stopLoss,
            $position['target'] ?? null,
            $broker['tickSize'],
            $broker['tickValue'],
            $costPerLot,
        );

        $safeVolume = $normalized['volume'];
        $actualRisk = $safeVolume === null
            ? null
            : Decimal::multiply($safeVolume, $netRiskPerLot);

        return [
            'status' => $normalized['status'],
            'code' => $normalized['code'],
            'safeVolume' => $safeVolume,
            'isMaximumVolumeCapped' => $normalized['capped'],
            'riskBudget' => $riskBudget,
            'actualRisk' => $actualRisk,
            'actualRiskPercent' => $actualRisk === null
                ? null
                : round(($actualRisk / $equity) * 100, 6, PHP_ROUND_HALF_UP),
            'rawVolume' => $rawVolume,
            'grossRiskPerLot' => $grossRiskPerLot,
            'costPerLot' => $costPerLot,
            'netRiskPerLot' => $netRiskPerLot,
            'netRiskReward' => $riskReward,
            'calculationScale' => 8,
        ];
    }
}
