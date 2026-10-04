<?php

return [
    'trading_mode' => env('TRADING_MODE', 'paper'),
    'live_trading_enabled' => filter_var(env('LIVE_TRADING_ENABLED', false), FILTER_VALIDATE_BOOL),
    'strategy_version' => env('STRATEGY_VERSION', 'h4-gold-v1'),
    'market_data_provider' => env('MARKET_DATA_PROVIDER', 'mock'),
    'twelve_data' => [
        'api_key' => env('TWELVE_DATA_API_KEY', ''),
        'base_url' => env('TWELVE_DATA_BASE_URL', 'https://api.twelvedata.com'),
    ],
    'quality' => [
        'max_quote_age_seconds' => (int) env('MARKET_MAX_QUOTE_AGE_SECONDS', 300),
        'max_future_skew_seconds' => (int) env('MARKET_MAX_FUTURE_SKEW_SECONDS', 30),
        'max_spread' => (float) env('MARKET_MAX_SPREAD', 1.0),
    ],
    'providers' => [
        'connect_timeout_seconds' => (int) env('PROVIDER_CONNECT_TIMEOUT_SECONDS', 3),
        'timeout_seconds' => (int) env('PROVIDER_TIMEOUT_SECONDS', 10),
        'retry_attempts' => (int) env('PROVIDER_RETRY_ATTEMPTS', 2),
        'retry_delay_ms' => (int) env('PROVIDER_RETRY_DELAY_MS', 250),
        'stale_after_minutes' => (int) env('PROVIDER_STALE_AFTER_MINUTES', 10),
    ],
    'rate_limits' => [
        'analysis' => (int) env('RATE_LIMIT_ANALYSIS_PER_MINUTE', 12),
        'backtests' => (int) env('RATE_LIMIT_BACKTESTS_PER_MINUTE', 5),
        'paper' => (int) env('RATE_LIMIT_PAPER_PER_MINUTE', 30),
    ],
    'operations' => [
        'missed_h4_after_minutes' => (int) env('MISSED_H4_AFTER_MINUTES', 20),
    ],
    'paper' => [
        'contract_size' => (float) env('PAPER_CONTRACT_SIZE', 100),
        'slippage_price' => (float) env('PAPER_SLIPPAGE_PRICE', 0.05),
        'commission_round_trip_per_lot' => (float) env('PAPER_COMMISSION_ROUND_TRIP_PER_LOT', 7),
        'loss_cooldown_minutes' => (int) env('PAPER_LOSS_COOLDOWN_MINUTES', 1440),
        'volume_min' => (float) env('PAPER_VOLUME_MIN', 0.01),
        'volume_max' => (float) env('PAPER_VOLUME_MAX', 100),
        'volume_step' => (float) env('PAPER_VOLUME_STEP', 0.01),
    ],
    'backtest' => [
        'python' => env('BACKTEST_PYTHON', 'python'),
    ],
    'analysis' => [
        'python' => env('ANALYSIS_PYTHON', env('BACKTEST_PYTHON', 'python')),
        'timeout_seconds' => (int) env('ANALYSIS_TIMEOUT_SECONDS', 10),
    ],
];
