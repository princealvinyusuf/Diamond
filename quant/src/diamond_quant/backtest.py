"""Dependency-free, deterministic closed-candle historical replay.

Prices are mid OHLC. ``CostModel`` converts entries/exits to executable
bid/ask prices, so spread is paid exactly once across the round trip.
Commission is a single round-trip cash amount deducted once at exit.
"""

from __future__ import annotations

from dataclasses import asdict, dataclass
from datetime import datetime
from decimal import Decimal
from hashlib import sha256
import json
import random
from typing import Callable, Protocol, Sequence

from .domain import Candle
from .technical import Pivot, confirmed_pivots

D = Decimal
ENGINE_VERSION = "diamond-replay/1.0.0"


@dataclass(frozen=True, slots=True)
class Signal:
    side: str
    stop: Decimal
    target: Decimal
    setup_family: str
    regime: str
    alignment: str
    score: int

    def __post_init__(self) -> None:
        if self.side not in {"BUY", "SELL"}:
            raise ValueError("side must be BUY or SELL")
        if not 0 <= self.score <= 100:
            raise ValueError("score must be between 0 and 100")


@dataclass(frozen=True, slots=True)
class ReplayContext:
    """Information available immediately after the current candle closes."""

    candles: tuple[Candle, ...]
    confirmed_pivots: tuple[Pivot, ...]
    index: int
    partition: str
    rng: random.Random


Strategy = Callable[[ReplayContext], Signal | None]


@dataclass(frozen=True, slots=True)
class CostModel:
    spread: Decimal = D("0")
    commission_round_turn: Decimal = D("0")

    def __post_init__(self) -> None:
        if self.spread < 0 or self.commission_round_turn < 0:
            raise ValueError("costs cannot be negative")

    def entry(self, mid: Decimal, side: str) -> Decimal:
        half = self.spread / D(2)
        return mid + half if side == "BUY" else mid - half

    def exit(self, mid: Decimal, side: str) -> Decimal:
        half = self.spread / D(2)
        return mid - half if side == "BUY" else mid + half


@dataclass(frozen=True, slots=True)
class PartitionPlan:
    train_end: datetime
    validation_end: datetime

    def label(self, at: datetime) -> str:
        if at <= self.train_end:
            return "TRAIN"
        if at <= self.validation_end:
            return "VALIDATION"
        return "OUT_OF_SAMPLE"


@dataclass(frozen=True, slots=True)
class WalkForwardFold:
    train_start: int
    train_end: int
    test_start: int
    test_end: int


def walk_forward_folds(
    size: int, train_size: int, test_size: int, step: int | None = None
) -> tuple[WalkForwardFold, ...]:
    if min(size, train_size, test_size) <= 0:
        raise ValueError("sizes must be positive")
    stride = step or test_size
    if stride <= 0:
        raise ValueError("step must be positive")
    folds: list[WalkForwardFold] = []
    start = 0
    while start + train_size + test_size <= size:
        folds.append(
            WalkForwardFold(start, start + train_size, start + train_size,
                            start + train_size + test_size)
        )
        start += stride
    return tuple(folds)


@dataclass(frozen=True, slots=True)
class TradeResult:
    entered_at: datetime
    exited_at: datetime
    side: str
    entry: Decimal
    stop: Decimal
    target: Decimal
    exit: Decimal
    pnl: Decimal
    r_multiple: Decimal
    exit_reason: str
    bars_held: int
    partition: str
    setup_family: str
    regime: str
    alignment: str
    score: int


class IntrabarResolver(Protocol):
    def resolve(self, bar: Candle, signal: Signal) -> str | None:
        """Return STOP, TARGET, or None using lower-timeframe bars."""


@dataclass(slots=True)
class _OpenTrade:
    signal: Signal
    entered_at: datetime
    entry: Decimal
    risk: Decimal
    partition: str
    entry_index: int


@dataclass(frozen=True, slots=True)
class BacktestResult:
    run_id: str
    engine_version: str
    strategy_version: str
    seed: int
    trades: tuple[TradeResult, ...]
    metrics: dict[str, object]


