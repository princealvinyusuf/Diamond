"""JSON bridge used by the Laravel database-queue worker."""

from __future__ import annotations

import argparse
from datetime import datetime
from decimal import Decimal
import json
from pathlib import Path

from .backtest import (
    CostModel,
    PartitionPlan,
    ReplayEngine,
    Signal,
    serialize_result,
    walk_forward_folds,
)
from .domain import Candle, Timeframe

D = Decimal


def _time(value: str) -> datetime:
    parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    if parsed.tzinfo is None:
        raise ValueError("timestamps must include a timezone")
    return parsed


def _strategy(context):
    """Small, versioned reference strategy; decisions use closed bars only."""
    if len(context.candles) < 3:
        return None
    current = context.candles[-1]
    previous = context.candles[-2]
    distance = abs(current.close - current.open)
    if distance == 0:
        return None
    if current.close > previous.high:
        return Signal(
            "BUY", current.close - distance, current.close + distance * D(2),
            "BREAKOUT_RETEST", "TREND", "ALIGNED", 70,
        )
    if current.close < previous.low:
        return Signal(
            "SELL", current.close + distance, current.close - distance * D(2),
            "BREAKOUT_RETEST", "TREND", "ALIGNED", 70,
        )
    return None


def execute(request: dict[str, object], root: Path) -> dict[str, object]:
    dataset_key = str(request["datasetKey"])
    if not dataset_key.replace("-", "").replace("_", "").isalnum():
        raise ValueError("invalid dataset key")
    payload = json.loads((root / "fixtures" / f"{dataset_key}.json").read_text(encoding="utf-8"))
    candles = [
        Candle(
            "historical-fixture", "XAUUSD", Timeframe.H4, _time(row["openTime"]),
            D(str(row["open"])), D(str(row["high"])), D(str(row["low"])),
            D(str(row["close"])), D(str(row["volume"])) if row.get("volume") is not None else None,
            bool(row.get("isClosed", True)),
        )
        for row in payload["candles"]
    ]
    costs = request["costModel"]
    partitions = request["partitions"]
    engine = ReplayEngine(
        initial_equity=D(str(request["initialEquity"])),
        cost_model=CostModel(
            D(str(costs["spread"])), D(str(costs["commissionRoundTurn"]))
        ),
        seed=int(request["seed"]),
        strategy_version=str(request["strategyVersion"]),
        partition_plan=PartitionPlan(
            _time(str(partitions["trainEnd"])), _time(str(partitions["validationEnd"]))
        ),
    )
    result = serialize_result(engine.run(candles, _strategy))
    walk_forward = request.get("walkForward")
    fold_results: list[dict[str, object]] = []
    if isinstance(walk_forward, dict):
        train_bars = int(walk_forward["trainBars"])
        test_bars = int(walk_forward["testBars"])
        step_bars = int(walk_forward.get("stepBars", test_bars))
        for index, fold in enumerate(
            walk_forward_folds(len(candles), train_bars, test_bars, step_bars)
        ):
            test_candles = candles[fold.test_start:fold.test_end]
            fold_engine = ReplayEngine(
                initial_equity=D(str(request["initialEquity"])),
                cost_model=engine.cost_model,
                seed=int(request["seed"]) + index,
                strategy_version=str(request["strategyVersion"]),
                partition_plan=engine.partition_plan,
            )
            fold_result = fold_engine.run(test_candles, _strategy)
            fold_results.append({
                "fold": index + 1,
                "trainStart": fold.train_start,
                "trainEnd": fold.train_end,
                "testStart": fold.test_start,
                "testEnd": fold.test_end,
                "metrics": fold_result.metrics,
            })
    result["walkForward"] = fold_results
    return result


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--request", required=True, type=Path)
    parser.add_argument("--root", required=True, type=Path)
    args = parser.parse_args()
    request = json.loads(args.request.read_text(encoding="utf-8"))
    print(json.dumps(execute(request, args.root), sort_keys=True, separators=(",", ":")))


if __name__ == "__main__":
    main()
