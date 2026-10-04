<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('analysis_runs', function (Blueprint $table): void {
            $table->string('schedule_key', 160)->nullable()->unique();
            $table->index(['symbol', 'timeframe', 'as_of']);
        });

        Schema::table('backtest_runs', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->index(['state', 'created_at']);
        });

        Schema::table('paper_orders', function (Blueprint $table): void {
            $table->index(['trading_account_id', 'state', 'created_at']);
        });
        Schema::table('positions', function (Blueprint $table): void {
            $table->unique('paper_order_id');
            $table->index(['trading_account_id', 'state']);
        });
        Schema::table('trades', function (Blueprint $table): void {
            $table->index(['closed_at', 'realized_pl']);
        });
        Schema::table('provider_health', function (Blueprint $table): void {
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('source_at')->nullable();
            $table->index(['is_stale', 'last_success_at']);
        });
        Schema::table('audit_log', function (Blueprint $table): void {
            $table->index(['action', 'occurred_at']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE paper_orders ADD CONSTRAINT paper_orders_volume_positive CHECK (volume > 0)');
            DB::statement('ALTER TABLE market_quotes ADD CONSTRAINT market_quotes_valid_prices CHECK (bid > 0 AND ask >= bid)');
            DB::statement('ALTER TABLE risk_profiles ADD CONSTRAINT risk_profiles_no_martingale CHECK (martingale_enabled = 0)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE paper_orders DROP CHECK paper_orders_volume_positive');
            DB::statement('ALTER TABLE market_quotes DROP CHECK market_quotes_valid_prices');
            DB::statement('ALTER TABLE risk_profiles DROP CHECK risk_profiles_no_martingale');
        }

        Schema::table('audit_log', fn (Blueprint $table) => $table->dropIndex(['action', 'occurred_at']));
        Schema::table('provider_health', function (Blueprint $table): void {
            $table->dropIndex(['is_stale', 'last_success_at']);
            $table->dropColumn(['consecutive_failures', 'last_attempt_at', 'last_failure_at', 'source_at']);
        });
        Schema::table('trades', fn (Blueprint $table) => $table->dropIndex(['closed_at', 'realized_pl']));
        Schema::table('positions', function (Blueprint $table): void {
            $table->dropUnique(['paper_order_id']);
            $table->dropIndex(['trading_account_id', 'state']);
        });
        Schema::table('paper_orders', fn (Blueprint $table) => $table->dropIndex(['trading_account_id', 'state', 'created_at']));
        Schema::table('backtest_runs', function (Blueprint $table): void {
            $table->dropIndex(['state', 'created_at']);
            $table->dropConstrainedForeignId('user_id');
        });
        Schema::table('analysis_runs', function (Blueprint $table): void {
            $table->dropUnique(['schedule_key']);
            $table->dropIndex(['symbol', 'timeframe', 'as_of']);
            $table->dropColumn('schedule_key');
        });
    }
};
