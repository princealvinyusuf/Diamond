<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('trade_setups', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('state_changed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
        });

        Schema::table('paper_orders', function (Blueprint $table): void {
            $table->string('create_idempotency_key', 128)->unique();
            $table->string('confirm_idempotency_key', 128)->nullable()->unique();
            $table->string('cancel_idempotency_key', 128)->nullable()->unique();
            $table->decimal('quote_bid', 20, 8)->nullable();
            $table->decimal('quote_ask', 20, 8)->nullable();
            $table->decimal('slippage_amount', 20, 8)->default(0);
            $table->decimal('commission_amount', 20, 8)->default(0);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
        });

        Schema::table('positions', function (Blueprint $table): void {
            $table->decimal('initial_risk_amount', 20, 8)->default(0);
            $table->decimal('commission_paid', 20, 8)->default(0);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
        });

        Schema::table('trades', function (Blueprint $table): void {
            $table->unsignedInteger('snapshot_version')->default(1);
            $table->char('entry_snapshot_hash', 64)->nullable();
            $table->string('close_idempotency_key', 128)->nullable()->unique();
            $table->decimal('exit_bid', 20, 8)->nullable();
            $table->decimal('exit_ask', 20, 8)->nullable();
            $table->decimal('gross_pl', 20, 8)->nullable();
            $table->decimal('total_costs', 20, 8)->default(0);
        });

        Schema::table('daily_risk_ledgers', function (Blueprint $table): void {
            $table->timestamp('cooldown_until')->nullable();
        });

        Schema::table('audit_log', function (Blueprint $table): void {
            $table->string('request_id', 128)->nullable()->index();
            $table->json('metadata')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table): void {
            $table->dropIndex(['request_id']);
            $table->dropColumn(['request_id', 'metadata']);
        });
        Schema::table('daily_risk_ledgers', fn (Blueprint $table) => $table->dropColumn('cooldown_until'));
        Schema::table('trades', function (Blueprint $table): void {
            $table->dropUnique(['close_idempotency_key']);
            $table->dropColumn(['snapshot_version', 'entry_snapshot_hash', 'close_idempotency_key', 'exit_bid', 'exit_ask', 'gross_pl', 'total_costs']);
        });
        Schema::table('positions', fn (Blueprint $table) => $table->dropColumn(['initial_risk_amount', 'commission_paid', 'opened_at', 'closed_at']));
        Schema::table('paper_orders', function (Blueprint $table): void {
            $table->dropUnique(['create_idempotency_key']);
            $table->dropUnique(['confirm_idempotency_key']);
            $table->dropUnique(['cancel_idempotency_key']);
            $table->dropColumn([
                'create_idempotency_key', 'confirm_idempotency_key', 'cancel_idempotency_key',
                'quote_bid', 'quote_ask', 'slippage_amount', 'commission_amount',
                'confirmed_at', 'cancelled_at', 'cancellation_reason',
            ]);
        });
        Schema::table('trade_setups', fn (Blueprint $table) => $table->dropColumn(['version', 'state_changed_at', 'cancelled_at']));
    }
};
