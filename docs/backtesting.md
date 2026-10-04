# Backtesting and analytics

This phase is historical research and paper-performance reporting only. It
does not add a broker, live-order route, execution adapter, or live mode.

## Replay invariants

- The replay loop discards every candle whose `is_closed` flag is false.
- A strategy is called after candle close with an immutable prefix ending at
  that candle. A signal can enter only at the next closed candle's open.
- Pivots are passed through `confirmed_pivots`; a pivot with a right width of
  two is therefore unavailable until two later closed candles exist.
- Fixture OHLC values are midpoint prices. Entry and exit convert midpoint to
  executable ask/bid using one fixed spread. This charges one complete spread
  over a round trip. `commissionRoundTurn` is then deducted once at exit.
- If an H4 candle touches both stop and target, stop wins. An
  `IntrabarResolver` can reconstruct ordering from lower-timeframe data.
- Runs record engine version, strategy version, unsigned seed, canonical
  configuration hash, dataset key, and a content-derived Python run identity.
- Train, validation, and out-of-sample labels are assigned from explicit UTC
  boundaries. `walk_forward_folds` creates rolling train/test index windows;
  API requests persist optional train/test/step sizes with the run.

## API

`POST /api/v1/backtests` validates, persists, and dispatches a database queue
job. It returns HTTP 202 and a `Location` header. Example:

```json
{
  "datasetKey": "xauusd-h4-demo-v1",
  "strategyVersion": "reference-breakout/1.0.0",
  "seed": 7,
  "initialEquity": 10000,
  "costModel": {"spread": 0.4, "commissionRoundTurn": 7},
  "partitions": {
    "trainEnd": "2025-01-01T12:00:00Z",
    "validationEnd": "2025-01-02T04:00:00Z"
  },
  "walkForward": {"trainBars": 100, "testBars": 25, "stepBars": 25}
}
```

`GET /api/v1/backtests/{id}` reports `QUEUED`, `RUNNING`, `COMPLETED`, or
`FAILED`, timestamps, versions, and results/error. The queue worker invokes
the dependency-free Python module with a controlled versioned fixture key.
Set `BACKTEST_PYTHON` when the Python executable is not named `python`.

`GET /api/v1/performance?accountId=1&strategyVersion=...` calculates metrics
only from closed paper trades. It never mixes historical backtest trades with
paper-account trades.

## Metrics

Results include net P/L and return, R distribution and expectancy, profit
factor, maximum drawdown, win rate, average win/loss and payoff, maximum
consecutive losses, bar exposure, setup-family/regime/alignment/partition
breakdowns, and ten-point score-bucket calibration.

The dashboard analytics are deliberately fixture-backed and labeled
synthetic/illustrative. They are not fetched from either API and are not
actual performance.
