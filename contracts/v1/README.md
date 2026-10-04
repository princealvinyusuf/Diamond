# Shared contracts v1

These JSON Schemas are the language-neutral boundary between the Laravel web
application and the Python quant package. Changes within `v1` must remain
backward-compatible; breaking changes require a new version directory.

All timestamps are UTC ISO-8601 values. Decimal prices and volumes travel as
JSON numbers at the interface and are persisted in MySQL `DECIMAL` columns.
Consumers must reject unknown enum values and invalid OHLC/symbol data.

The fixtures are illustrative mock data only. `executionMode` is fixed to
`PAPER`; there is deliberately no live-order contract. Decision responses may
include `pipelineTrace`, an ordered data→risk→event→regime→bias→setup→economics
→sizing audit trail. Provider health fields mirror the `provider_health`
persistence columns and do not imply trading connectivity.

`paper-trading.schema.json` defines the paper order, position and journal
lifecycle. Paper writes require an `Idempotency-Key` header. Creating an order
never fills it: the caller must explicitly confirm the pending order. Fill and
close prices come from the configured read-only market-data provider, never
from request payloads.

`backtest.schema.json` defines asynchronous historical replay status/results.
Backtest records are separate from paper trades and cannot create positions or
orders.

`technical-analysis-request.schema.json` defines the normalized closed-candle
input, and `technical-analysis.schema.json` defines deterministic D1/H4/H1
features, confirmed structure, alignment, setup evidence, and score returned
by the Python bridge. A decision may embed that result or an explicit
structured `UNAVAILABLE` error; consumers must never infer or substitute
analysis.
