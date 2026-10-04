<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\BacktestController;
use App\Http\Controllers\PaperTradingController;
use App\Http\Controllers\PerformanceController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('dashboard');
Route::get('/contracts/v1/decision', [DashboardController::class, 'contract'])
    ->name('contracts.decision.v1');

Route::prefix('api/v1')->group(function (): void {
    Route::get('/settings', [SettingsController::class, 'show'])->name('settings.show');
    Route::put('/settings', [SettingsController::class, 'update'])
        ->middleware('mutation-owner')->name('settings.update');
    Route::get('/health', HealthController::class)->middleware('throttle:analysis');
    Route::middleware('throttle:analysis')->group(function (): void {
        Route::get('/market/quote', [ApiController::class, 'quote']);
        Route::get('/market/candles', [ApiController::class, 'candles']);
        Route::get('/fundamentals', [ApiController::class, 'fundamentals']);
        Route::get('/calendar', [ApiController::class, 'calendar']);
        Route::get('/analysis/latest', [ApiController::class, 'latest']);
        Route::post('/analysis/run', [ApiController::class, 'run']);
        Route::post('/risk/quote', [ApiController::class, 'riskQuote']);
    });
    Route::middleware('throttle:backtests')->group(function (): void {
        Route::post('/backtests', [BacktestController::class, 'store'])
            ->middleware('mutation-owner')->name('backtests.store');
        Route::get('/backtests/{backtestRun}', [BacktestController::class, 'show'])
            ->name('backtests.show');
    });
    Route::get('/performance', PerformanceController::class);

    Route::middleware('throttle:paper')->prefix('paper')->group(function (): void {
        Route::get('/orders', [PaperTradingController::class, 'index']);
        Route::post('/orders', [PaperTradingController::class, 'create'])->middleware('mutation-owner');
        Route::post('/orders/{paperOrder}/confirm', [PaperTradingController::class, 'confirm'])->middleware('mutation-owner');
        Route::post('/orders/{paperOrder}/cancel', [PaperTradingController::class, 'cancel'])->middleware('mutation-owner');
        Route::get('/positions', [PaperTradingController::class, 'positions']);
        Route::post('/positions/{position}/close', [PaperTradingController::class, 'close'])->middleware('mutation-owner');
        Route::get('/journal', [PaperTradingController::class, 'journal']);
    });
});
