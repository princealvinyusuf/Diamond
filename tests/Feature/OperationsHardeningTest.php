<?php

namespace Tests\Feature;

use App\Jobs\RunScheduledH4Analysis;
use App\Models\AnalysisRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

final class OperationsHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_analysis_api_is_rate_limited_and_has_security_headers(): void
    {
        RateLimiter::clear('203.0.113.9');
        for ($request = 1; $request <= 12; $request++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
                ->getJson('/api/v1/market/quote')
                ->assertOk()
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'DENY');
        }
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->getJson('/api/v1/market/quote')
            ->assertStatus(429);
    }

    public function test_scheduled_h4_replay_is_idempotent(): void
    {
        $job = new RunScheduledH4Analysis('2026-10-04T00:00:00+00:00');
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);

        self::assertSame(1, AnalysisRun::query()
            ->where('schedule_key', 'XAUUSD:H4:2026-10-04T00:00:00+00:00')
            ->count());
        self::assertDatabaseHas('analysis_runs', [
            'final_action' => 'NO_TRADE',
            'is_mock' => true,
        ]);
    }

    public function test_health_endpoint_exposes_paper_only_operational_status(): void
    {
        $response = $this->getJson('/api/v1/health');
        self::assertContains($response->status(), [200, 503]);
        $response->assertJsonPath('executionMode', 'PAPER')
            ->assertJsonPath('liveTradingSupported', false)
            ->assertJsonStructure([
                'status',
                'scheduler' => ['expectedBoundary', 'graceMinutes', 'missed'],
                'metrics' => ['queuedJobs', 'failedJobsLast24h', 'missedH4Analysis', 'providerStale'],
            ]);
    }

    public function test_persistent_mutations_fail_closed_without_owner_outside_local(): void
    {
        app()->detectEnvironment(fn (): string => 'production');
        try {
            $this->withSession(['_token' => 'test-csrf'])
                ->withHeader('X-CSRF-TOKEN', 'test-csrf')
                ->postJson('/api/v1/backtests', [])->assertForbidden();
            $this->withSession(['_token' => 'test-csrf'])
                ->withHeader('X-CSRF-TOKEN', 'test-csrf')
                ->postJson('/api/v1/paper/orders', [])->assertForbidden();
        } finally {
            app()->detectEnvironment(fn (): string => 'testing');
        }
    }
}
