<?php

namespace App\Domain\Decision;

use App\Domain\Fundamental\CalendarProvider;
use App\Domain\Fundamental\EventWindowEvaluator;
use App\Domain\MarketData\MarketDataProvider;
use App\Domain\MarketData\MarketDataQualityGate;
use App\Domain\MarketData\TechnicalAnalysisBridge;
use DateInterval;
use DateTimeImmutable;

final class AnalysisService
{
    public function __construct(
        private readonly MarketDataProvider $market,
        private readonly CalendarProvider $calendar,
        private readonly MarketDataQualityGate $qualityGate,
        private readonly EventWindowEvaluator $eventGate,
        private readonly TechnicalAnalysisBridge $technical,
        private readonly DecisionPipeline $pipeline,
    ) {}

    /**
     * @param list<string> $riskReasons
     * @return array{decision: array<string, mixed>, quote: array<string, mixed>, candles: array<string, array>, quality: array<string, mixed>, events: array, calendarHealth: array<string, mixed>, technical: array<string, mixed>, providerHealth: array<string, mixed>}
     */
    public function run(bool $riskAllowed = false, array $riskReasons = []): array
    {
        $quote = $this->market->quote('XAUUSD');
        $isMock = (bool) ($quote['isMock'] ?? false);
        $at = $isMock
            ? new DateTimeImmutable((string) $quote['timestamp'])
            : new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $candles = [];
        foreach (['D1', 'H4', 'H1'] as $timeframe) {
            $candles[$timeframe] = $this->market->candles('XAUUSD', $timeframe, 250);
        }
        $quality = $this->qualityGate->evaluate(
            $quote,
            $candles['H4'],
            $at,
            (int) config('diamond.quality.max_quote_age_seconds', 300),
            (float) config('diamond.quality.max_spread', 1.0),
            (int) config('diamond.quality.max_future_skew_seconds', 30),
        );
        $events = $this->calendar->events($at->sub(new DateInterval('P1D')), $at->add(new DateInterval('P1D')));
        $calendarHealth = $this->calendar->health();
        $event = $this->eventGate->evaluate($events, $at);
        if (! $isMock && (bool) ($calendarHealth['isMock'] ?? true)) {
            $event = [
                'state' => 'HIGH',
                'blocking' => true,
                'eventIds' => [],
                'reasons' => ['EVENT_DATA_UNVERIFIED'],
            ];
        }
        $technical = $this->technical->analyze($candles, $at);
        $analysis = $technical['analysis'] ?? null;
        $technicalAvailable = $technical['ok'] && is_array($analysis);
        $dataQuality = $technicalAvailable ? $quality['status'] : 'UNAVAILABLE';
        $technicalError = $technical['error']['code'] ?? null;

        $decision = $this->pipeline->decide([
            'asOf' => $at->format(DATE_ATOM),
            'isMock' => $isMock,
            'strategyVersion' => (string) config('diamond.strategy_version', 'h4-gold-v1'),
            'data' => ['quality' => $dataQuality],
            'risk' => [
                'allowed' => $riskAllowed,
                'reasons' => $riskAllowed ? [] : ($riskReasons ?: ['ACCOUNT_RISK_REVIEW_REQUIRED']),
            ],
            'event' => $event,
            'regime' => $analysis['regime'] ?? 'UNKNOWN',
            'bias' => $analysis['bias'] ?? 'NEUTRAL',
            'setup' => [
                ...($analysis['setup'] ?? []),
                'qualityScore' => $analysis['setup']['qualityScore'] ?? 0,
                'minimumScore' => 60,
            ],
            // This bridge supplies evidence, not invented trade prices or account sizing.
            'economics' => ['netRiskReward' => 0, 'minimumRiskReward' => 2],
            'sizing' => ['safeVolume' => 0],
            'technicalAnalysis' => $technicalAvailable ? $analysis : [
                'status' => 'UNAVAILABLE',
                'error' => [
                    'code' => $technicalError ?: 'TECHNICAL_ANALYSIS_UNAVAILABLE',
                    'message' => $technical['error']['message'] ?? 'Technical analysis is unavailable.',
                ],
            ],
        ]);

        return [
            'decision' => $decision,
            'quote' => $quote,
            'candles' => $candles,
            'quality' => $quality,
            'events' => $events,
            'calendarHealth' => $calendarHealth,
            'technical' => $technical,
            'providerHealth' => $this->market->health(),
        ];
    }
}
