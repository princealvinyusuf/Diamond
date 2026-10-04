<?php

namespace Tests\Feature;

use Tests\TestCase;

final class DashboardTest extends TestCase
{
    public function test_dashboard_is_paper_only_and_mock_labeled(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('decision.executionMode', 'PAPER')
                ->where('decision.isMock', true)
                ->where('decision.finalAction', 'NO_TRADE')
                ->has('decision.blockReasons', 2)
                ->where('dashboard.quote.sourceLabel', 'STATIC FIXTURE · NOT LIVE')
                ->has('dashboard.chart.candles', 16)
                ->where('dataState.mode', 'FIXTURE')
                ->has('dataState.candles', 0)
                ->where('dashboard.account.name', 'Paper account')
                ->where('dashboard.analytics.status', 'COMPLETED · FIXTURE')
                ->has('dashboard.analytics.equityCurve', 16)
                ->has('dashboard.analytics.metrics', 4));
    }
}
