<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BacktestContractTest extends TestCase
{
    public function test_backtest_contract_exposes_reproducibility_and_status_fields(): void
    {
        $schema = json_decode(
            file_get_contents(dirname(__DIR__, 2).'/contracts/v1/backtest.schema.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            ['QUEUED', 'RUNNING', 'COMPLETED', 'FAILED'],
            $schema['properties']['status']['enum'],
        );
        foreach (['engineVersion', 'strategyVersion', 'configurationHash', 'seed', 'partitions'] as $field) {
            self::assertContains($field, $schema['required']);
        }
    }
}
