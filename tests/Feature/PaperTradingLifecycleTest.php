<?php

namespace Tests\Feature;

use App\Domain\PaperTrading\PaperTradingService;
use App\Domain\PaperTrading\SetupLifecycle;
use App\Models\AnalysisRun;
use App\Models\RiskProfile;
use App\Models\TradeSetup;
use App\Models\TradingAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PaperTradingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_order_opens_and_closes_with_costs_exactly_once(): void
    {
        [$account, $setup] = $this->scenario();
        $service = app(PaperTradingService::class);

        $order = $service->createOrder($account, $setup, [
            'order_type' => 'MARKET', 'volume' => 0.04,
            'costs' => [
                'contract_size' => 100, 'slippage_price' => 0.05,
                'commission_round_trip_per_lot' => 7,
            ],
        ], 'create-1');
        self::assertSame('PENDING', $order->state);
        self::assertDatabaseMissing('positions', ['paper_order_id' => $order->id]);

        $position = $service->confirm($order, ['bid' => 2649.80, 'ask' => 2650.20], 'confirm-1');
        self::assertSame('2650.25000000', $position->average_entry);
        self::assertSame('OPEN', $position->state);
        self::assertSame($position->id, $service->confirm($order, ['bid' => 1, 'ask' => 2], 'confirm-1')->id);

        $trade = $service->close($position, [
            'bid' => 2660, 'ask' => 2660.40, 'exit_reason' => 'TARGET',
        ], 'close-1');
        self::assertSame('2659.95000000', $trade->exit_price);
        self::assertSame('38.80000000', $trade->gross_pl);
        self::assertSame('0.28000000', $trade->total_costs);
        self::assertSame('38.52000000', $trade->realized_pl);
        self::assertSame($trade->id, $service->close($position, [
            'bid' => 2000, 'ask' => 2001, 'exit_reason' => 'RETRY',
        ], 'close-1')->id);
        self::assertDatabaseHas('daily_risk_ledgers', [
            'trading_account_id' => $account->id, 'trades_count' => 1,
            'consecutive_losses' => 0,
        ]);
    }

    public function test_setup_state_machine_rejects_skipping_confirmation_states(): void
    {
        [, $setup] = $this->scenario('WATCHING');
        $this->expectException(\DomainException::class);
        app(SetupLifecycle::class)->transition($setup, 'OPEN');
    }

    public function test_api_requires_idempotency_and_uses_provider_quote_for_confirmation(): void
    {
        [$account, $setup] = $this->scenario();

        $this->postJson('/api/v1/paper/orders', [
            'trading_account_id' => $account->id, 'trade_setup_id' => $setup->id,
            'order_type' => 'MARKET', 'volume' => 0.1,
        ])->assertUnprocessable();

        $created = $this->withHeader('Idempotency-Key', 'api-create-1')
            ->postJson('/api/v1/paper/orders', [
                'trading_account_id' => $account->id, 'trade_setup_id' => $setup->id,
                'order_type' => 'MARKET', 'volume' => 0.04,
            ])->assertCreated()->json();

        $this->withHeader('Idempotency-Key', 'api-confirm-1')
            ->postJson("/api/v1/paper/orders/{$created['id']}/confirm", [
                'bid' => 1, 'ask' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('average_entry', '2650.25000000');
    }

    public function test_pending_order_cancellation_is_audited_and_not_deleted(): void
    {
        [$account, $setup] = $this->scenario();
        $service = app(PaperTradingService::class);
        $order = $service->createOrder($account, $setup, [
            'order_type' => 'MARKET', 'volume' => 0.1,
        ], 'create-cancel');
        $cancelled = $service->cancel($order, 'User withdrew confirmation', 'cancel-1');
        self::assertSame('CANCELLED', $cancelled->state);
        self::assertDatabaseHas('paper_orders', ['id' => $order->id, 'state' => 'CANCELLED']);
        self::assertDatabaseHas('audit_log', ['action' => 'paper_order.cancelled', 'subject_id' => (string) $order->id]);
    }

    public function test_volume_below_minimum_is_rejected(): void
    {
        [$account, $setup] = $this->scenario();
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('minimum');
        app(PaperTradingService::class)->createOrder($account, $setup, [
            'order_type' => 'MARKET', 'volume' => 0.001,
        ], 'below-minimum');
    }

    public function test_confirmation_rejects_volume_that_exceeds_per_trade_risk(): void
    {
        [$account, $setup] = $this->scenario();
        $service = app(PaperTradingService::class);
        $order = $service->createOrder($account, $setup, [
            'order_type' => 'MARKET', 'volume' => 0.1,
        ], 'unsafe-risk-create');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('per-trade risk limit');
        $service->confirm($order, ['bid' => 2649.8, 'ask' => 2650.2], 'unsafe-risk-confirm');
    }

    public function test_high_impact_event_state_blocks_confirmation(): void
    {
        [$account, $setup] = $this->scenario();
        $setup->analysisRun->update(['event_state' => 'HIGH']);
        $order = app(PaperTradingService::class)->createOrder($account, $setup, [
            'order_type' => 'MARKET', 'volume' => 0.1,
        ], 'event-create');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('event window');
        app(PaperTradingService::class)->confirm($order, [
            'bid' => 2649.8, 'ask' => 2650.2,
        ], 'event-confirm');
    }

    public function test_stale_and_future_non_mock_quotes_fail_closed(): void
    {
        [$account, $setup] = $this->scenario();
        $service = app(PaperTradingService::class);
        $order = $service->createOrder($account, $setup, [
            'order_type' => 'MARKET', 'volume' => 0.1,
        ], 'stale-create');

        foreach ([
            now()->subHour()->toISOString() => 'stale',
            now()->addHour()->toISOString() => 'future',
        ] as $timestamp => $message) {
            try {
                $service->confirm($order, [
                    'bid' => 2649.8, 'ask' => 2650.2,
                    'timestamp' => $timestamp, 'isMock' => false,
                ], 'quote-'.$message);
                self::fail("Expected {$message} quote to be rejected.");
            } catch (\DomainException $exception) {
                self::assertStringContainsString($message, strtolower($exception->getMessage()));
            }
        }
        self::assertSame('PENDING', $order->fresh()->state);
    }

    private function scenario(string $state = 'VALID'): array
    {
        $account = TradingAccount::query()->create([
            'name' => 'Test paper', 'currency' => 'USD', 'equity' => 10000, 'mode' => 'PAPER',
        ]);
        RiskProfile::query()->create([
            'trading_account_id' => $account->id, 'risk_per_trade_percent' => 0.5,
            'daily_loss_cap_percent' => 2, 'max_trades_per_day' => 3,
            'loss_streak_limit' => 2, 'minimum_rr' => 2, 'martingale_enabled' => false,
        ]);
        $analysis = AnalysisRun::query()->create([
            'run_key' => fake()->uuid(), 'symbol' => 'XAUUSD', 'timeframe' => 'H4',
            'strategy_version' => 'test-v1', 'configuration_hash' => str_repeat('a', 64),
            'as_of' => now(), 'market_bias' => 'BULLISH', 'event_state' => 'LOW',
            'final_action' => 'BUY', 'market_snapshot' => ['bid' => 2649.8, 'ask' => 2650.2],
            'fundamental_snapshot' => ['usd' => 'neutral'], 'is_mock' => true,
        ]);
        $setup = TradeSetup::query()->create([
            'analysis_run_id' => $analysis->id, 'direction' => 'BUY', 'state' => $state,
            'entry_low' => 2648, 'entry_high' => 2652, 'stop_price' => 2640,
            'targets' => [2670], 'quality_score' => 80, 'expires_at' => now()->addDay(),
            'reasons' => ['TEST'],
        ]);
        return [$account->fresh('riskProfile'), $setup];
    }
}
