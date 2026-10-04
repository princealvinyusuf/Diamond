<?php

namespace App\Infrastructure\Fundamental;

use App\Domain\Fundamental\CalendarProvider;
use App\Domain\Fundamental\FundamentalProvider;
use DateTimeImmutable;

final class FixtureResearchProvider implements CalendarProvider, FundamentalProvider
{
    private array $fixture;

    public function __construct(?string $path = null)
    {
        $contents = file_get_contents($path ?? base_path('fixtures/mock-research.json'));
        $this->fixture = json_decode($contents ?: '', true, flags: JSON_THROW_ON_ERROR);
    }

    public function observations(string $symbol): array
    {
        return $symbol === 'XAUUSD' ? $this->fixture['fundamentals'] : [];
    }

    public function events(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return array_values(array_filter(
            $this->fixture['events'],
            static fn (array $event): bool => ($at = new DateTimeImmutable($event['scheduledAt'])) >= $from && $at <= $to,
        ));
    }

    public function health(): array
    {
        return [
            'provider' => 'fixture-research', 'latency_ms' => 0,
            'last_success_at' => '2026-10-04T00:00:00Z', 'is_stale' => false,
            'error_code' => null, 'error_message' => null, 'isMock' => true,
        ];
    }
}
