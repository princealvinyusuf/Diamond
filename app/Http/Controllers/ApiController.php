<?php

namespace App\Http\Controllers;

use App\Domain\Decision\AnalysisService;
use App\Domain\Fundamental\CalendarProvider;
use App\Domain\Fundamental\DriverAggregator;
use App\Domain\Fundamental\FundamentalProvider;
use App\Domain\MarketData\MarketDataProvider;
use App\Domain\Risk\RiskCalculator;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApiController
{
    public function quote(MarketDataProvider $provider): JsonResponse
    {
        return response()->json($provider->quote('XAUUSD'));
    }

    public function candles(Request $request, MarketDataProvider $provider): JsonResponse
    {
        $timeframe = strtoupper((string) $request->query('timeframe', 'H4'));
        abort_unless(in_array($timeframe, ['H1', 'H4', 'D1'], true), 422, 'Invalid timeframe.');
        return response()->json([
            'symbol' => 'XAUUSD',
            'timeframe' => $timeframe,
            'candles' => $provider->candles('XAUUSD', $timeframe, (int) $request->query('limit', 250)),
            'health' => $provider->health(),
        ]);
    }

    public function fundamentals(FundamentalProvider $provider, DriverAggregator $aggregator): JsonResponse
    {
        $observations = $provider->observations('XAUUSD');
        $health = $provider->health();
        return response()->json([
            'symbol' => 'XAUUSD',
            'observations' => $observations,
            'aggregate' => $aggregator->aggregate($observations, ['real_yields', 'usd_momentum', 'inflation']),
            'health' => $health,
            'isMock' => (bool) ($health['isMock'] ?? false),
        ]);
    }

    public function calendar(CalendarProvider $provider): JsonResponse
    {
        $health = $provider->health();
        $at = ($health['isMock'] ?? false) && isset($health['last_success_at'])
            ? new DateTimeImmutable((string) $health['last_success_at'])
            : new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return response()->json([
            'events' => $provider->events($at->sub(new DateInterval('P1D')), $at->add(new DateInterval('P7D'))),
            'health' => $health,
            'isMock' => (bool) ($health['isMock'] ?? false),
        ]);
    }

    public function latest(): JsonResponse
    {
        abort_unless(config('diamond.market_data_provider') === 'mock', 404, 'No persisted analysis is available.');
        return response()->json(json_decode(
            file_get_contents(base_path('fixtures/mock-dashboard.json')) ?: '',
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
    }

    public function run(AnalysisService $analysis): JsonResponse
    {
        return response()->json($analysis->run()['decision']);
    }

    public function riskQuote(Request $request, RiskCalculator $calculator): JsonResponse
    {
        $position = $request->validate([
            'equity' => ['required', 'numeric', 'gt:0'],
            'riskPercent' => ['required', 'numeric', 'gt:0', 'lte:100'],
            'entry' => ['required', 'numeric', 'gt:0'],
            'stopLoss' => ['required', 'numeric', 'gt:0'],
            'target' => ['nullable', 'numeric', 'gt:0'],
            'brokerSpec' => ['sometimes', 'array'],
            'brokerSpec.tickSize' => ['required_with:brokerSpec', 'numeric', 'gt:0'],
            'brokerSpec.tickValue' => ['required_with:brokerSpec', 'numeric', 'gt:0'],
            'brokerSpec.minimumVolume' => ['required_with:brokerSpec', 'numeric', 'gt:0'],
            'brokerSpec.maximumVolume' => ['required_with:brokerSpec', 'numeric', 'gte:brokerSpec.minimumVolume'],
            'brokerSpec.volumeStep' => ['required_with:brokerSpec', 'numeric', 'gt:0'],
            'costs' => ['sometimes', 'array'],
            'costs.spreadPerLot' => ['sometimes', 'numeric', 'min:0'],
            'costs.commissionPerLot' => ['sometimes', 'numeric', 'min:0'],
            'costs.slippagePerLot' => ['sometimes', 'numeric', 'min:0'],
        ]);
        $brokerSpec = $position['brokerSpec'] ?? [
            'tickSize' => '0.01', 'tickValue' => '1.00',
            'minimumVolume' => '0.01', 'maximumVolume' => '1.00', 'volumeStep' => '0.01',
        ];
        $costs = $position['costs'] ?? [];
        unset($position['brokerSpec'], $position['costs']);
        return response()->json([
            ...$calculator->calculate($position, $brokerSpec, $costs),
            'executionMode' => 'PAPER',
            'isMock' => ! $request->has('brokerSpec'),
            'brokerSpecProvenance' => $request->has('brokerSpec')
                ? 'user-supplied-manual-specification'
                : 'fixture-default-not-live',
            'disclaimer' => 'Decision support only; no order is created.',
        ]);
    }
}
