<?php

namespace App\Http\Controllers;

use App\Domain\Decision\AnalysisService;
use App\Models\BrokerSymbolSpec;
use App\Models\DailyRiskLedger;
use App\Models\TradingAccount;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Throwable;

final class DashboardController
{
    public function __invoke(AnalysisService $analysis): InertiaResponse
    {
        $decision = json_decode(
            file_get_contents(base_path('fixtures/mock-dashboard.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $dashboard = json_decode(
            file_get_contents(base_path('fixtures/mock-dashboard-view.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $dataState = [
            'mode' => 'FIXTURE',
            'label' => 'STATIC FIXTURE · NOT LIVE',
            'message' => 'No live prices, signals, advice, or order execution. All values below come from a local fixture.',
            'candles' => [],
        ];

        $analysisResult = null;
        if (true) {
            try {
                $result = $analysis->run();
                $analysisResult = $result;
                $decision = $result['decision'];
                $quote = $result['quote'];
                $h4 = array_values(array_filter(
                    $result['candles']['H4'],
                    static fn (array $candle): bool => ($candle['isClosed'] ?? false) === true
                        && (new \DateTimeImmutable((string) $candle['openTime']))->modify('+4 hours') <= new \DateTimeImmutable((string) $decision['asOf']),
                ));
                $indicative = (bool) ($quote['indicative'] ?? false);
                $mid = $indicative
                    ? (float) $quote['mid']
                    : ((float) $quote['bid'] + (float) $quote['ask']) / 2;
                $dashboard['quote'] = [
                    'displayPrice' => number_format($mid, 2),
                    'change' => $indicative ? 'INDICATIVE MID · NOT EXECUTABLE' : 'Read-only bid/ask midpoint',
                    'session' => 'Provider snapshot',
                    'sourceLabel' => strtoupper((string) $quote['provider']).($indicative ? ' · INDICATIVE MID' : ' · READ ONLY'),
                ];
                $technical = $decision['technicalAnalysis'];
                $analysisAvailable = isset($technical['features']);
                $features = $technical['features']['H4'] ?? [];
                $dashboard['technical'] = [
                    'status' => $analysisAvailable ? 'Calculated' : 'Unavailable',
                    'items' => [
                        ['label' => 'Structure', 'value' => implode(' · ', $technical['structure']['labels'] ?? []) ?: 'No confirmed pivots'],
                        ['label' => 'Momentum', 'value' => isset($features['rsi14']) ? 'RSI '.number_format((float) $features['rsi14'], 1) : 'Not warmed up'],
                        ['label' => 'Volatility', 'value' => isset($features['atr14']) ? 'ATR '.number_format((float) $features['atr14'], 2) : 'Not warmed up'],
                    ],
                ];
                $dashboard['fundamental'] = [
                    'status' => 'Unavailable',
                    'items' => [
                        ['label' => 'USD / yields', 'value' => 'No verified live source'],
                        ['label' => 'Macro regime', 'value' => 'Not scored'],
                        ['label' => 'Positioning', 'value' => 'Not scored'],
                    ],
                ];
                $calendarIsMock = (bool) ($result['calendarHealth']['isMock'] ?? true);
                $dashboard['eventRisk'] = [
                    'status' => str_replace('_', ' ', (string) $decision['newsRisk']),
                    'nextEvent' => $calendarIsMock
                        ? 'No verified live calendar feed'
                        : 'See verified calendar events',
                    'guidance' => $calendarIsMock
                        ? 'New entries remain blocked until event data is verified.'
                        : 'Protection windows are evaluated from the configured calendar provider.',
                ];
                $dashboard['chart']['label'] = 'Provider H4 closed candles';
                $dashboard['explanation'] = [
                    'summary' => $analysisAvailable
                        ? 'The displayed result comes from read-only provider data and deterministic technical analysis.'
                        : 'Provider candles are available, but technical analysis failed closed.',
                    'steps' => $decision['blockReasons'] === []
                        ? ['All decision gates passed; execution remains paper-only.']
                        : array_map(
                            static fn (string $reason): string => str_replace('_', ' ', $reason),
                            $decision['blockReasons'],
                        ),
                ];
                $dataState = [
                    'mode' => ($quote['isMock'] ?? false) ? 'FIXTURE' : 'PROVIDER',
                    'label' => strtoupper((string) $quote['provider']).(($quote['isMock'] ?? false) ? ' · FIXTURE' : ' · READ ONLY'),
                    'message' => $indicative
                        ? 'Indicative midpoint only. There is no executable spread; every action gate is blocked.'
                        : ($analysisAvailable
                            ? 'Read-only provider candles and deterministic analysis. Paper mode; no automatic order creation.'
                            : 'Read-only provider candles are shown; analysis is unavailable and actionable gates are blocked.'),
                    'candles' => array_slice($h4, -250),
                ];
            } catch (Throwable) {
                $decision = [
                    ...$decision,
                    'asOf' => now('UTC')->toISOString(),
                    'isMock' => false,
                    'marketBias' => 'NEUTRAL',
                    'tradeSetup' => 'BLOCKED',
                    'riskPermission' => 'BLOCKED',
                    'finalAction' => 'NO_TRADE',
                    'newsRisk' => 'HIGH',
                    'dataQuality' => 'UNAVAILABLE',
                    'technicalScore' => null,
                    'fundamentalScore' => null,
                    'blockReasons' => ['PROVIDER_DATA_UNAVAILABLE'],
                    'technicalAnalysis' => [
                        'status' => 'UNAVAILABLE',
                        'error' => ['code' => 'PROVIDER_DATA_UNAVAILABLE', 'message' => 'Provider data is unavailable.'],
                    ],
                ];
                $dashboard['quote'] = [
                    'displayPrice' => '—',
                    'change' => 'Unavailable',
                    'session' => 'No verified snapshot',
                    'sourceLabel' => 'PROVIDER UNAVAILABLE',
                ];
                $dashboard['technical']['status'] = 'Unavailable';
                $dashboard['technical']['items'] = [
                    ['label' => 'Structure', 'value' => 'No verified data'],
                    ['label' => 'Momentum', 'value' => 'Unavailable'],
                    ['label' => 'Volatility', 'value' => 'Unavailable'],
                ];
                $dashboard['fundamental'] = [
                    'status' => 'Unavailable',
                    'items' => [
                        ['label' => 'USD / yields', 'value' => 'No verified data'],
                        ['label' => 'Macro regime', 'value' => 'Unavailable'],
                        ['label' => 'Positioning', 'value' => 'Unavailable'],
                    ],
                ];
                $dashboard['eventRisk'] = [
                    'status' => 'Unavailable',
                    'nextEvent' => 'No verified calendar data',
                    'guidance' => 'Risk permission remains blocked while event data is unavailable.',
                ];
                $dashboard['explanation'] = [
                    'summary' => 'No decision can be produced because provider data is unavailable.',
                    'steps' => ['No fixture market values were substituted.', 'All actionable gates remain blocked.'],
                ];
                $dataState = [
                    'mode' => 'UNAVAILABLE',
                    'label' => 'PROVIDER UNAVAILABLE',
                    'message' => 'No verified provider or analysis data is available. No values are substituted.',
                    'candles' => [],
                ];
            }
        }

        $account = $analysisResult['account'] ?? TradingAccount::query()->with('riskProfile')->oldest('id')->first();
        $profile = $account?->riskProfile;
        $ledger = $analysisResult['ledger'] ?? ($account === null ? null : DailyRiskLedger::query()
            ->where('trading_account_id', $account->id)->latest('session_date')->first());
        $spec = $analysisResult['symbolSpec'] ?? BrokerSymbolSpec::query()
            ->where('provider', 'manual')->where('symbol', 'XAUUSD')->latest('effective_at')->first();
        if ($account !== null && $profile !== null) {
            $dashboard['account'] = [
                'name' => $account->name,
                'equity' => '$'.number_format((float) $account->equity, 2),
                'riskPerTrade' => number_format((float) $profile->risk_per_trade_percent, 2).'%',
                'dailyLossLimit' => number_format((float) $profile->daily_loss_cap_percent, 2).'%',
                'tradesToday' => (int) ($ledger?->trades_count ?? 0).' / '.(int) $profile->max_trades_per_day,
                'cooldown' => $ledger?->cooldown_until?->isFuture()
                    ? 'Until '.$ledger->cooldown_until->toISOString()
                    : 'Inactive',
                'safeVolume' => isset($decision['paperPlan']['safeVolume'])
                    ? number_format((float) $decision['paperPlan']['safeVolume'], 2).' lots'
                    : 'Not calculated',
            ];
        }

        return Inertia::render('Dashboard', [
            'decision' => $decision,
            'dashboard' => $dashboard,
            'dataState' => $dataState,
            'settings' => [
                'account' => [
                    'name' => $account?->name ?? '',
                    'equity' => (float) ($account?->equity ?? 0),
                    'mode' => 'PAPER',
                ],
                'risk' => [
                    'riskPerTradePercent' => (float) ($profile?->risk_per_trade_percent ?? 0.5),
                    'dailyLossCapPercent' => (float) ($profile?->daily_loss_cap_percent ?? 2),
                    'maxTradesPerDay' => (int) ($profile?->max_trades_per_day ?? 3),
                    'lossCooldownMinutes' => (int) ($profile?->loss_cooldown_minutes ?? 1440),
                    'minimumRiskReward' => (float) ($profile?->minimum_rr ?? 2),
                    'dailyProfitTarget' => $profile?->daily_profit_target === null ? null : (float) $profile->daily_profit_target,
                    'martingaleEnabled' => false,
                ],
                'symbol' => [
                    'symbol' => 'XAUUSD',
                    'tickSize' => (float) ($spec?->tick_size ?? 0.01),
                    'tickValue' => (float) ($spec?->tick_value ?? 1),
                    'contractSize' => (float) ($spec?->contract_size ?? 100),
                    'minimumVolume' => (float) ($spec?->volume_min ?? 0.01),
                    'maximumVolume' => (float) ($spec?->volume_max ?? 100),
                    'volumeStep' => (float) ($spec?->volume_step ?? 0.01),
                    'provenance' => $spec === null ? 'fallback-unverified' : 'manual-unverified',
                ],
            ],
        ]);
    }

    public function contract(): Response
    {
        return response(
            file_get_contents(base_path('contracts/v1/decision.schema.json')),
            200,
            ['Content-Type' => 'application/schema+json'],
        );
    }
}
