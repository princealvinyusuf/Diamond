<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('risk_profiles', function (Blueprint $table): void {
            $table->unsignedSmallInteger('loss_cooldown_minutes')->default(1440);
        });
    }

    public function down(): void
    {
        Schema::table('risk_profiles', function (Blueprint $table): void {
            $table->dropColumn('loss_cooldown_minutes');
        });
    }
};
