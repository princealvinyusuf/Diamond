<?php

namespace App\Jobs;

use App\Domain\Decision\AnalysisService;
use App\Models\AnalysisRun;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class RunScheduledH4Analysis implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 90;
    public int $uniqueFor = 14400;

    public function __construct(public readonly string $scheduledAt)
    {
        $this->onQueue('analysis');
    }

    public function uniqueId(): string
    {
        return 'XAUUSD:H4:'.$this->scheduledAt;
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(AnalysisService $analysis): void
    {
        $asOf = new DateTimeImmutable($this->scheduledAt);
        $evaluatedAt = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $scheduleKey = $this->uniqueId();
        if (AnalysisRun::query()->where('schedule_key', $scheduleKey)->exists()) {
            Log::info('analysis.schedule_duplicate_skipped', ['schedule_key' => $scheduleKey]);
            return;
        }

        $result = $analysis->run(false, ['SCHEDULED_ANALYSIS_REQUIRES_MANUAL_RISK_REVIEW']);
        $quote = $result['quote'];
        $candles = $result['candles'];
        $quality = $result['quality'];
        $events = $result['events'];
        $isMock = (bool) ($quote['isMock'] ?? true);
        $decision = $result['decision'];

        AnalysisRun::query()->firstOrCreate(['schedule_key' => $scheduleKey], [
            'run_key' => (string) Str::uuid(),
            'symbol' => 'XAUUSD',
            'timeframe' => 'H4',
            'strategy_version' => $decision['strategyVersion'],
            'configuration_hash' => $decision['configurationHash'],
            'as_of' => $asOf,
            'market_bias' => $decision['marketBias'],
            'technical_score' => $decision['technicalScore'],
            'fundamental_score' => $decision['fundamentalScore'],
            'event_state' => $decision['newsRisk'],
            'final_action' => $decision['finalAction'],
            'market_snapshot' => [
                'quote' => $quote,
                'candles' => $candles,
                'quality' => $quality,
                'provider_health' => $result['providerHealth'],
                'technical_analysis' => $result['technical'],
                'evaluated_at' => $evaluatedAt->format(DATE_ATOM),
            ],
            'fundamental_snapshot' => ['events' => $events],
            'block_reasons' => $decision['blockReasons'],
            'is_mock' => $isMock,
        ]);
        Log::info('analysis.schedule_completed', [
            'schedule_key' => $scheduleKey,
            'data_quality' => $quality['status'],
            'final_action' => $decision['finalAction'],
            'is_mock' => $isMock,
        ]);
    }
}
