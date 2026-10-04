<?php

namespace App\Domain\Decision;

use App\Domain\Fundamental\CalendarProvider;
use App\Domain\Fundamental\EventWindowEvaluator;
use App\Domain\MarketData\MarketDataProvider;
use App\Domain\MarketData\MarketDataQualityGate;
use App\Domain\MarketData\TechnicalAnalysisBridge;
use App\Domain\Risk\RiskCalculator;
use App\Models\BrokerSymbolSpec;
use App\Models\DailyRiskLedger;
use App\Models\TradingAccount;
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
        private readonly RiskCalculator $riskCalculator,
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
        $account = TradingAccount::query()->with('riskProfile')->oldest('id')->first();
        $profile = $account?->riskProfile;
        $ledger = $account === null ? null : DailyRiskLedger::query()
            ->where('trading_account_id', $account->id)
            ->whereDate('session_date', now('UTC')->toDateString())
            ->first();
        if ($account !== null && $ledger === null) {
            $ledger = DailyRiskLedger::query()->create([
                'trading_account_id' => $account->id,
                'session_date' => now('UTC')->toDateString(),
                'session_timezone' => 'UTC',
                'starting_equity' => $account->equity,
                'realized_pl' => 0,
                'open_risk' => 0,
                'trades_count' => 0,
                'consecutive_losses' => 0,
                'is_locked' => false,
            ]);
        }
        $calculatedRisk = $this->riskPermission($account, $ledger);
        if ($account !== null) {
            $riskAllowed = $calculatedRisk['allowed'];
            $riskReasons = $calculatedRisk['reasons'];
        }
        $paperPlan = $this->sizePaperPlan($analysis['paperPlan'] ?? null, $account, $quality, $quote);

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
                'entry' => $paperPlan['entry'] ?? null,
                'stopLoss' => $paperPlan['stopLoss'] ?? null,
                'targets' => $paperPlan['targets'] ?? [],
                'qualityScore' => $analysis['setup']['qualityScore'] ?? 0,
                'minimumScore' => 60,
            ],
            'economics' => [
                'netRiskReward' => $paperPlan['netRiskReward'] ?? 0,
                'minimumRiskReward' => (float) ($profile?->minimum_rr ?? 2),
            ],
            'sizing' => ['safeVolume' => $paperPlan['safeVolume'] ?? 0],
            'paperPlan' => $paperPlan,
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
            'account' => $account,
            'ledger' => $ledger,
            'symbolSpec' => BrokerSymbolSpec::query()->where('provider', 'manual')
                ->where('symbol', 'XAUUSD')->latest('effective_at')->first(),
        ];
    }

    /** @return array{allowed: bool, reasons: list<string>} */
    private function riskPermission(?TradingAccount $account, ?DailyRiskLedger $ledger): array
    {
        if ($account === null || $account->riskProfile === null || $ledger === null) {
            return ['allowed' => false, 'reasons' => ['ACCOUNT_RISK_PROFILE_UNAVAILABLE']];
        }
        $profile = $account->riskProfile;
        $reasons = [];
        $lossCap = (float) $ledger->starting_equity * ((float) $profile->daily_loss_cap_percent / 100);
        if ($ledger->is_locked) {
            $reasons[] = $ledger->lock_reason ?: 'DAILY_RISK_LOCKED';
        }
        if ($ledger->cooldown_until?->isFuture()) {
            $reasons[] = 'LOSS_COOLDOWN_ACTIVE';
        }
        if ($ledger->trades_count >= $profile->max_trades_per_day) {
            $reasons[] = 'DAILY_TRADE_LIMIT_REACHED';
        }
        if (max(0, -(float) $ledger->realized_pl) + (float) $ledger->open_risk >= $lossCap) {
            $reasons[] = 'DAILY_LOSS_CAP_REACHED';
        }
        return ['allowed' => $reasons === [], 'reasons' => array_values(array_unique($reasons))];
    }

    /** @return array<string, mixed>|null */
    private function sizePaperPlan(mixed $plan, ?TradingAccount $account, array $quality, array $quote): ?array
    {
        if (! is_array($plan) || $account?->riskProfile === null) {
            return null;
        }
        $spec = BrokerSymbolSpec::query()->where('provider', 'manual')->where('symbol', 'XAUUSD')
            ->latest('effective_at')->first();
        if ($spec === null) {
            return [...$plan, 'sizingStatus' => 'NO_MANUAL_SYMBOL_SPEC', 'safeVolume' => null, 'dollarRisk' => null];
        }
        $contractSize = (float) $spec->contract_size;
        $hasExecutableSpread = ! (bool) ($quote['indicative'] ?? false);
        $costs = [
            'spreadPerLot' => $hasExecutableSpread
                ? max(0, (float) $quote['ask'] - (float) $quote['bid']) * $contractSize
                : 0,
            'commissionPerLot' => (float) config('diamond.paper.commission_round_trip_per_lot', 7),
            'slippagePerLot' => (float) config('diamond.paper.slippage_price', 0.05) * $contractSize * 2,
        ];
        $sizing = $this->riskCalculator->calculate([
            'equity' => $account->equity,
            'riskPercent' => $account->riskProfile->risk_per_trade_percent,
            'entry' => $plan['entry'],
            'stopLoss' => $plan['stopLoss'],
            'target' => $plan['targets'][0] ?? null,
        ], [
            'tickSize' => $spec->tick_size,
            'tickValue' => $spec->tick_value,
            'minimumVolume' => $spec->volume_min,
            'maximumVolume' => $spec->volume_max,
            'volumeStep' => $spec->volume_step,
        ], $costs);

        return [
            ...$plan,
            'actionable' => false,
            'qualityStatus' => $quality['status'],
            'safeVolume' => $sizing['safeVolume'],
            'dollarRisk' => $sizing['actualRisk'],
            'riskBudget' => $sizing['riskBudget'],
            'netRiskReward' => $sizing['netRiskReward']['ratio'],
            'sizingStatus' => $sizing['code'],
            'costs' => $costs,
            'spreadExecutable' => $hasExecutableSpread,
        ];
    }
}
