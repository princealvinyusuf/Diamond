<?php

namespace App\Domain\Fundamental;

interface FundamentalProvider
{
    /** @return list<array<string, mixed>> */
    public function observations(string $symbol): array;

    /** @return array<string, mixed> */
    public function health(): array;
}
