<?php

namespace App\Domain\Fundamental;

use DateTimeImmutable;

interface CalendarProvider
{
    /** @return list<array<string, mixed>> */
    public function events(DateTimeImmutable $from, DateTimeImmutable $to): array;

    /** @return array<string, mixed> */
    public function health(): array;
}
