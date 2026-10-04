<?php

namespace App\Domain\PaperTrading;

use App\Models\PaperOrder;
use App\Models\Position;
use App\Models\Trade;
use App\Models\TradeSetup;
use App\Models\TradingAccount;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use DateTimeImmutable;

final class PaperTradingService
{
    public function __construct(
        private readonly SetupLifecycle $lifecycle,
        private readonly DailyRiskManager $risk,
        private readonly AuditLogger $audit,
    ) {}

    public function createOrder(
        TradingAccount $account,
        TradeSetup $setup,
        array $input,
        string $idempotencyKey,
        ?int $actorId = null,
    ): PaperOrder {
        return DB::transaction(function () use ($account, $setup, $input, $idempotencyKey, $actorId): PaperOrder {
            $costs = $this->costs($input['costs'] ?? []);
            $account = TradingAccount::query()->with('riskProfile')->lockForUpdate()->findOrFail($account->id);
            $setup = TradeSetup::query()->lockForUpdate()->findOrFail($setup->id);
            $existing = PaperOrder::query()->where('create_idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if (
                    $existing->trading_account_id !== $account->id
                    || $existing->trade_setup_id !== $setup->id
                    || $existing->order_type !== ($input['order_type'] ?? null)
                    || (float) $existing->volume !== (float) ($input['volume'] ?? 0)
                    || $existing->cost_assumptions != $costs
                ) {
                    throw new DomainException('Idempotency key was already used with a different order payload.');
                }
                return $existing;
            }
            if ($account->mode !== 'PAPER') {
                throw new DomainException('Only PAPER accounts are supported.');
            }
            if ($setup->state !== 'VALID' || $setup->expires_at?->isPast()) {
                throw new DomainException('Paper orders may only be created from an unexpired VALID setup.');
            }
            if ($setup->paperOrders()->whereIn('state', ['PENDING', 'FILLED'])->exists()) {
                throw new DomainException('This setup already has an active paper order.');
            }
            if ((float) ($input['volume'] ?? 0) <= 0) {
                throw new DomainException('Paper order volume must be greater than zero.');
            }
            $volume = (float) $input['volume'];
            $minimumVolume = (float) config('diamond.paper.volume_min', 0.01);
            $maximumVolume = (float) config('diamond.paper.volume_max', 100);
            $volumeStep = (float) config('diamond.paper.volume_step', 0.01);
            $steps = $volumeStep > 0 ? $volume / $volumeStep : 0;
            if ($volume < $minimumVolume || $volume > $maximumVolume
                || $volumeStep <= 0 || abs($steps - round($steps)) > 1.0e-8) {
                throw new DomainException('Paper order volume violates the configured minimum, maximum, or step.');
            }
            $volume = (float) $input['volume'];
            $minimum = (float) config('diamond.paper.volume_min', 0.01);
            $maximum = (float) config('diamond.paper.volume_max', 100);
            $step = (float) config('diamond.paper.volume_step', 0.01);
            $steps = round($volume / $step);
            if ($volume < $minimum || $volume > $maximum || abs($volume - ($steps * $step)) > 1e-8) {
                throw new DomainException('Paper order volume violates the configured minimum, maximum, or step.');
            }
            if (! in_array($input['order_type'] ?? null, ['MARKET', 'LIMIT', 'STOP'], true)) {
                throw new DomainException('Unsupported paper order type.');
            }
            if ($input['order_type'] !== 'MARKET' && (float) ($input['requested_price'] ?? 0) <= 0) {
                throw new DomainException('LIMIT and STOP orders require a positive requested price.');
            }

            $order = PaperOrder::query()->create([
                'order_key' => (string) Str::uuid(),
                'trading_account_id' => $account->id,
                'trade_setup_id' => $setup->id,
                'order_type' => $input['order_type'],
                'side' => $setup->direction,
                'volume' => $input['volume'],
                'requested_price' => $input['requested_price'] ?? null,
                'cost_assumptions' => $costs,
                'state' => 'PENDING',
                'create_idempotency_key' => $idempotencyKey,
            ]);
            $this->audit->record('paper_order.created', $order, null, $order->toArray(), $idempotencyKey, [], $actorId);
            return $order;
        }, 3);
    }

    public function confirm(PaperOrder $order, array $quote, string $idempotencyKey, ?int $actorId = null): Position
    {
        return DB::transaction(function () use ($order, $quote, $idempotencyKey, $actorId): Position {
            $order = PaperOrder::query()->with(['account.riskProfile', 'setup.analysisRun'])->lockForUpdate()->findOrFail($order->id);
            $idempotent = PaperOrder::query()->where('confirm_idempotency_key', $idempotencyKey)->first();
            if ($idempotent?->position) {
                if ($idempotent->id !== $order->id) {
                    throw new DomainException('Idempotency key was already used for a different confirmation.');
                }
                return $idempotent->position;
            }
            if ($order->state !== 'PENDING') {
                throw new DomainException('Only a PENDING paper order can be confirmed.');
            }
            if ($order->setup->state !== 'VALID' || $order->setup->expires_at?->isPast()) {
                throw new DomainException('The source setup is no longer VALID.');
            }
            if (in_array($order->setup->analysisRun->event_state, ['HIGH', 'POST_EVENT_STABILIZATION'], true)) {
                throw new DomainException('Paper confirmation is blocked by the analysis event window.');
            }
            $this->assertQuote($quote);
            $this->assertOrderIsFillable($order, $quote);
            $ledger = $this->risk->lockedLedger($order->account);

            $costs = $order->cost_assumptions;
            $slippage = (float) $costs['slippage_price'];
            $fill = $order->side === 'BUY'
                ? (float) $quote['ask'] + $slippage
                : (float) $quote['bid'] - $slippage;
            $commission = round((float) $costs['commission_round_trip_per_lot'] * (float) $order->volume, 8);
            $risk = round(
                abs($fill - (float) $order->setup->stop_price)
                * (float) $costs['contract_size']
                * (float) $order->volume
                + $commission,
                8,
            );
            if ($risk <= 0) {
                throw new DomainException('Initial risk must be greater than zero.');
            }
            $riskProfile = $order->account->riskProfile;
            if ($riskProfile === null) {
                throw new DomainException('A risk profile is required before paper trading.');
            }
            $perTradeCap = (float) $order->account->equity
                * ((float) $riskProfile->risk_per_trade_percent / 100);
            if ($risk > $perTradeCap) {
                throw new DomainException('Paper order risk exceeds the configured per-trade risk limit.');
            }
            $this->risk->assertCanOpen($order->account, $ledger, $risk);

            $before = $order->toArray();
            $order->forceFill([
                'state' => 'FILLED', 'filled_price' => $fill,
                'quote_bid' => $quote['bid'], 'quote_ask' => $quote['ask'],
                'slippage_amount' => round($slippage * (float) $costs['contract_size'] * (float) $order->volume, 8),
                'commission_amount' => $commission, 'confirm_idempotency_key' => $idempotencyKey,
                'confirmed_at' => now(), 'filled_at' => now(),
            ])->save();

            $position = Position::query()->create([
                'trading_account_id' => $order->trading_account_id, 'paper_order_id' => $order->id,
                'symbol' => $order->setup->analysisRun->symbol, 'side' => $order->side,
                'average_entry' => $fill, 'volume' => $order->volume,
                'stop_price' => $order->setup->stop_price, 'targets' => $order->setup->targets,
                'initial_risk_amount' => $risk, 'commission_paid' => 0,
                'unrealized_pl' => 0, 'state' => 'OPEN', 'opened_at' => now(),
            ]);
            $snapshot = $this->entrySnapshot($order);
            $trade = Trade::query()->create([
                'position_id' => $position->id, 'opened_at' => now(), 'entry_price' => $fill,
                'initial_risk_amount' => $risk, 'entry_snapshot' => $snapshot,
                'snapshot_version' => 1,
                'entry_snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)),
            ]);
            $this->risk->opened($ledger, $risk);
            $this->lifecycle->transition($order->setup, 'OPEN', ['PAPER_ORDER_FILLED'], $idempotencyKey);
            $this->audit->record('paper_order.confirmed', $order, $before, $order->fresh()->toArray(), $idempotencyKey, [
                'spread_embedded_in_bid_ask' => true, 'slippage_embedded_in_fill' => true,
                'commission_deferred_until_close' => true,
            ], $actorId);
            $this->audit->record('position.opened', $position, null, $position->toArray(), $idempotencyKey, [
                'trade_id' => $trade->id,
            ], $actorId);
            return $position->fresh(['trade', 'order']);
        }, 3);
    }

    public function cancel(PaperOrder $order, string $reason, string $idempotencyKey, ?int $actorId = null): PaperOrder
    {
        return DB::transaction(function () use ($order, $reason, $idempotencyKey, $actorId): PaperOrder {
            $order = PaperOrder::query()->lockForUpdate()->findOrFail($order->id);
            $idempotent = PaperOrder::query()->where('cancel_idempotency_key', $idempotencyKey)->first();
            if ($idempotent) {
                if ($idempotent->id !== $order->id) {
                    throw new DomainException('Idempotency key was already used for a different cancellation.');
                }
                return $idempotent;
            }
            if ($order->state !== 'PENDING') {
                throw new DomainException('Only a PENDING order can be cancelled.');
            }
            $before = $order->toArray();
            $order->forceFill([
                'state' => 'CANCELLED', 'cancel_idempotency_key' => $idempotencyKey,
                'cancelled_at' => now(), 'cancellation_reason' => $reason,
            ])->save();
            $this->audit->record('paper_order.cancelled', $order, $before, $order->fresh()->toArray(), $idempotencyKey, [], $actorId);
            return $order->fresh();
        }, 3);
    }

    public function close(Position $position, array $input, string $idempotencyKey, ?int $actorId = null): Trade
    {
        return DB::transaction(function () use ($position, $input, $idempotencyKey, $actorId): Trade {
            $position = Position::query()->with(['trade', 'order.account.riskProfile', 'order.setup'])->lockForUpdate()->findOrFail($position->id);
            $idempotent = Trade::query()->where('close_idempotency_key', $idempotencyKey)->first();
            if ($idempotent) {
                if ($idempotent->position_id !== $position->id) {
                    throw new DomainException('Idempotency key was already used for a different close.');
                }
                return $idempotent;
            }
            if ($position->state !== 'OPEN' || $position->trade->closed_at !== null) {
                throw new DomainException('Only an OPEN position can be closed.');
            }
            if (! in_array($input['exit_reason'] ?? null, ['STOP_LOSS', 'TARGET', 'MANUAL', 'SETUP_INVALIDATED', 'SESSION_END'], true)) {
                throw new DomainException('Unsupported position exit reason.');
            }
            $this->assertQuote($input);
            $costs = $position->order->cost_assumptions;
            $slippage = (float) $costs['slippage_price'];
            $exit = $position->side === 'BUY'
                ? (float) $input['bid'] - $slippage
                : (float) $input['ask'] + $slippage;
            $direction = $position->side === 'BUY' ? 1 : -1;
            $gross = round(($exit - (float) $position->average_entry) * $direction
                * (float) $costs['contract_size'] * (float) $position->volume, 8);
            $commission = (float) $position->order->commission_amount;
            $net = round($gross - $commission, 8);
            $rMultiple = round($net / (float) $position->initial_risk_amount, 6);

            $trade = $position->trade;
            $trade->forceFill([
                'closed_at' => now(), 'exit_price' => $exit, 'exit_bid' => $input['bid'],
                'exit_ask' => $input['ask'], 'gross_pl' => $gross, 'total_costs' => $commission,
                'realized_pl' => $net, 'r_multiple' => $rMultiple,
                'exit_reason' => $input['exit_reason'], 'close_idempotency_key' => $idempotencyKey,
            ])->save();
            $position->forceFill([
                'state' => 'CLOSED', 'closed_at' => now(), 'unrealized_pl' => 0,
                'commission_paid' => $commission,
            ])->save();

            $account = $position->order->account;
            $ledger = $this->risk->lockedLedger($account);
            $this->risk->closed($account, $ledger, (float) $position->initial_risk_amount, $net);
            $account->forceFill(['equity' => round((float) $account->equity + $net, 8), 'equity_as_of' => now()])->save();
            $this->lifecycle->transition($position->order->setup, 'CLOSED', [$input['exit_reason']], $idempotencyKey);
            $this->audit->record('position.closed', $position, ['state' => 'OPEN'], $position->fresh()->toArray(), $idempotencyKey, [
                'gross_pl' => $gross, 'commission_once' => $commission, 'net_pl' => $net,
            ], $actorId);
            return $trade->fresh();
        }, 3);
    }

    private function costs(array $costs): array
    {
        $normalized = [
            'contract_size' => (float) ($costs['contract_size'] ?? config('diamond.paper.contract_size', 100)),
            'slippage_price' => (float) ($costs['slippage_price'] ?? config('diamond.paper.slippage_price', 0)),
            'commission_round_trip_per_lot' => (float) ($costs['commission_round_trip_per_lot'] ?? config('diamond.paper.commission_round_trip_per_lot', 0)),
            'spread_treatment' => 'EMBEDDED_IN_BID_ASK',
        ];
        if ($normalized['contract_size'] <= 0 || $normalized['slippage_price'] < 0 || $normalized['commission_round_trip_per_lot'] < 0) {
            throw new DomainException('Paper cost assumptions are invalid.');
        }
        return $normalized;
    }

    private function assertQuote(array $quote): void
    {
        if (! isset($quote['bid'], $quote['ask'])
            || (float) $quote['bid'] <= 0
            || (float) $quote['ask'] <= 0
            || (float) $quote['ask'] < (float) $quote['bid']) {
            throw new DomainException('A valid positive bid/ask quote is required.');
        }
        if (isset($quote['timestamp']) && ! ($quote['isMock'] ?? false)) {
            $timestamp = new DateTimeImmutable((string) $quote['timestamp']);
            $age = time() - $timestamp->getTimestamp();
            if ($age < -(int) config('diamond.quality.max_future_skew_seconds', 30)) {
                throw new DomainException('Provider quote timestamp is in the future.');
            }
            if ($age > (int) config('diamond.quality.max_quote_age_seconds', 300)) {
                throw new DomainException('Provider quote is stale.');
            }
        }
    }

    private function assertOrderIsFillable(PaperOrder $order, array $quote): void
    {
        if ($order->setup->stop_price === null || empty($order->setup->targets)) {
            throw new DomainException('The setup no longer has a complete stop and target definition.');
        }
        $marketPrice = $order->side === 'BUY' ? (float) $quote['ask'] : (float) $quote['bid'];
        if ($marketPrice < (float) $order->setup->entry_low || $marketPrice > (float) $order->setup->entry_high) {
            throw new DomainException('The current executable price is outside the setup entry range.');
        }
        $requested = (float) $order->requested_price;
        $triggered = match ($order->order_type) {
            'MARKET' => true,
            'LIMIT' => $order->side === 'BUY' ? $marketPrice <= $requested : $marketPrice >= $requested,
            'STOP' => $order->side === 'BUY' ? $marketPrice >= $requested : $marketPrice <= $requested,
            default => false,
        };
        if (! $triggered) {
            throw new DomainException('The requested LIMIT or STOP price has not been reached.');
        }
    }

    private function entrySnapshot(PaperOrder $order): array
    {
        $analysis = $order->setup->analysisRun;
        return [
            'snapshot_version' => 1,
            'captured_at' => now()->toISOString(),
            'analysis' => $analysis->toArray(),
            'strategy' => ['version' => $analysis->strategy_version],
            'configuration' => ['hash' => $analysis->configuration_hash],
            'fundamental_inputs' => $analysis->fundamental_snapshot,
            'risk_inputs' => $order->account->riskProfile?->toArray(),
            'setup' => $order->setup->toArray(),
            'execution' => [
                'mode' => 'PAPER', 'order_key' => $order->order_key,
                'bid' => $order->quote_bid, 'ask' => $order->quote_ask,
                'fill' => $order->filled_price, 'cost_assumptions' => $order->cost_assumptions,
            ],
        ];
    }
}
