# Diamond

Risk-first XAUUSD H4 decision-support platform with historical backtesting.
It includes read-only market/research ports, deterministic technical analysis,
quality gates, decision orchestration, paper trading, and dependency-light
historical replay/analytics. It does **not** implement live trading.

## Safety status

- Paper mode is the only accepted application/account mode.
- Live execution has no provider interface, route, controller, or JSON contract.
- Enabling `LIVE_TRADING_ENABLED` or changing `TRADING_MODE` makes boot fail.
- The database constrains account mode to `PAPER`.
- Fixture responses carry `isMock: true`; decisions include ordered, machine-readable reasons.
- Twelve Data support is read-only and opt-in with `MARKET_DATA_PROVIDER=twelve-data`.
- Daily profit target is display-only; martingale is prohibited by schema and model.

Nothing in this repository is financial advice. Mock values are illustrative
and must not be interpreted as current market data.

## Stack

- PHP 8.2+, Laravel 12, Inertia 2, React 19, TypeScript, Vite
- MySQL/MariaDB and Laravel database queue/cache/session
- Python 3.12 package with dependency-free domain models and provider protocols
- Versioned JSON Schema contracts in `contracts/v1`

Authentication is intentionally absent from the foundation.
Outside `local`/`testing`, persistent mutation routes therefore fail closed
until authenticated ownership is added.

## XAMPP setup

1. Install Composer, Node.js, and Python separately if they are not available.
2. Place/clone this directory under `C:\xampp\htdocs\Diamond`.
3. In phpMyAdmin create a UTF-8 database named `diamond`.
4. Run:

   ```powershell
   Copy-Item .env.example .env
   composer install
   php artisan key:generate
   php artisan migrate --seed
   npm install
   npm run build
   ```

5. Keep Apache `mod_rewrite` enabled and browse to
   `http://localhost/Diamond/public`.
6. Process queued jobs with
   `php artisan queue:work --queue=analysis,backtests,default`, and run
   `php artisan schedule:work` for idempotent UTC H4 analysis.

Detailed security, provider, scheduler, backup/restore, troubleshooting, and
paper-production readiness guidance is in
[`docs/windows-setup.md`](docs/windows-setup.md).

For a virtual host, point `DocumentRoot` at this repository's `public`
directory and grant `AllowOverride All`. Never expose the project root.

## Development checks

```powershell
composer test
npm run typecheck
npm run build
python -m pip install -e "quant[dev]"
python -m pytest quant/tests
python -m ruff check quant
python -m mypy quant/src
```

## Decision-support API

Fixture-backed by default under `/api/v1`: `market/quote`, `market/candles`,
`fundamentals`, `calendar`, `analysis/latest`, `analysis/run`, and `risk/quote`.
The two calculation endpoints are POST requests, but neither persists or
creates orders. Fixture timestamps and values are illustrative, never live.

The Python engine in `quant/src/diamond_quant/technical.py` deterministically
computes EMA, RSI, MACD, ATR, ADX, confirmed pivots/structure, ATR-normalized
zones, breakout/retest evidence, timeframe alignment, setup family, and score.
Laravel invokes it through a bounded read-only JSON process bridge for both
`analysis/run` and scheduled H4 analysis. The bridge accepts only normalized,
ascending, closed D1/H4/H1 candles, times out after
`ANALYSIS_TIMEOUT_SECONDS`, validates output strictly, and converts every
failure to an explicit unavailable state. It never supplies invented entry,
stop, target, risk/reward, or volume values, so those decision gates remain
closed until separately validated.

When `MARKET_DATA_PROVIDER=twelve-data`, the dashboard requests the same
provider/analysis path and plots returned H4 OHLC data with Lightweight Charts.
Provider failures show an unavailable panel with no substituted prices. In
the default mock mode, the existing chart remains conspicuously marked as a
static illustrative fixture.

The replay engine in `quant/src/diamond_quant/backtest.py` enforces
closed-candle availability, delayed pivot confirmation, conservative
same-candle exits, explicit once-only costs, deterministic run identities,
dataset partitions, walk-forward folds, and performance/calibration metrics.

Backtests are submitted with `POST /api/v1/backtests`, polled with
`GET /api/v1/backtests/{id}`, and processed on the `backtests` database queue.
Paper-account strategy metrics are available from `GET /api/v1/performance`.
Operational status and missed-job/provider-stale metrics are available from
`GET /api/v1/health`.
See `docs/backtesting.md` for contracts and replay assumptions.

## Layout

- `app/`, `bootstrap/`, `config/`, `database/`, `public/`, `resources/`,
  `routes/`: Laravel/Inertia application
- `contracts/v1/`: shared, versioned language-neutral contracts
- `fixtures/`: conspicuously labeled mock interface data
- `quant/`: Python domain, read-only provider ports, and technical engine
- `tests/`: PHP contract, HTTP, and safety-invariant tests

The single foundation migration creates every core blueprint entity plus
Laravel's database queue, cache, and session tables. Analysis records preserve
strategy/configuration versions and immutable source snapshots for future
reproducibility.
