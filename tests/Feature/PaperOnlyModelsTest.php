<?php

namespace Tests\Feature;

use App\Models\RiskProfile;
use App\Models\TradingAccount;
use InvalidArgumentException;
use Tests\TestCase;

final class PaperOnlyModelsTest extends TestCase
{
    public function test_trading_account_rejects_live_mode_before_persistence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PAPER mode');

        (new TradingAccount([
            'name' => 'Unsafe account',
            'currency' => 'USD',
            'equity' => 1000,
            'mode' => 'LIVE',
        ]))->save();
    }

    public function test_risk_profile_rejects_martingale_before_persistence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('prohibited');

        (new RiskProfile([
            'trading_account_id' => 1,
            'martingale_enabled' => true,
        ]))->save();
    }
}
