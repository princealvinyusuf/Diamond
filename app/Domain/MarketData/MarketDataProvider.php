<?php

namespace App\Domain\MarketData;

interface MarketDataProvider
{
    /** @return array<string, mixed> */
    public function quote(string $symbol): array;

    /** @return list<array<string, mixed>> */
    public function candles(string $symbol, string $timeframe, int $limit = 250): array;

    /** @return array<string, mixed> Persistence-friendly health snapshot. */
    public function health(): array;
}
