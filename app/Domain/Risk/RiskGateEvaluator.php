<?php

namespace App\Domain\Risk;

use DateTimeImmutable;
use InvalidArgumentException;

final class RiskGateEvaluator
{
    /**
     * @param array{equity: numeric-string|int|float, realizedDailyPnl: numeric-string|int|float, tradesToday: int, lastTradeAt?: string|null, evaluatedAt: string} $state
     * @param array{maxDailyLossPercent: numeric-string|int|float, maxTradesPerDay: int, cooldownMinutes: int} $limits
     * @return array{allowed: bool, status: 'allowed'|'blocked', blockers: list<array{code: string, message: string, context: array<string, int|float|string>}>}
     */
    public function evaluate(array $state, array $limits): array
    {
        $equity = Decimal::value($state['equity'], 'equity');
        $dailyPnl = Decimal::value($state['realizedDailyPnl'], 'realizedDailyPnl');
        $lossPercent = Decimal::value($limits['maxDailyLossPercent'], 'maxDailyLossPercent');
        $tradeCount = $state['tradesToday'];
        $maxTrades = $limits['maxTradesPerDay'];
        $cooldownMinutes = $limits['cooldownMinutes'];

        if ($equity <= 0.0 || $lossPercent <= 0.0 || $tradeCount < 0 || $maxTrades <= 0 || $cooldownMinutes < 0) {
            throw new InvalidArgumentException('Risk gate values are outside their valid ranges.');
        }

        $blockers = [];
        $lossLimit = Decimal::multiply($equity, Decimal::divide($lossPercent, 100.0));
        $realizedLoss = max(0.0, -$dailyPnl);

        if ($realizedLoss >= $lossLimit) {
            $blockers[] = [
                'code' => 'DAILY_LOSS_LIMIT_REACHED',
                'message' => 'The daily realized-loss limit has been reached.',
                'context' => ['realizedLoss' => $realizedLoss, 'lossLimit' => $lossLimit],
            ];
        }

        if ($tradeCount >= $maxTrades) {
            $blockers[] = [
                'code' => 'DAILY_TRADE_LIMIT_REACHED',
                'message' => 'The maximum number of daily trades has been reached.',
                'context' => ['tradesToday' => $tradeCount, 'maxTradesPerDay' => $maxTrades],
            ];
        }

        if (($state['lastTradeAt'] ?? null) !== null && $cooldownMinutes > 0) {
            $evaluatedAt = new DateTimeImmutable($state['evaluatedAt']);
            $lastTradeAt = new DateTimeImmutable($state['lastTradeAt']);
            $elapsedSeconds = $evaluatedAt->getTimestamp() - $lastTradeAt->getTimestamp();
            $requiredSeconds = $cooldownMinutes * 60;

            if ($elapsedSeconds < 0) {
                throw new InvalidArgumentException('lastTradeAt cannot be after evaluatedAt.');
            }

            if ($elapsedSeconds < $requiredSeconds) {
                $blockers[] = [
                    'code' => 'TRADE_COOLDOWN_ACTIVE',
                    'message' => 'The post-trade cooldown is still active.',
                    'context' => [
                        'remainingSeconds' => $requiredSeconds - $elapsedSeconds,
                        'cooldownMinutes' => $cooldownMinutes,
                    ],
                ];
            }
        }

        return [
            'allowed' => $blockers === [],
            'status' => $blockers === [] ? 'allowed' : 'blocked',
            'blockers' => $blockers,
        ];
    }
}
