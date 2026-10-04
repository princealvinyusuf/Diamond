<?php

namespace App\Domain\Risk;

use InvalidArgumentException;

final class BrokerTickMath
{
    public function ticksBetween(
        int|float|string $firstPrice,
        int|float|string $secondPrice,
        int|float|string $tickSize,
    ): float {
        $distance = abs(
            Decimal::value($firstPrice, 'firstPrice')
            - Decimal::value($secondPrice, 'secondPrice')
        );
        $size = Decimal::value($tickSize, 'tickSize');

        if ($size <= 0.0) {
            throw new InvalidArgumentException('tickSize must be greater than zero.');
        }

        return Decimal::divide($distance, $size);
    }

    public function grossValuePerLot(
        int|float|string $firstPrice,
        int|float|string $secondPrice,
        int|float|string $tickSize,
        int|float|string $tickValue,
    ): float {
        $value = Decimal::value($tickValue, 'tickValue');

        if ($value <= 0.0) {
            throw new InvalidArgumentException('tickValue must be greater than zero.');
        }

        return Decimal::multiply(
            $this->ticksBetween($firstPrice, $secondPrice, $tickSize),
            $value,
        );
    }
}
