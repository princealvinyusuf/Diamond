<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('timezone')->default('UTC');
            $table->json('preferences')->nullable();
            $table->timestamps();
        });

        Schema::create('trading_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->char('currency', 3)->default('USD');
            $table->decimal('equity', 20, 8)->default(0);
            $table->enum('mode', ['PAPER'])->default('PAPER');
            $table->timestamp('equity_as_of')->nullable();
            $table->timestamps();
        });

        Schema::create('broker_symbol_specs', function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->string('symbol', 32);
            $table->char('currency', 3);
            $table->decimal('contract_size', 20, 8);
            $table->decimal('tick_size', 20, 10);
            $table->decimal('tick_value', 20, 8);
            $table->decimal('volume_min', 16, 8);
            $table->decimal('volume_max', 16, 8);
            $table->decimal('volume_step', 16, 8);
            $table->timestamp('effective_at');
            $table->json('provenance')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'symbol', 'effective_at']);
        });

        Schema::create('risk_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trading_account_id')->constrained()->cascadeOnDelete();
            $table->decimal('risk_per_trade_percent', 7, 4)->default(0.5000);
            $table->decimal('daily_loss_cap_percent', 7, 4)->default(2.0000);
            $table->unsignedTinyInteger('max_trades_per_day')->default(3);
            $table->unsignedTinyInteger('loss_streak_limit')->default(3);
            $table->decimal('minimum_rr', 8, 4)->default(2);
            $table->decimal('daily_profit_target', 20, 8)->nullable()->comment('Display only; never used for sizing');
            $table->boolean('martingale_enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('market_bars', function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->string('symbol', 32);
            $table->enum('timeframe', ['H1', 'H4', 'D1']);
            $table->timestamp('ts_open');
            $table->decimal('open', 20, 8);
            $table->decimal('high', 20, 8);
            $table->decimal('low', 20, 8);
            $table->decimal('close', 20, 8);
            $table->decimal('volume', 24, 8)->nullable();
            $table->boolean('is_closed')->default(false);
            $table->json('quality_flags')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'symbol', 'timeframe', 'ts_open'], 'market_bars_canonical_unique');
        });

        Schema::create('market_quotes', function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->string('symbol', 32);
            $table->decimal('bid', 20, 8);
            $table->decimal('ask', 20, 8);
            $table->timestamp('quoted_at');
            $table->enum('freshness', ['FRESH', 'STALE', 'DEGRADED', 'UNAVAILABLE']);
            $table->timestamps();
            $table->unique(['provider', 'symbol', 'quoted_at']);
        });

        Schema::create('analysis_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_key')->unique();
            $table->string('symbol', 32)->default('XAUUSD');
            $table->enum('timeframe', ['H4'])->default('H4');
            $table->string('strategy_version');
            $table->char('configuration_hash', 64);
            $table->timestamp('as_of');
            $table->enum('market_bias', ['STRONG_BULLISH', 'BULLISH', 'NEUTRAL', 'BEARISH', 'STRONG_BEARISH']);
            $table->smallInteger('technical_score')->nullable();
            $table->smallInteger('fundamental_score')->nullable();
            $table->enum('event_state', ['LOW', 'ELEVATED', 'HIGH', 'POST_EVENT_STABILIZATION']);
            $table->enum('final_action', ['BUY', 'SELL', 'WAIT_FOR_CONFIRMATION', 'NO_TRADE']);
            $table->json('market_snapshot');
            $table->json('fundamental_snapshot')->nullable();
            $table->json('block_reasons')->nullable();
            $table->boolean('is_mock')->default(false);
            $table->timestamps();
        });

        Schema::create('indicator_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('analysis_run_id')->constrained()->cascadeOnDelete();
            $table->enum('timeframe', ['H1', 'H4', 'D1']);
            $table->json('indicators');
            $table->json('derived_features')->nullable();
            $table->timestamp('calculated_at');
            $table->timestamps();
            $table->unique(['analysis_run_id', 'timeframe']);
        });

        Schema::create('fundamental_observations', function (Blueprint $table): void {
            $table->id();
            $table->string('source');
            $table->string('series_key');
            $table->decimal('actual', 24, 10)->nullable();
            $table->decimal('prior', 24, 10)->nullable();
            $table->decimal('consensus', 24, 10)->nullable();
            $table->decimal('revision', 24, 10)->nullable();
            $table->timestamp('observed_at');
            $table->timestamp('vintage_at');
            $table->json('provenance');
            $table->timestamps();
            $table->unique(['source', 'series_key', 'observed_at', 'vintage_at'], 'fundamental_vintage_unique');
        });

        Schema::create('economic_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->string('external_event_id');
            $table->string('event_name');
            $table->char('currency', 3)->default('USD');
            $table->enum('importance', ['LOW', 'MEDIUM', 'HIGH', 'VERY_HIGH']);
            $table->enum('status', ['SCHEDULED', 'RELEASED', 'REVISED', 'CANCELLED']);
            $table->timestamp('scheduled_at');
            $table->decimal('actual', 24, 10)->nullable();
            $table->decimal('consensus', 24, 10)->nullable();
            $table->decimal('prior', 24, 10)->nullable();
            $table->json('provenance')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'external_event_id']);
            $table->index(['scheduled_at', 'importance']);
        });

        Schema::create('trade_setups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('analysis_run_id')->constrained()->cascadeOnDelete();
            $table->enum('direction', ['BUY', 'SELL']);
            $table->enum('state', ['WATCHING', 'ARMED', 'VALID', 'BLOCKED', 'EXPIRED', 'OPEN', 'CLOSED']);
            $table->decimal('entry_low', 20, 8)->nullable();
            $table->decimal('entry_high', 20, 8)->nullable();
            $table->decimal('stop_price', 20, 8)->nullable();
            $table->json('targets')->nullable();
            $table->unsignedTinyInteger('quality_score')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('reasons');
            $table->timestamps();
        });

        Schema::create('paper_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('order_key')->unique();
            $table->foreignId('trading_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trade_setup_id')->constrained()->restrictOnDelete();
            $table->enum('order_type', ['MARKET', 'LIMIT', 'STOP']);
            $table->enum('side', ['BUY', 'SELL']);
            $table->decimal('volume', 16, 8);
            $table->decimal('requested_price', 20, 8)->nullable();
            $table->decimal('filled_price', 20, 8)->nullable();
            $table->json('cost_assumptions');
            $table->enum('state', ['PENDING', 'FILLED', 'CANCELLED', 'REJECTED']);
            $table->timestamp('filled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('positions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trading_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('paper_order_id')->constrained()->restrictOnDelete();
            $table->string('symbol', 32);
            $table->enum('side', ['BUY', 'SELL']);
            $table->decimal('average_entry', 20, 8);
            $table->decimal('volume', 16, 8);
            $table->decimal('stop_price', 20, 8)->nullable();
            $table->json('targets')->nullable();
            $table->decimal('unrealized_pl', 20, 8)->default(0);
            $table->enum('state', ['OPEN', 'CLOSED']);
            $table->timestamps();
        });

        Schema::create('trades', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('position_id')->constrained()->restrictOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('entry_price', 20, 8);
            $table->decimal('exit_price', 20, 8)->nullable();
            $table->decimal('initial_risk_amount', 20, 8);
            $table->decimal('realized_pl', 20, 8)->nullable();
            $table->decimal('r_multiple', 12, 6)->nullable();
            $table->string('exit_reason')->nullable();
            $table->json('entry_snapshot');
            $table->timestamps();
        });

        Schema::create('daily_risk_ledgers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trading_account_id')->constrained()->cascadeOnDelete();
            $table->date('session_date');
            $table->string('session_timezone')->default('UTC');
            $table->decimal('starting_equity', 20, 8);
            $table->decimal('realized_pl', 20, 8)->default(0);
            $table->decimal('open_risk', 20, 8)->default(0);
            $table->unsignedSmallInteger('trades_count')->default(0);
            $table->unsignedSmallInteger('consecutive_losses')->default(0);
            $table->boolean('is_locked')->default(false);
            $table->string('lock_reason')->nullable();
            $table->timestamps();
            $table->unique(['trading_account_id', 'session_date']);
        });

        Schema::create('backtest_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_key')->unique();
            $table->string('strategy_version');
            $table->string('dataset_key');
            $table->char('configuration_hash', 64);
            $table->json('parameters');
            $table->json('cost_model');
            $table->enum('state', ['QUEUED', 'RUNNING', 'COMPLETED', 'FAILED']);
            $table->json('results_summary')->nullable();
            $table->timestamps();
        });

        Schema::create('backtest_trades', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('backtest_run_id')->constrained()->cascadeOnDelete();
            $table->json('setup_snapshot');
            $table->timestamp('entered_at');
            $table->timestamp('exited_at');
            $table->decimal('entry_price', 20, 8);
            $table->decimal('exit_price', 20, 8);
            $table->decimal('realized_pl', 20, 8);
            $table->decimal('r_multiple', 12, 6);
            $table->timestamps();
        });

        Schema::create('provider_health', function (Blueprint $table): void {
            $table->id();
            $table->string('provider')->unique();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->boolean('is_stale')->default(true);
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_log', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_type')->default('system');
            $table->string('actor_id')->nullable();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('occurred_at');
            $table->index(['subject_type', 'subject_id']);
        });

        $this->createFrameworkTables();
    }

    private function createFrameworkTables(): void
    {
        Schema::create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        Schema::create('cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::create('job_batches', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach ([
            'failed_jobs', 'job_batches', 'jobs', 'sessions', 'cache_locks', 'cache',
            'audit_log', 'provider_health', 'backtest_trades', 'backtest_runs',
            'daily_risk_ledgers', 'trades', 'positions', 'paper_orders', 'trade_setups',
            'economic_events', 'fundamental_observations', 'indicator_snapshots',
            'analysis_runs', 'market_quotes', 'market_bars', 'risk_profiles',
            'broker_symbol_specs', 'trading_accounts', 'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
