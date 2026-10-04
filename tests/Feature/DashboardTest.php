<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_loads_seeded_paper_account_and_settings(): void
    {
        $this->seed();

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('decision.executionMode', 'PAPER')
                ->where('decision.finalAction', 'NO_TRADE')
                ->has('dashboard.chart.candles', 16)
                ->where('dashboard.account.name', 'Foundation paper account')
                ->where('dashboard.account.equity', '$10,000.00')
                ->where('settings.account.mode', 'PAPER')
                ->where('settings.risk.martingaleEnabled', false)
                ->where('settings.symbol.symbol', 'XAUUSD')
                ->where('dashboard.analytics.status', 'COMPLETED · FIXTURE')
                ->has('dashboard.analytics.equityCurve', 16)
                ->has('dashboard.analytics.metrics', 4));
    }
}
