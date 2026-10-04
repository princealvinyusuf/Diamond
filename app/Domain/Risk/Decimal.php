<?php

namespace App\Domain\Risk;

use InvalidArgumentException;

/**
 * Fixed-scale helpers used at broker boundaries.
 *
 * Calculations are rounded to eight decimal places and volume flooring is
 * performed with scaled integers, avoiding binary-float step drift.
 */
final class Decimal
{
    public const SCALE = 100_000_000;

    public static function value(int|float|string $value, string $field): float
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException("$field must be numeric.");
        }

        $number = (float) $value;

        if (! is_finite($number)) {
            throw new InvalidArgumentException("$field must be finite.");
        }

        return round($number, 8, PHP_ROUND_HALF_UP);
    }

    public static function multiply(float ...$values): float
    {
        return round(array_product($values), 8, PHP_ROUND_HALF_UP);
    }

    public static function divide(float $dividend, float $divisor): float
    {
        if ($divisor === 0.0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        return round($dividend / $divisor, 8, PHP_ROUND_HALF_UP);
    }

    public static function floorToStep(float $value, float $step): float
    {
        if ($step <= 0.0) {
            throw new InvalidArgumentException('Volume step must be greater than zero.');
        }

        $scaledValue = (int) round($value * self::SCALE, 0, PHP_ROUND_HALF_UP);
        $scaledStep = (int) round($step * self::SCALE, 0, PHP_ROUND_HALF_UP);

        if ($scaledStep < 1) {
            throw new InvalidArgumentException('Volume step is below supported precision.');
        }

        return round(intdiv($scaledValue, $scaledStep) * $scaledStep / self::SCALE, 8);
    }
}