class ReplayEngine:
    def __init__(
        self,
        *,
        initial_equity: Decimal,
        cost_model: CostModel,
        seed: int,
        strategy_version: str,
        partition_plan: PartitionPlan,
        intrabar_resolver: IntrabarResolver | None = None,
    ) -> None:
        if initial_equity <= 0:
            raise ValueError("initial_equity must be positive")
        self.initial_equity = initial_equity
        self.cost_model = cost_model
        self.seed = seed
        self.strategy_version = strategy_version
        self.partition_plan = partition_plan
        self.intrabar_resolver = intrabar_resolver

    def run(self, candles: Sequence[Candle], strategy: Strategy) -> BacktestResult:
        closed = tuple(c for c in candles if c.is_closed)
        self._validate_candles(closed)
        rng = random.Random(self.seed)
        trades: list[TradeResult] = []
        position: _OpenTrade | None = None
        pending: Signal | None = None

        for index, bar in enumerate(closed):
            if pending is not None and position is None:
                entry = self.cost_model.entry(bar.open, pending.side)
                valid = pending.stop < entry < pending.target if pending.side == "BUY" else (
                    pending.target < entry < pending.stop
                )
                if valid:
                    position = _OpenTrade(
                        pending, bar.open_time, entry, abs(entry - pending.stop),
                        self.partition_plan.label(bar.open_time), index,
                    )
                pending = None

            if position is not None:
                outcome = self._outcome(bar, position.signal)
                if outcome is not None:
                    mid_exit = position.signal.stop if outcome == "STOP" else position.signal.target
                    exit_price = self.cost_model.exit(mid_exit, position.signal.side)
                    direction = D(1) if position.signal.side == "BUY" else D(-1)
                    gross = direction * (exit_price - position.entry)
                    pnl = gross - self.cost_model.commission_round_turn
                    trades.append(
                        TradeResult(
                            position.entered_at, bar.open_time, position.signal.side,
                            position.entry, position.signal.stop, position.signal.target,
                            exit_price, pnl, pnl / position.risk,
                            outcome, index - position.entry_index + 1, position.partition,
                            position.signal.setup_family, position.signal.regime,
                            position.signal.alignment, position.signal.score,
                        )
                    )
                    position = None

            if position is None and index < len(closed) - 1:
                visible = closed[: index + 1]
                context = ReplayContext(
                    visible,
                    tuple(confirmed_pivots(visible)),
                    index,
                    self.partition_plan.label(bar.open_time),
                    rng,
                )
                pending = strategy(context)

        if position is not None:
            bar = closed[-1]
            exit_price = self.cost_model.exit(bar.close, position.signal.side)
            direction = D(1) if position.signal.side == "BUY" else D(-1)
            pnl = direction * (exit_price - position.entry) - self.cost_model.commission_round_turn
            trades.append(
                TradeResult(
                    position.entered_at, bar.open_time, position.signal.side, position.entry,
                    position.signal.stop, position.signal.target, exit_price, pnl,
                    pnl / position.risk, "END_OF_DATA",
                    len(closed) - position.entry_index, position.partition,
                    position.signal.setup_family, position.signal.regime,
                    position.signal.alignment, position.signal.score,
                )
            )

        run_id = self._run_id(closed)
        return BacktestResult(
            run_id, ENGINE_VERSION, self.strategy_version, self.seed, tuple(trades),
            calculate_metrics(tuple(trades), self.initial_equity, len(closed)),
        )

    def _outcome(self, bar: Candle, signal: Signal) -> str | None:
        stop_hit = bar.low <= signal.stop if signal.side == "BUY" else bar.high >= signal.stop
        target_hit = bar.high >= signal.target if signal.side == "BUY" else bar.low <= signal.target
        if stop_hit and target_hit:
            if self.intrabar_resolver is not None:
                resolved = self.intrabar_resolver.resolve(bar, signal)
                if resolved in {"STOP", "TARGET"}:
                    return resolved
            return "STOP"  # conservative H4 ambiguity rule
        if stop_hit:
            return "STOP"
        if target_hit:
            return "TARGET"
        return None

    @staticmethod
    def _validate_candles(candles: Sequence[Candle]) -> None:
        if not candles:
            raise ValueError("at least one closed candle is required")
        if any(candles[i].open_time >= candles[i + 1].open_time for i in range(len(candles) - 1)):
            raise ValueError("candles must be strictly chronological and unique")

    def _run_id(self, candles: Sequence[Candle]) -> str:
        identity = {
            "engine": ENGINE_VERSION,
            "strategy": self.strategy_version,
            "seed": self.seed,
            "initialEquity": str(self.initial_equity),
            "costModel": {
                "spread": str(self.cost_model.spread),
                "commissionRoundTurn": str(self.cost_model.commission_round_turn),
            },
            "dataset": [
                [c.open_time.isoformat(), *map(str, (c.open, c.high, c.low, c.close))]
                for c in candles
            ],
        }
        return sha256(json.dumps(identity, sort_keys=True).encode()).hexdigest()


