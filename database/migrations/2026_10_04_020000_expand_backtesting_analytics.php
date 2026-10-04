<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('backtest_runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('seed')->default(0);
            $table->string('engine_version')->default('diamond-replay/1.0.0');
            $table->json('partitions')->nullable();
            $table->json('walk_forward')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
        });

        Schema::table('backtest_trades', function (Blueprint $table): void {
            $table->string('partition', 32)->nullable()->index();
            $table->string('setup_family', 64)->nullable()->index();
            $table->string('regime', 64)->nullable()->index();
            $table->string('alignment', 64)->nullable()->index();
            $table->unsignedTinyInteger('score')->nullable()->index();
            $table->string('exit_reason', 64)->nullable();
            $table->unsignedInteger('bars_held')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('backtest_trades', function (Blueprint $table): void {
            $table->dropIndex(['partition']);
            $table->dropIndex(['setup_family']);
            $table->dropIndex(['regime']);
            $table->dropIndex(['alignment']);
            $table->dropIndex(['score']);
            $table->dropColumn([
                'partition', 'setup_family', 'regime', 'alignment', 'score',
                'exit_reason', 'bars_held',
            ]);
        });
        Schema::table('backtest_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'seed', 'engine_version', 'partitions', 'walk_forward',
                'error_message', 'started_at', 'finished_at',
            ]);
        });
    }
};
