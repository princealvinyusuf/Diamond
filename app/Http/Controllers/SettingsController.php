<?php

namespace App\Http\Controllers;

use App\Domain\PaperTrading\AuditLogger;
use App\Models\BrokerSymbolSpec;
use App\Models\TradingAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SettingsController
{
    public function show(): JsonResponse
    {
        return response()->json($this->payload($this->account()));
    }

    public function update(Request $request, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'account.name' => ['required', 'string', 'min:2', 'max:80'],
            'account.equity' => ['required', 'numeric', 'min:100', 'max:10000000'],
            'risk.riskPerTradePercent' => ['required', 'numeric', 'min:0.1', 'max:2'],
            'risk.dailyLossCapPercent' => ['required', 'numeric', 'min:0.5', 'max:5'],
            'risk.maxTradesPerDay' => ['required', 'integer', 'min:1', 'max:10'],
            'risk.lossCooldownMinutes' => ['required', 'integer', 'min:15', 'max:10080'],
            'risk.minimumRiskReward' => ['required', 'numeric', 'min:2', 'max:5'],
            'risk.dailyProfitTarget' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'risk.martingaleEnabled' => ['sometimes', 'declined'],
            'symbol.symbol' => ['required', 'in:XAUUSD'],
            'symbol.tickSize' => ['required', 'numeric', 'gt:0', 'max:100'],
            'symbol.tickValue' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'symbol.contractSize' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'symbol.minimumVolume' => ['required', 'numeric', 'gt:0', 'max:100'],
            'symbol.maximumVolume' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'symbol.volumeStep' => ['required', 'numeric', 'gt:0', 'max:100'],
        ]);

        if ((float) $validated['risk']['dailyLossCapPercent'] < (float) $validated['risk']['riskPerTradePercent']) {
            throw ValidationException::withMessages([
                'risk.dailyLossCapPercent' => 'The daily cap must be at least the per-trade risk.',
            ]);
        }
        if ((float) $validated['symbol']['minimumVolume'] > (float) $validated['symbol']['maximumVolume']
            || (float) $validated['symbol']['volumeStep'] > (float) $validated['symbol']['maximumVolume']) {
            throw ValidationException::withMessages([
                'symbol.maximumVolume' => 'Maximum volume must cover the minimum and volume step.',
            ]);
        }

        $account = DB::transaction(function () use ($validated, $request, $audit): TradingAccount {
            $account = TradingAccount::query()->with('riskProfile')->lockForUpdate()->oldest('id')->firstOrFail();
            $before = $this->payload($account);
            $account->forceFill([
                'name' => $validated['account']['name'],
                'equity' => $validated['account']['equity'],
                'mode' => 'PAPER',
                'equity_as_of' => now(),
            ])->save();
            $account->riskProfile()->updateOrCreate([], [
                'risk_per_trade_percent' => $validated['risk']['riskPerTradePercent'],
                'daily_loss_cap_percent' => $validated['risk']['dailyLossCapPercent'],
                'max_trades_per_day' => $validated['risk']['maxTradesPerDay'],
                'loss_streak_limit' => 3,
                'loss_cooldown_minutes' => $validated['risk']['lossCooldownMinutes'],
                'minimum_rr' => $validated['risk']['minimumRiskReward'],
                'daily_profit_target' => $validated['risk']['dailyProfitTarget'],
                'martingale_enabled' => false,
            ]);
            $spec = BrokerSymbolSpec::query()
                ->where('provider', 'manual')
                ->where('symbol', 'XAUUSD')
                ->latest('effective_at')
                ->lockForUpdate()
                ->first();
            ($spec ?? new BrokerSymbolSpec)->forceFill([
                'provider' => 'manual',
                'symbol' => 'XAUUSD',
                'currency' => 'USD',
                'contract_size' => $validated['symbol']['contractSize'],
                'tick_size' => $validated['symbol']['tickSize'],
                'tick_value' => $validated['symbol']['tickValue'],
                'volume_min' => $validated['symbol']['minimumVolume'],
                'volume_max' => $validated['symbol']['maximumVolume'],
                'volume_step' => $validated['symbol']['volumeStep'],
                'effective_at' => now(),
                'provenance' => ['source' => 'local-settings', 'verified' => false],
            ])->save();
            $account->load('riskProfile');
            $audit->record(
                'dashboard_settings.updated',
                $account,
                $before,
                $this->payload($account),
                $request->header('X-Request-ID'),
                ['paper_only' => true, 'martingale' => false],
                $request->user()?->getAuthIdentifier(),
            );
            return $account;
        });

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Paper settings saved.', 'settings' => $this->payload($account)]);
        }
        return back()->with('success', 'Paper settings saved.');
    }

    /** @return array<string, mixed> */
    private function payload(TradingAccount $account): array
    {
        $account->loadMissing('riskProfile');
        $risk = $account->riskProfile;
        $spec = BrokerSymbolSpec::query()->where('provider', 'manual')->where('symbol', 'XAUUSD')
            ->latest('effective_at')->first();

        return [
            'account' => ['name' => $account->name, 'equity' => (float) $account->equity, 'mode' => 'PAPER'],
            'risk' => [
                'riskPerTradePercent' => (float) $risk->risk_per_trade_percent,
                'dailyLossCapPercent' => (float) $risk->daily_loss_cap_percent,
                'maxTradesPerDay' => (int) $risk->max_trades_per_day,
                'lossCooldownMinutes' => (int) $risk->loss_cooldown_minutes,
                'minimumRiskReward' => (float) $risk->minimum_rr,
                'dailyProfitTarget' => $risk->daily_profit_target === null ? null : (float) $risk->daily_profit_target,
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
        ];
    }

    private function account(): TradingAccount
    {
        return TradingAccount::query()->with('riskProfile')->oldest('id')->firstOrFail();
    }
}
