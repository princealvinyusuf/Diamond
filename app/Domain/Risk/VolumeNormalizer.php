<?php

namespace App\Domain\Risk;

use InvalidArgumentException;

final class VolumeNormalizer
{
    /**
     * @return array{status: 'accepted'|'rejected', code: string, volume: float|null, capped: bool}
     */
    public function normalize(
        int|float|string $rawVolume,
        int|float|string $minimumVolume,
        int|float|string $maximumVolume,
        int|float|string $volumeStep,
    ): array {
        $raw = Decimal::value($rawVolume, 'rawVolume');
        $minimum = Decimal::value($minimumVolume, 'minimumVolume');
        $maximum = Decimal::value($maximumVolume, 'maximumVolume');
        $step = Decimal::value($volumeStep, 'volumeStep');

        if ($raw < 0.0 || $minimum <= 0.0 || $maximum < $minimum || $step <= 0.0) {
            throw new InvalidArgumentException('Invalid broker volume constraints.');
        }

        $capped = $raw > $maximum;
        $candidate = Decimal::floorToStep(min($raw, $maximum), $step);

        if ($candidate < $minimum) {
            return [
                'status' => 'rejected',
                'code' => 'BELOW_MINIMUM_VOLUME',
                'volume' => null,
                'capped' => false,
            ];
        }

        return [
            'status' => 'accepted',
            'code' => $capped ? 'CAPPED_AT_MAXIMUM_VOLUME' : 'VOLUME_ACCEPTED',
            'volume' => $candidate,
            'capped' => $capped,
        ];
    }
}
