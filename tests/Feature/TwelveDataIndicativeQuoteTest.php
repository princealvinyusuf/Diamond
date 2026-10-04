<?php

namespace Tests\Feature;

use App\Domain\MarketData\MarketDataQualityGate;
use App\Infrastructure\MarketData\TwelveDataMarketDataProvider;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class TwelveDataIndicativeQuoteTest extends TestCase
{
    public function test_free_tier_price_is_labeled_indicative_and_never_fabricates_bid_ask(): void
    {
        Http::fake([
            'https://api.test/quote*' => Http::response([
                'symbol' => 'XAU/USD',
                'close' => '2650.25',
                'timestamp' => 1791072000,
            ]),
        ]);
        $quote = (new TwelveDataMarketDataProvider('test-key', 'https://api.test'))->quote('XAUUSD');

        self::assertTrue($quote['indicative']);
        self::assertFalse($quote['hasExecutableSpread']);
        self::assertSame(2650.25, $quote['mid']);
        self::assertArrayNotHasKey('bid', $quote);
        self::assertArrayNotHasKey('ask', $quote);

        $quality = (new MarketDataQualityGate)->evaluate(
            $quote,
            [['open' => 2640, 'high' => 2660, 'low' => 2630, 'close' => 2650]],
            new DateTimeImmutable($quote['timestamp']),
            300,
            1,
        );
        self::assertSame('DEGRADED', $quality['status']);
        self::assertFalse($quality['passed']);
        self::assertContains('INDICATIVE_MID_NO_EXECUTABLE_SPREAD', $quality['reasons']);
    }
}
