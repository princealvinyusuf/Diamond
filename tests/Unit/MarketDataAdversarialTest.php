<?php

namespace Tests\Unit;

use App\Domain\MarketData\MarketDataQualityGate;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MarketDataAdversarialTest extends TestCase
{
    public function test_future_quote_and_candle_timestamps_are_rejected(): void
    {
        $result = (new MarketDataQualityGate)->evaluate(
            ['bid' => 2649.8, 'ask' => 2650.2, 'timestamp' => '2026-10-04T00:10:00Z'],
            [[
                'openTime' => '2026-10-04T04:00:00Z',
                'open' => 2640, 'high' => 2660, 'low' => 2630, 'close' => 2650,
            ]],
            new DateTimeImmutable('2026-10-04T00:00:00Z'),
            300,
            1.0,
            30,
        );

        self::assertFalse($result['passed']);
        self::assertContains('QUOTE_TIMESTAMP_IN_FUTURE', $result['reasons']);
        self::assertContains('CANDLE_TIMESTAMP_IN_FUTURE_0', $result['reasons']);
    }
}
