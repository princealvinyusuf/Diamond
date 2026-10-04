from datetime import datetime, timedelta, timezone
from decimal import Decimal

from diamond_quant.domain import Candle, Timeframe
from diamond_quant.technical import (
    analyze,
    breakout_retest,
    confirmed_pivots,
    ema,
    market_structure,
    quality_score,
    setup_family,
)


def candles(prices: list[int]) -> list[Candle]:
    start = datetime(2025, 1, 1, tzinfo=timezone.utc)
    return [
        Candle(
            provider="fixture",
            symbol="XAUUSD",
            timeframe=Timeframe.H4,
            open_time=start + timedelta(hours=4 * index),
            open=Decimal(price),
            high=Decimal(price + 2),
            low=Decimal(price - 2),
            close=Decimal(price),
            volume=None,
            is_closed=True,
        )
        for index, price in enumerate(prices)
    ]


def test_indicators_are_deterministic_and_warmed_up() -> None:
    series = candles(list(range(2000, 2220)))
    first = analyze(series)
    assert first == analyze(series)
    assert first["ema20"] == ema([c.close for c in series], 20)[-1]
    assert all(first[key] is not None for key in ("ema200", "rsi14", "macd", "atr14", "adx14"))


def test_pivots_require_right_side_confirmation_and_classify_structure() -> None:
    series = candles([10, 12, 15, 12, 11, 13, 17, 14, 12])
    pivots = confirmed_pivots(series)
    assert [(pivot.kind, pivot.price) for pivot in pivots] == [
        ("HIGH", Decimal("17")),
        ("LOW", Decimal("9")),
        ("HIGH", Decimal("19")),
    ]
    assert market_structure(pivots) == ["HH"]


def test_setup_evidence_and_score_are_bounded() -> None:
    series = candles([99, 101, 103])
    evidence = breakout_retest(series, Decimal("100"), "BUY", Decimal("2"))
    assert evidence == {"breakout": True, "retest": True}
    assert setup_family(evidence) == "BREAKOUT_RETEST"
    assert quality_score(
        alignment=True,
        trend=True,
        momentum=True,
        structure=True,
        breakout=True,
        retest=True,
        data_quality=True,
    ) == 100
