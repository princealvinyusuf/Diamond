<?php

namespace App\Domain\Risk;

final class NetRiskReward
{
    public function __construct(private readonly BrokerTickMath $tickMath = new BrokerTickMath)
    {
    }

    /**
     * @return array{status: 'available'|'unavailable', code: string, ratio: float|null, netRiskPerLot: float, netRewardPerLot: float|null}
     */
    public function calculate(
        int|float|string $entry,
        int|float|string $stopLoss,
        int|float|string|null $target,
        int|float|string $tickSize,
        int|float|string $tickValue,
        int|float|string $roundTripCostPerLot = 0,
    ): array {
        $cost = Decimal::value($roundTripCostPerLot, 'roundTripCostPerLot');
        $risk = round(
            $this->tickMath->grossValuePerLot($entry, $stopLoss, $tickSize, $tickValue) + $cost,
            8,
            PHP_ROUND_HALF_UP,
        );

        if ($target === null) {
            return [
                'status' => 'unavailable',
                'code' => 'TARGET_NOT_PROVIDED',
                'ratio' => null,
                'netRiskPerLot' => $risk,
                'netRewardPerLot' => null,
            ];
        }

        $reward = round(
            $this->tickMath->grossValuePerLot($entry, $target, $tickSize, $tickValue) - $cost,
            8,
            PHP_ROUND_HALF_UP,
        );

        if ($risk <= 0.0 || $reward <= 0.0) {
            return [
                'status' => 'unavailable',
                'code' => 'NON_POSITIVE_NET_REWARD',
                'ratio' => null,
                'netRiskPerLot' => $risk,
                'netRewardPerLot' => $reward,
            ];
        }

        return [
            'status' => 'available',
            'code' => 'NET_RR_AVAILABLE',
            'ratio' => round($reward / $risk, 4, PHP_ROUND_HALF_UP),
            'netRiskPerLot' => $risk,
            'netRewardPerLot' => $reward,
        ];
    }
}
