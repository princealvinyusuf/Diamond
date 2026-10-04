<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DecisionContractTest extends TestCase
{
    public function test_mock_fixture_matches_foundation_contract_invariants(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/../../fixtures/mock-dashboard.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame('1.0.0', $fixture['contractVersion']);
        self::assertSame('XAUUSD', $fixture['symbol']);
        self::assertSame('PAPER', $fixture['executionMode']);
        self::assertSame('NO_TRADE', $fixture['finalAction']);
        self::assertSame('BLOCKED', $fixture['riskPermission']);
        self::assertTrue($fixture['isMock']);
        self::assertNotEmpty($fixture['blockReasons']);
        self::assertNull($fixture['entry']);
        self::assertNull($fixture['safeVolume']);
    }

    public function test_json_contracts_are_valid_json(): void
    {
        foreach (glob(__DIR__.'/../../contracts/v1/*.json') ?: [] as $contract) {
            json_decode(file_get_contents($contract), true, flags: JSON_THROW_ON_ERROR);
            self::addToAssertionCount(1);
        }
    }
}
