<?php

namespace App\Http\Controllers;

use App\Models\AnalysisRun;
use App\Models\ProviderHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class HealthController
{
    public function __invoke(): JsonResponse
    {
        $now = now('UTC');
        $expectedBoundary = $now->copy()->startOfHour();
        $expectedBoundary->subHours($expectedBoundary->hour % 4);
        $graceMinutes = (int) config('diamond.operations.missed_h4_after_minutes', 20);
        $latest = Schema::hasTable('analysis_runs')
            ? AnalysisRun::query()->whereNotNull('schedule_key')->latest('as_of')->first()
            : null;
        $missed = $now->greaterThan($expectedBoundary->copy()->addMinutes($graceMinutes))
            && ($latest === null || $latest->as_of->lt($expectedBoundary));

        $providers = Schema::hasTable('provider_health')
            ? ProviderHealth::query()->orderBy('provider')->get()->map(function (ProviderHealth $health) use ($now): array {
                $stale = $health->last_success_at === null
                    || $health->last_success_at->lt($now->copy()->subMinutes(
                        (int) config('diamond.providers.stale_after_minutes', 10),
                    ));
                return [
                    'provider' => $health->provider,
                    'status' => ($health->is_stale || $stale) ? 'STALE' : 'HEALTHY',
                    'lastSuccessAt' => $health->last_success_at?->toISOString(),
                    'lastAttemptAt' => $health->last_attempt_at?->toISOString(),
                    'sourceAt' => $health->source_at?->toISOString(),
                    'latencyMs' => $health->latency_ms,
                    'consecutiveFailures' => $health->consecutive_failures,
                    'errorCode' => $health->error_code,
                ];
            })->all()
            : [];
        $providerStale = collect($providers)->contains(fn (array $provider) => $provider['status'] === 'STALE');

        $metrics = [
            'queuedJobs' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : null,
            'failedJobsLast24h' => Schema::hasTable('failed_jobs')
                ? DB::table('failed_jobs')->where('failed_at', '>=', $now->copy()->subDay())->count()
                : null,
            'latestScheduledAnalysisAt' => $latest?->as_of?->toISOString(),
            'missedH4Analysis' => $missed,
            'providerStale' => $providerStale,
        ];
        $status = ($missed || $providerStale || ($metrics['failedJobsLast24h'] ?? 0) > 0)
            ? 'DEGRADED'
            : 'OK';

        return response()->json([
            'status' => $status,
            'executionMode' => 'PAPER',
            'liveTradingSupported' => false,
            'time' => $now->toISOString(),
            'scheduler' => [
                'expectedBoundary' => $expectedBoundary->toISOString(),
                'graceMinutes' => $graceMinutes,
                'missed' => $missed,
            ],
            'providers' => $providers,
            'metrics' => $metrics,
        ], $status === 'OK' ? 200 : 503);
    }
}