def _decimal_stats(values: Sequence[Decimal]) -> dict[str, str]:
    if not values:
        return {"count": "0", "total": "0", "average": "0", "min": "0", "max": "0"}
    return {
        "count": str(len(values)),
        "total": str(sum(values, D(0))),
        "average": str(sum(values, D(0)) / D(len(values))),
        "min": str(min(values)),
        "max": str(max(values)),
    }


def calculate_metrics(
    trades: Sequence[TradeResult], initial_equity: Decimal, total_bars: int
) -> dict[str, object]:
    pnl = [trade.pnl for trade in trades]
    wins = [value for value in pnl if value > 0]
    losses = [value for value in pnl if value < 0]
    rs = [trade.r_multiple for trade in trades]
    net = sum(pnl, D(0))
    peak = initial_equity
    equity = initial_equity
    max_drawdown = D(0)
    max_drawdown_percent = D(0)
    loss_streak = longest_loss_streak = 0
    for value in pnl:
        equity += value
        peak = max(peak, equity)
        max_drawdown = max(max_drawdown, peak - equity)
        max_drawdown_percent = max(
            max_drawdown_percent, (peak - equity) / peak if peak else D(0)
        )
        loss_streak = loss_streak + 1 if value < 0 else 0
        longest_loss_streak = max(longest_loss_streak, loss_streak)
    gross_profit = sum(wins, D(0))
    gross_loss = abs(sum(losses, D(0)))
    average_win = gross_profit / D(len(wins)) if wins else D(0)
    average_loss = gross_loss / D(len(losses)) if losses else D(0)

    def breakdown(field: str) -> dict[str, object]:
        keys = sorted({str(getattr(trade, field)) for trade in trades})
        return {
            key: {
                "trades": len(group := [t for t in trades if str(getattr(t, field)) == key]),
                "netPl": str(sum((t.pnl for t in group), D(0))),
                "expectancyR": str(sum((t.r_multiple for t in group), D(0)) / D(len(group))),
                "winRate": str(D(sum(t.pnl > 0 for t in group)) / D(len(group))),
            }
            for key in keys
        }

    buckets: dict[str, object] = {}
    for low in range(0, 100, 10):
        group = [t for t in trades if low <= t.score <= (100 if low == 90 else low + 9)]
        if group:
            buckets[f"{low:02d}-{100 if low == 90 else low + 9:02d}"] = {
                "trades": len(group),
                "observedWinRate": str(D(sum(t.pnl > 0 for t in group)) / D(len(group))),
                "averageR": str(sum((t.r_multiple for t in group), D(0)) / D(len(group))),
            }

    return {
        "netPl": str(net),
        "netReturn": str(net / initial_equity),
        "rDistribution": {
            **_decimal_stats(rs),
            "positive": sum(value > 0 for value in rs),
            "negative": sum(value < 0 for value in rs),
            "zero": sum(value == 0 for value in rs),
            "buckets": {
                "belowMinusOne": sum(value < -1 for value in rs),
                "minusOneToZero": sum(-1 <= value < 0 for value in rs),
                "zeroToOne": sum(0 <= value < 1 for value in rs),
                "oneOrMore": sum(value >= 1 for value in rs),
            },
        },
        "expectancyR": str(sum(rs, D(0)) / D(len(rs))) if rs else "0",
        "profitFactor": str(gross_profit / gross_loss) if gross_loss else (None if not wins else "Infinity"),
        "maxDrawdown": str(max_drawdown),
        "maxDrawdownPercent": str(max_drawdown_percent),
        "winRate": str(D(len(wins)) / D(len(trades))) if trades else "0",
        "averageWin": str(average_win),
        "averageLoss": str(average_loss),
        "payoffRatio": str(average_win / average_loss) if average_loss else None,
        "maxConsecutiveLosses": longest_loss_streak,
        "exposure": str(D(sum(t.bars_held for t in trades)) / D(total_bars)) if total_bars else "0",
        "tradeCount": len(trades),
        "breakdowns": {
            "setupFamily": breakdown("setup_family"),
            "regime": breakdown("regime"),
            "alignment": breakdown("alignment"),
            "partition": breakdown("partition"),
        },
        "scoreCalibration": buckets,
    }


def serialize_result(result: BacktestResult) -> dict[str, object]:
    def convert(value: object) -> object:
        if isinstance(value, Decimal):
            return str(value)
        if isinstance(value, datetime):
            return value.isoformat()
        return value

    trades = [{key: convert(value) for key, value in asdict(trade).items()} for trade in result.trades]
    return {
        "runId": result.run_id,
        "engineVersion": result.engine_version,
        "strategyVersion": result.strategy_version,
        "seed": result.seed,
        "metrics": result.metrics,
        "trades": trades,
    }
