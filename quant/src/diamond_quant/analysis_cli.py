"""Strict stdin/stdout JSON bridge for deterministic closed-candle analysis."""

from __future__ import annotations

from datetime import datetime, timedelta, timezone
from decimal import Decimal
import json
import sys
from typing import Any, cast

from .domain import Candle, Timeframe
from .technical import (
    Pivot,
    analyze,
    breakout_retest,
    multi_timeframe_alignment,
    quality_score,
    setup_family,
    support_resistance_zones,
)

D = Decimal
TIMEFRAMES = (Timeframe.D1, Timeframe.H4, Timeframe.H1)
MAX_CANDLES = 500
DURATIONS = {
    Timeframe.D1: timedelta(days=1),
    Timeframe.H4: timedelta(hours=4),
    Timeframe.H1: timedelta(hours=1),
}


def _time(value: object) -> datetime:
    if not isinstance(value, str):
        raise ValueError("openTime must be a string")
    parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    if parsed.tzinfo is None or parsed.utcoffset() != timezone.utc.utcoffset(None):
        raise ValueError("timestamps must be UTC-aware")
    return parsed


def _candles(rows: object, timeframe: Timeframe, as_of: datetime) -> list[Candle]:
    if not isinstance(rows, list) or len(rows) > MAX_CANDLES:
        raise ValueError(f"{timeframe.value} must be an array of at most {MAX_CANDLES} candles")
    result: list[Candle] = []
    previous: datetime | None = None
    required = {"openTime", "open", "high", "low", "close", "volume", "isClosed"}
    for row in rows:
        if not isinstance(row, dict) or set(row) != required:
            raise ValueError(f"{timeframe.value} candle shape is invalid")
        opened = _time(row["openTime"])
        if previous is not None and opened <= previous:
            raise ValueError(f"{timeframe.value} candles must be unique and ascending")
        previous = opened
        if row["isClosed"] is not True:
            raise ValueError("analysis accepts closed candles only")
        if opened + DURATIONS[timeframe] > as_of:
            raise ValueError("analysis accepts completed candles only")
        result.append(Candle(
            provider="normalized-input",
            symbol="XAUUSD",
            timeframe=timeframe,
            open_time=opened,
            open=D(str(row["open"])),
            high=D(str(row["high"])),
            low=D(str(row["low"])),
            close=D(str(row["close"])),
            volume=D(str(row["volume"])) if row["volume"] is not None else None,
            is_closed=True,
        ))
    return result


def _number(value: object) -> float | None:
    return float(value) if isinstance(value, Decimal) else None


def execute(request: dict[str, object]) -> dict[str, object]:
    if set(request) != {"contractVersion", "symbol", "asOf", "series"}:
        raise ValueError("request shape is invalid")
    if request["contractVersion"] != "1.0.0" or request["symbol"] != "XAUUSD":
        raise ValueError("unsupported contract version or symbol")
    as_of = _time(request["asOf"])
    raw_series = request["series"]
    if not isinstance(raw_series, dict) or set(raw_series) != {item.value for item in TIMEFRAMES}:
        raise ValueError("series must contain exactly D1, H4, and H1")

    series = {
        timeframe: _candles(raw_series[timeframe.value], timeframe, as_of)
        for timeframe in TIMEFRAMES
    }
    alignment = multi_timeframe_alignment(series)
    analyses = {timeframe: analyze(series[timeframe]) for timeframe in TIMEFRAMES}
    h4 = analyses[Timeframe.H4]
    h4_candles = series[Timeframe.H4]
    direction = str(alignment["direction"])
    atr = h4["atr14"] if isinstance(h4["atr14"], Decimal) else D(0)
    pivots = cast(list[Pivot], h4["pivots"])
    relevant_kind = "HIGH" if direction == "BULLISH" else "LOW"
    relevant = next((pivot for pivot in reversed(pivots) if pivot.kind == relevant_kind), None)
    evidence = {"breakout": False, "retest": False}
    if relevant is not None and direction != "NEUTRAL":
        evidence = breakout_retest(
            h4_candles[-20:],
            relevant.price,
            "BUY" if direction == "BULLISH" else "SELL",
            atr * D("0.20"),
        )

    ema20, ema50 = h4["ema20"], h4["ema50"]
    histogram = h4["macdHistogram"]
    trend = (
        direction == "BULLISH"
        and isinstance(ema20, Decimal)
        and isinstance(ema50, Decimal)
        and ema20 > ema50
    ) or (
        direction == "BEARISH"
        and isinstance(ema20, Decimal)
        and isinstance(ema50, Decimal)
        and ema20 < ema50
    )
    momentum = (
        direction == "BULLISH" and isinstance(histogram, Decimal) and histogram > 0
    ) or (
        direction == "BEARISH" and isinstance(histogram, Decimal) and histogram < 0
    )
    labels = cast(list[str], h4["structure"])
    structure = ("HH" in labels[-2:] or "HL" in labels[-2:]) if direction == "BULLISH" else (
        ("LH" in labels[-2:] or "LL" in labels[-2:]) if direction == "BEARISH" else False
    )
    warmed_up = all(len(series[timeframe]) >= 200 for timeframe in TIMEFRAMES)
    score = quality_score(
        alignment=bool(alignment["aligned"]),
        trend=trend,
        momentum=momentum,
        structure=structure,
        breakout=evidence["breakout"],
        retest=evidence["retest"],
        data_quality=warmed_up,
    )
    family = setup_family({
        **evidence,
        "trend": trend,
        "pullback": evidence["retest"],
        "reversal": False,
        "confirmation": False,
    })
    setup_direction = (
        "BUY" if direction == "BULLISH" else ("SELL" if direction == "BEARISH" else None)
    )
    features: dict[str, Any] = {}
    for timeframe in TIMEFRAMES:
        item = analyses[timeframe]
        features[timeframe.value] = {
            key: _number(item[key])
            for key in (
                "ema20", "ema50", "ema200", "rsi14", "macd",
                "macdSignal", "macdHistogram", "atr14", "adx14",
            )
        }

    adx_value = _number(h4["adx14"])
    return {
        "contractVersion": "1.0.0",
        "symbol": "XAUUSD",
        "asOf": as_of.isoformat(),
        "features": features,
        "structure": {
            "labels": list(labels[-6:]),
            "zones": support_resistance_zones(pivots, atr) if atr > 0 else [],
        },
        "alignment": alignment,
        "regime": "TREND" if trend and adx_value is not None and adx_value >= 20 else "RANGE",
        "bias": direction,
        "setup": {
            "direction": setup_direction,
            "family": family,
            "confirmed": bool(evidence["retest"] and momentum and structure and alignment["aligned"]),
            "evidence": {
                "alignment": bool(alignment["aligned"]),
                "trend": trend,
                "momentum": momentum,
                "structure": structure,
                **evidence,
                "warmedUp": warmed_up,
            },
            "qualityScore": score,
        },
    }


def main() -> None:
    try:
        payload = json.load(sys.stdin)
        if not isinstance(payload, dict):
            raise ValueError("request must be a JSON object")
        print(json.dumps(execute(payload), sort_keys=True, separators=(",", ":")))
    except (KeyError, TypeError, ValueError, json.JSONDecodeError) as error:
        print(json.dumps({
            "error": {"code": "INVALID_ANALYSIS_PAYLOAD", "message": str(error)}
        }, sort_keys=True, separators=(",", ":")), file=sys.stderr)
        raise SystemExit(2) from error


if __name__ == "__main__":
    main()
