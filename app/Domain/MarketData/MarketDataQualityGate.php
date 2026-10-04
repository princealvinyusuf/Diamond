<?php

namespace App\Domain\MarketData;

use DateTimeImmutable;
use InvalidArgumentException;

final class MarketDataQualityGate
{
    /** @return array{status: string, passed: bool, reasons: list<string>, metrics: array<string, int|float>} */
    public function evaluate(
        array $quote,
        array $candles,
        DateTimeImmutable $now,
        int $maxAgeSeconds,
        float $maxSpread,
        int $maxFutureSkewSeconds = 30,
    ): array
    {
        $reasons = [];
        foreach (['bid', 'ask', 'timestamp'] as $field) {
            if (!array_key_exists($field, $quote)) {
                $reasons[] = "QUOTE_".strtoupper($field)."_MISSING";
            }
        }
        if ($reasons !== []) {
            return ['status' => 'UNAVAILABLE', 'passed' => false, 'reasons' => $reasons, 'metrics' => []];
        }

        $bid = filter_var($quote['bid'], FILTER_VALIDATE_FLOAT);
        $ask = filter_var($quote['ask'], FILTER_VALIDATE_FLOAT);
        if ($bid === false || $ask === false || $bid <= 0 || $ask < $bid) {
            throw new InvalidArgumentException('Quote must contain positive bid <= ask.');
        }
        $quoteTimestamp = new DateTimeImmutable((string) $quote['timestamp']);
        $signedAge = $now->getTimestamp() - $quoteTimestamp->getTimestamp();
        $age = max(0, $signedAge);
        $spread = $ask - $bid;
        if ($signedAge < -$maxFutureSkewSeconds) {
            $reasons[] = 'QUOTE_TIMESTAMP_IN_FUTURE';
        }
        if ($age > $maxAgeSeconds) {
            $reasons[] = 'QUOTE_STALE';
        }
        if ($spread > $maxSpread) {
            $reasons[] = 'SPREAD_TOO_WIDE';
        }
        if ($candles === []) {
            $reasons[] = 'CANDLES_MISSING';
        }
        foreach ($candles as $index => $candle) {
            $open = (float) ($candle['open'] ?? 0);
            $high = (float) ($candle['high'] ?? 0);
            $low = (float) ($candle['low'] ?? 0);
            $close = (float) ($candle['close'] ?? 0);
            if ($open <= 0 || $low <= 0 || $high < max($open, $close) || $low > min($open, $close)) {
                $reasons[] = "INVALID_OHLC_{$index}";
            }
            if (isset($candle['openTime'])) {
                $openedAt = new DateTimeImmutable((string) $candle['openTime']);
                if ($openedAt->getTimestamp() > $now->getTimestamp() + $maxFutureSkewSeconds) {
                    $reasons[] = "CANDLE_TIMESTAMP_IN_FUTURE_{$index}";
                }
            }
        }

        $status = in_array('QUOTE_STALE', $reasons, true) ? 'STALE' : ($reasons === [] ? 'FRESH' : 'DEGRADED');
        return [
            'status' => $status,
            'passed' => $reasons === [],
            'reasons' => $reasons,
            'metrics' => ['ageSeconds' => $age, 'spread' => round($spread, 8)],
        ];
    }
}
