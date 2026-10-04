from datetime import datetime, timedelta, timezone
from decimal import Decimal as D

from diamond_quant.backtest import (
    CostModel,
    PartitionPlan,
    ReplayEngine,
    Signal,
    walk_forward_folds,
)
from diamond_quant.domain import Candle, Timeframe


START = datetime(2025, 1, 1, tzinfo=timezone.utc)


def candle(index: int, open_: str, high: str, low: str, close: str, closed: bool = True) -> Candle:
    return Candle(
        "fixture", "XAUUSD", Timeframe.H4, START + timedelta(hours=4 * index),
        D(open_), D(high), D(low), D(close), D("1"), closed,
    )


def engine(*, spread: str = "0", commission: str = "0") -> ReplayEngine:
    return ReplayEngine(
        initial_equity=D("100"),
        cost_model=CostModel(D(spread), D(commission)),
        seed=7,
        strategy_version="test/1",
        partition_plan=PartitionPlan(
            START + timedelta(hours=4), START + timedelta(hours=8)
        ),
    )


def test_replay_exposes_only_closed_history_and_enters_next_bar() -> None:
    bars = [
        candle(0, "100", "101", "99", "100"),
        candle(1, "100", "103", "99", "102"),
        candle(2, "102", "106", "101", "105"),
        candle(3, "105", "110", "104", "109", closed=False),
    ]
    visible_lengths: list[int] = []

    def strategy(context):
        visible_lengths.append(len(context.candles))
        if context.index == 0:
            return Signal("BUY", D("98"), D("105"), "BREAKOUT", "TREND", "ALIGNED", 75)
        return None

    result = engine().run(bars, strategy)

    assert visible_lengths == [1]
    assert result.trades[0].entered_at == bars[1].open_time
    assert result.trades[0].exited_at == bars[2].open_time
    assert result.trades[0].partition == "TRAIN"


def test_same_bar_stop_and_target_is_conservatively_a_stop() -> None:
    bars = [candle(0, "100", "101", "99", "100"), candle(1, "100", "106", "94", "101")]

    result = engine().run(
        bars,
        lambda context: Signal(
            "BUY", D("95"), D("105"), "REVERSAL", "RANGE", "MIXED", 50
        ),
    )

    assert result.trades[0].exit_reason == "STOP"
    assert result.trades[0].pnl == D("-5")


def test_pivot_is_not_visible_until_right_side_candles_have_closed() -> None:
    bars = [
        candle(0, "100", "101", "99", "100"),
        candle(1, "100", "103", "99", "101"),
        candle(2, "101", "110", "100", "102"),
        candle(3, "102", "104", "99", "101"),
        candle(4, "101", "103", "98", "100"),
        candle(5, "100", "102", "97", "99"),
    ]
    observations: list[tuple[int, tuple[int, ...]]] = []

    def strategy(context):
        observations.append((context.index, tuple(p.index for p in context.confirmed_pivots)))
        return None

    engine().run(bars, strategy)

    assert 2 not in dict(observations)[3]
    assert 2 in dict(observations)[4]


def test_spread_and_round_turn_commission_are_counted_once() -> None:
    bars = [candle(0, "100", "101", "99", "100"), candle(1, "100", "111", "99", "110")]
    result = engine(spread="2", commission="1").run(
        bars,
        lambda context: Signal(
            "BUY", D("95"), D("110"), "TREND", "TREND", "ALIGNED", 80
        ),
    )

    trade = result.trades[0]
    assert trade.entry == D("101")
    assert trade.exit == D("109")
    assert trade.pnl == D("7")  # 10 midpoint move - 2 spread - 1 commission
    assert result.metrics["netPl"] == "7"


def test_run_identity_is_seeded_versioned_and_deterministic() -> None:
    bars = [candle(0, "100", "101", "99", "100"), candle(1, "100", "101", "99", "100")]
    strategy = lambda context: None

    first = engine().run(bars, strategy)
    second = engine().run(bars, strategy)

    assert first.run_id == second.run_id
    assert first.seed == 7
    assert first.engine_version == "diamond-replay/1.0.0"


def test_walk_forward_builds_rolling_non_overlapping_test_windows() -> None:
    folds = walk_forward_folds(size=20, train_size=8, test_size=4)

    assert [(fold.train_start, fold.train_end, fold.test_start, fold.test_end) for fold in folds] == [
        (0, 8, 8, 12),
        (4, 12, 12, 16),
        (8, 16, 16, 20),
    ]
