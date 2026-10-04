<?php

namespace App\Providers;

use App\Domain\Fundamental\CalendarProvider;
use App\Domain\Fundamental\FundamentalProvider;
use App\Domain\MarketData\MarketDataProvider;
use App\Infrastructure\Fundamental\FixtureResearchProvider;
use App\Infrastructure\MarketData\MockMarketDataProvider;
use App\Infrastructure\MarketData\TwelveDataMarketDataProvider;
use App\Infrastructure\ProviderHealthRecorder;
use App\Models\RiskProfile;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ((bool) config('diamond.live_trading_enabled', false)) {
            throw new RuntimeException('Live trading is not supported. Set LIVE_TRADING_ENABLED=false.');
        }

        if (strtolower((string) config('diamond.trading_mode', 'paper')) !== 'paper') {
            throw new RuntimeException('Foundation supports paper mode only.');
        }

        $this->app->singleton(MarketDataProvider::class, function (): MarketDataProvider {
            $provider = config('diamond.market_data_provider', 'mock');
            return match ($provider) {
                'mock' => new MockMarketDataProvider(
                    healthRecorder: $this->app->make(ProviderHealthRecorder::class),
                ),
                'twelve-data' => new TwelveDataMarketDataProvider(
                    (string) config('diamond.twelve_data.api_key'),
                    (string) config('diamond.twelve_data.base_url'),
                    $this->app->make(ProviderHealthRecorder::class),
                ),
                default => throw new RuntimeException("Unknown market-data provider: {$provider}"),
            };
        });
        $this->app->singleton(FixtureResearchProvider::class);
        $this->app->alias(FixtureResearchProvider::class, FundamentalProvider::class);
        $this->app->alias(FixtureResearchProvider::class, CalendarProvider::class);
    }

    public function boot(): void
    {
        RateLimiter::for('analysis', fn (Request $request) => Limit::perMinute(
            (int) config('diamond.rate_limits.analysis', 12),
        )->by($request->user()?->getAuthIdentifier() ?: $request->ip()));
        RateLimiter::for('backtests', fn (Request $request) => Limit::perMinute(
            (int) config('diamond.rate_limits.backtests', 5),
        )->by($request->user()?->getAuthIdentifier() ?: $request->ip()));
        RateLimiter::for('paper', fn (Request $request) => Limit::perMinute(
            (int) config('diamond.rate_limits.paper', 30),
        )->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        RiskProfile::updated(function (RiskProfile $profile): void {
            $fields = [
                'risk_per_trade_percent', 'daily_loss_cap_percent', 'max_trades_per_day',
                'loss_streak_limit', 'minimum_rr', 'daily_profit_target',
            ];
            $changes = array_intersect_key($profile->getChanges(), array_flip($fields));
            if ($changes === []) {
                return;
            }
            app(\App\Domain\PaperTrading\AuditLogger::class)->record(
                'risk_profile.overridden',
                $profile,
                array_intersect_key($profile->getOriginal(), $changes),
                $changes,
                request()?->header('X-Request-ID'),
                ['source' => 'model_observer'],
                request()?->user()?->getAuthIdentifier(),
            );
        });
    }
}
