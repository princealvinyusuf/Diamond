<?php

namespace App\Domain\Risk;

use InvalidArgumentException;

final class TradingCost
{
    /**
     * Returns total round-trip monetary cost for one lot.
     *
     * All inputs are account-currency amounts per lot, not price distances.
     */
    public function roundTripPerLot(
        int|float|string $spread = 0,
        int|float|string $commission = 0,
        int|float|string $slippage = 0,
    ): float {
        $parts = [
            Decimal::value($spread, 'spreadCostPerLot'),
            Decimal::value($commission, 'commissionPerLot'),
            Decimal::value($slippage, 'slippageCostPerLot'),
        ];

        if (min($parts) < 0.0) {
            throw new InvalidArgumentException('Trading costs cannot be negative.');
        }

        return round(array_sum($parts), 8, PHP_ROUND_HALF_UP);
    }
}
