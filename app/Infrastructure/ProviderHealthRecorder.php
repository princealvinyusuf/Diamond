<?php

namespace App\Infrastructure;

use App\Models\ProviderHealth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Throwable;

final class ProviderHealthRecorder
{
    public function success(string $provider, int $latencyMs, ?string $sourceAt = null): void
    {
        $source = $sourceAt === null ? null : Carbon::parse($sourceAt);
        $sourceIsStale = $source !== null && (
            $source->lt(now()->subMinutes((int) config('diamond.providers.stale_after_minutes', 10)))
            || $source->gt(now()->addSeconds((int) config('diamond.quality.max_future_skew_seconds', 30)))
        );
        $this->persist($provider, [
            'latency_ms' => $latencyMs,
            'last_success_at' => now(),
            'last_attempt_at' => now(),
            'source_at' => $source,
            'is_stale' => $sourceIsStale,
            'consecutive_failures' => 0,
            'error_code' => null,
            'error_message' => null,
        ], ['source_at' => $sourceAt]);
    }

    public function failure(string $provider, int $latencyMs, Throwable $error): void
    {
        try {
            if (! Schema::hasTable('provider_health')) {
                return;
            }
            $health = ProviderHealth::query()->firstOrNew(['provider' => $provider]);
            $health->fill([
                'latency_ms' => $latencyMs,
                'last_attempt_at' => now(),
                'last_failure_at' => now(),
                'is_stale' => true,
                'consecutive_failures' => ((int) $health->consecutive_failures) + 1,
                'error_code' => 'PROVIDER_ERROR',
                'error_message' => mb_substr($error->getMessage(), 0, 1000),
            ])->save();
            Log::warning('provider.request_failed', [
                'provider' => $provider,
                'latency_ms' => $latencyMs,
                'consecutive_failures' => $health->consecutive_failures,
                'exception' => $error::class,
            ]);
        } catch (Throwable $persistenceError) {
            Log::error('provider.health_persistence_failed', [
                'provider' => $provider,
                'exception' => $persistenceError::class,
            ]);
        }
    }

    private function persist(string $provider, array $values, array $context = []): void
    {
        try {
            if (! Schema::hasTable('provider_health')) {
                return;
            }
            ProviderHealth::query()->updateOrCreate(['provider' => $provider], $values);
            Log::info('provider.request_succeeded', ['provider' => $provider, ...$values, ...$context]);
        } catch (Throwable $error) {
            Log::error('provider.health_persistence_failed', [
                'provider' => $provider,
                'exception' => $error::class,
            ]);
        }
    }
}
