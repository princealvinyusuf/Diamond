<?php

namespace App\Infrastructure\MarketData;

use App\Domain\MarketData\MarketDataProvider;
use App\Infrastructure\ProviderHealthRecorder;
use RuntimeException;

final class MockMarketDataProvider implements MarketDataProvider
{
    private array $fixture;

    public function __construct(?string $path = null, private readonly ?ProviderHealthRecorder $healthRecorder = null)
    {
        $contents = file_get_contents($path ?? base_path('fixtures/mock-market-data.json'));
        $this->fixture = json_decode($contents ?: '', true, flags: JSON_THROW_ON_ERROR);
    }

    public function quote(string $symbol): array
    {
        $this->guardSymbol($symbol);
        $this->healthRecorder?->success('fixture', 0, $this->fixture['quote']['timestamp']);
        return $this->fixture['quote'];
    }

    public function candles(string $symbol, string $timeframe, int $limit = 250): array
    {
        $this->guardSymbol($symbol);
        if (!in_array($timeframe, ['H1', 'H4', 'D1'], true)) {
            throw new RuntimeException('Unsupported timeframe.');
        }
        $this->healthRecorder?->success('fixture', 0, $this->fixture['quote']['timestamp']);
        return array_map(
            static fn (array $candle): array => [
                'provider' => 'fixture', 'symbol' => 'XAUUSD', 'timeframe' => $timeframe, ...$candle,
            ],
            array_slice($this->fixture['candles'][$timeframe] ?? [], -max(1, min($limit, 500))),
        );
    }

    public function health(): array
    {
        return [
            'provider' => 'fixture',
            'latency_ms' => 0,
            'last_success_at' => $this->fixture['quote']['timestamp'],
            'is_stale' => false,
            'error_code' => null,
            'error_message' => null,
            'isMock' => true,
        ];
    }

    private function guardSymbol(string $symbol): void
    {
        if ($symbol !== 'XAUUSD') {
            throw new RuntimeException('Only XAUUSD is supported.');
        }
    }
}
