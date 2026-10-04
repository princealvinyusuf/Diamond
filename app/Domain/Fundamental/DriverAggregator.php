<?php

namespace App\Domain\Fundamental;

final class DriverAggregator
{
    /**
     * Drivers use normalized scores from -100 (gold bearish) to 100 (gold bullish).
     * @return array{score: int|null, confidence: int, missing: list<string>, contributions: list<array<string, mixed>>, provenance: list<array<string, mixed>>}
     */
    public function aggregate(array $drivers, array $requiredKeys): array
    {
        $weighted = 0.0;
        $weight = 0.0;
        $present = [];
        $contributions = [];
        $provenance = [];
        foreach ($drivers as $driver) {
            if (!isset($driver['key'], $driver['score'], $driver['weight'], $driver['provenance'])) {
                continue;
            }
            $score = max(-100, min(100, (float) $driver['score']));
            $driverWeight = max(0, (float) $driver['weight']);
            $weighted += $score * $driverWeight;
            $weight += $driverWeight;
            $present[] = (string) $driver['key'];
            $contributions[] = ['key' => $driver['key'], 'score' => $score, 'weight' => $driverWeight];
            $provenance[] = $driver['provenance'];
        }
        $missing = array_values(array_diff($requiredKeys, $present));
        $coverage = $requiredKeys === [] ? 1.0 : (count($requiredKeys) - count($missing)) / count($requiredKeys);
        return [
            'score' => $weight > 0 ? (int) round($weighted / $weight) : null,
            'confidence' => (int) round(100 * max(0, min(1, $coverage))),
            'missing' => $missing,
            'contributions' => $contributions,
            'provenance' => $provenance,
        ];
    }
}
