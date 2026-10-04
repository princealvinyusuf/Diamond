<?php

namespace App\Domain\PaperTrading;

use App\Models\DailyRiskLedger;
use App\Models\TradingAccount;
use DomainException;

final class DailyRiskManager
{
    public function lockedLedger(TradingAccount $account): DailyRiskLedger
    {
        $timezone = $account->user?->timezone ?? config('app.timezone', 'UTC');
        $date = now($timezone)->toDateString();
        $activeCooldown = DailyRiskLedger::query()
            ->where('trading_account_id', $account->id)
            ->where('cooldown_until', '>', now())
            ->latest('session_date')
            ->first();

        $ledger = DailyRiskLedger::query()
            ->where('trading_account_id', $account->id)
            ->whereDate('session_date', $date)
            ->first();
        if ($ledger === null) {
            $ledger = DailyRiskLedger::query()->create([
                'trading_account_id' => $account->id,
                'session_date' => $date,
                'session_timezone' => $timezone,
                'starting_equity' => $account->equity,
                'realized_pl' => 0, 'open_risk' => 0, 'trades_count' => 0,
                'consecutive_losses' => $activeCooldown?->consecutive_losses ?? 0,
                'is_locked' => $activeCooldown !== null,
                'cooldown_until' => $activeCooldown?->cooldown_until,
                'lock_reason' => $activeCooldown ? 'CONSECUTIVE_LOSS_COOLDOWN' : null,
            ]);
        }

        /** @var DailyRiskLedger */
        return DailyRiskLedger::query()->lockForUpdate()->findOrFail($ledger->id);
    }

    public function assertCanOpen(
        TradingAccount $account,
        DailyRiskLedger $ledger,
        float $proposedRisk = 0,
    ): void
    {
        $profile = $account->riskProfile;
        if ($profile === null) {
            throw new DomainException('A risk profile is required before paper trading.');
        }
        if ($ledger->lock_reason === 'CONSECUTIVE_LOSS_COOLDOWN' && $ledger->cooldown_until?->isPast()) {
            $ledger->forceFill(['is_locked' => false, 'lock_reason' => null, 'cooldown_until' => null])->save();
        }
        if ($ledger->cooldown_until?->isFuture()) {
            throw new DomainException('Trading is in consecutive-loss cooldown.');
        }
        if ($ledger->is_locked || $ledger->trades_count >= $profile->max_trades_per_day) {
            throw new DomainException('Daily trading limit is locked.');
        }
        $lossCap = (float) $ledger->starting_equity * ((float) $profile->daily_loss_cap_percent / 100);
        if ((float) $ledger->realized_pl <= -$lossCap) {
            throw new DomainException('Daily loss cap has been reached.');
        }
        $usedRisk = max(0, -(float) $ledger->realized_pl)
            + (float) $ledger->open_risk
            + max(0, $proposedRisk);
        if ($usedRisk > $lossCap) {
            throw new DomainException('Realized loss plus open and proposed risk exceeds the daily risk cap.');
        }
    }

    public function opened(DailyRiskLedger $ledger, float $risk): void
    {
        $ledger->forceFill([
            'trades_count' => $ledger->trades_count + 1,
            'open_risk' => round((float) $ledger->open_risk + $risk, 8),
        ])->save();
    }

    public function closed(TradingAccount $account, DailyRiskLedger $ledger, float $risk, float $pl): void
    {
        $profile = $account->riskProfile;
        $losses = $pl < 0 ? $ledger->consecutive_losses + 1 : 0;
        $realized = round((float) $ledger->realized_pl + $pl, 8);
        $lossCap = (float) $ledger->starting_equity * ((float) $profile->daily_loss_cap_percent / 100);
        $locked = $realized <= -$lossCap || $ledger->trades_count >= $profile->max_trades_per_day;

        $ledger->forceFill([
            'realized_pl' => $realized,
            'open_risk' => max(0, round((float) $ledger->open_risk - $risk, 8)),
            'consecutive_losses' => $losses,
            'cooldown_until' => $losses >= $profile->loss_streak_limit
                ? now()->addMinutes((int) $profile->loss_cooldown_minutes)
                : null,
            'is_locked' => $locked || $losses >= $profile->loss_streak_limit,
            'lock_reason' => $losses >= $profile->loss_streak_limit
                ? 'CONSECUTIVE_LOSS_COOLDOWN'
                : ($locked ? 'DAILY_RISK_LIMIT' : null),
        ])->save();
    }
}
