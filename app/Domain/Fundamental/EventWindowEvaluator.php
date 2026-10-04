<?php

namespace App\Domain\Fundamental;

use DateTimeImmutable;

final class EventWindowEvaluator
{
    /** @return array{state: string, blocking: bool, eventIds: list<string>, reasons: list<string>} */
    public function evaluate(array $events, DateTimeImmutable $at, int $beforeMinutes = 60, int $afterMinutes = 30): array
    {
        $state = 'LOW';
        $ids = [];
        foreach ($events as $event) {
            if (($event['status'] ?? 'SCHEDULED') === 'CANCELLED') {
                continue;
            }
            $scheduled = new DateTimeImmutable((string) $event['scheduledAt']);
            $delta = $scheduled->getTimestamp() - $at->getTimestamp();
            $importance = $event['importance'] ?? 'LOW';
            if ($delta <= $beforeMinutes * 60 && $delta >= -$afterMinutes * 60 && in_array($importance, ['HIGH', 'VERY_HIGH'], true)) {
                $state = 'HIGH';
                $ids[] = (string) $event['id'];
            } elseif ($state !== 'HIGH' && $delta < -$afterMinutes * 60 && $delta >= -($afterMinutes + 60) * 60 && in_array($importance, ['HIGH', 'VERY_HIGH'], true)) {
                $state = 'POST_EVENT_STABILIZATION';
                $ids[] = (string) $event['id'];
            } elseif ($state === 'LOW' && abs($delta) <= $beforeMinutes * 120 && $importance === 'MEDIUM') {
                $state = 'ELEVATED';
                $ids[] = (string) $event['id'];
            }
        }
        $blocking = in_array($state, ['HIGH', 'POST_EVENT_STABILIZATION'], true);
        return [
            'state' => $state,
            'blocking' => $blocking,
            'eventIds' => $ids,
            'reasons' => $blocking ? ["EVENT_WINDOW_{$state}"] : [],
        ];
    }
}
