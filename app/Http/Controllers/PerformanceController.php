<?php

namespace App\Http\Controllers;

use App\Models\Trade;
use App\Models\TradingAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class PerformanceController
{
    public function __invoke(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'accountId' => ['required', 'integer', 'exists:trading_accounts,id'],
            'strategyVersion' => ['sometimes', 'string', 'max:100'],
        ]);
        $account = TradingAccount::query()->findOrFail($filters['accountId']);
        $query = Trade::query()
            ->whereNotNull('realized_pl')
            ->whereHas('position', fn ($query) => $query->where('trading_account_id', $account->id))
            ->orderBy('closed_at');
        if (isset($filters['strategyVersion'])) {
            $query->where('entry_snapshot->strategy->version', $filters['strategyVersion']);
        }
        $trades = $query->get();

        return response()->json([
            'scope' => [
                'executionMode' => 'PAPER',
                'accountId' => $account->id,
                'strategyVersion' => $filters['strategyVersion'] ?? 'ALL',
            ],
            'asOf' => now()->toISOString(),
            'metrics' => $this->metrics($trades, (float) $account->equity),
        ]);
    }

    /** @param Collection<int, Trade> $trades
     *  @return array<string, mixed>
     */
    private function metrics(Collection $trades, float $equity): array
    {
        $values = $trades->map(fn (Trade $trade): float => (float) $trade->realized_pl);
        $wins = $values->filter(fn (float $value): bool => $value > 0);
        $losses = $values->filter(fn (float $value): bool => $value < 0);
        $net = $values->sum();
        $startingEquity = $equity - $net;
        $peak = $startingEquity;
        $running = $startingEquity;
        $drawdown = 0.0;
        $streak = 0;
        $maxStreak = 0;
        foreach ($values as $value) {
            $running += $value;
            $peak = max($peak, $running);
            $drawdown = max($drawdown, $peak - $running);
            $streak = $value < 0 ? $streak + 1 : 0;
            $maxStreak = max($maxStreak, $streak);
        }
        $grossWin = $wins->sum();
        $grossLoss = abs($losses->sum());
        $averageWin = $wins->isEmpty() ? 0.0 : $wins->avg();
        $averageLoss = $losses->isEmpty() ? 0.0 : abs($losses->avg());
        $first = $trades->first()?->opened_at;
        $last = $trades->last()?->closed_at;
        $window = $first && $last ? max(1, $first->diffInSeconds($last)) : 0;
        $held = $trades->sum(
            fn (Trade $trade): int => $trade->closed_at && $trade->opened_at
                ? (int) $trade->opened_at->diffInSeconds($trade->closed_at)
                : 0
        );

        return [
            'tradeCount' => $trades->count(),
            'netPl' => $net,
            'netReturn' => $startingEquity > 0 ? $net / $startingEquity : null,
            'expectancyR' => $trades->isEmpty() ? null : $trades->avg('r_multiple'),
            'profitFactor' => $grossLoss > 0 ? $grossWin / $grossLoss : null,
            'maxDrawdown' => $drawdown,
            'winRate' => $trades->isEmpty() ? null : $wins->count() / $trades->count(),
            'averageWin' => $averageWin,
            'averageLoss' => $averageLoss,
            'payoffRatio' => $averageLoss > 0 ? $averageWin / $averageLoss : null,
            'maxConsecutiveLosses' => $maxStreak,
            'exposure' => $window > 0 ? min(1, $held / $window) : 0,
            'breakdowns' => [
                'setupFamily' => $this->breakdown($trades, 'setupFamily'),
                'regime' => $this->breakdown($trades, 'regime'),
                'alignment' => $this->breakdown($trades, 'alignment'),
            ],
        ];
    }

    /** @param Collection<int, Trade> $trades
     *  @return array<string, array<string, float|int|null>>
     */
    private function breakdown(Collection $trades, string $key): array
    {
        return $trades->groupBy(
            fn (Trade $trade): string => (string) (
                $trade->entry_snapshot['setup'][$key]
                ?? $trade->entry_snapshot['analysis']['market_snapshot'][$key]
                ?? 'UNKNOWN'
            )
        )->map(function (Collection $group): array {
            $wins = $group->filter(fn (Trade $trade): bool => (float) $trade->realized_pl > 0);

            return [
                'trades' => $group->count(),
                'netPl' => $group->sum(fn (Trade $trade): float => (float) $trade->realized_pl),
                'expectancyR' => $group->avg('r_multiple'),
                'winRate' => $group->isEmpty() ? null : $wins->count() / $group->count(),
            ];
        })->all();
    }
}
