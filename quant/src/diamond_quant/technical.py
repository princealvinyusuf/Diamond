"""Deterministic, dependency-free technical analysis for closed candles."""

from dataclasses import dataclass
from decimal import Decimal
from typing import Iterable, Sequence

from .domain import Candle, Timeframe

D = Decimal


def ema(values: Sequence[Decimal], period: int) -> list[Decimal | None]:
    if period <= 0:
        raise ValueError("period must be positive")
    out: list[Decimal | None] = [None] * len(values)
    if len(values) < period:
        return out
    seed = sum(values[:period], D(0)) / D(period)
    out[period - 1] = seed
    alpha = D(2) / D(period + 1)
    current = seed
    for index in range(period, len(values)):
        current = values[index] * alpha + current * (D(1) - alpha)
        out[index] = current
    return out


def rsi(values: Sequence[Decimal], period: int = 14) -> list[Decimal | None]:
    out: list[Decimal | None] = [None] * len(values)
    if len(values) <= period:
        return out
    gains = [max(D(0), values[i] - values[i - 1]) for i in range(1, len(values))]
    losses = [max(D(0), values[i - 1] - values[i]) for i in range(1, len(values))]
    avg_gain = sum(gains[:period], D(0)) / D(period)
    avg_loss = sum(losses[:period], D(0)) / D(period)
    out[period] = _rsi_value(avg_gain, avg_loss)
    for i in range(period + 1, len(values)):
        avg_gain = (avg_gain * D(period - 1) + gains[i - 1]) / D(period)
        avg_loss = (avg_loss * D(period - 1) + losses[i - 1]) / D(period)
        out[i] = _rsi_value(avg_gain, avg_loss)
    return out


def _rsi_value(gain: Decimal, loss: Decimal) -> Decimal:
    if loss == 0:
        return D(100) if gain > 0 else D(50)
    return D(100) - D(100) / (D(1) + gain / loss)


def true_ranges(candles: Sequence[Candle]) -> list[Decimal]:
    result: list[Decimal] = []
    for i, candle in enumerate(candles):
        previous = candles[i - 1].close if i else candle.close
        result.append(
            max(candle.high - candle.low, abs(candle.high - previous), abs(candle.low - previous))
        )
    return result


def wilders(values: Sequence[Decimal], period: int) -> list[Decimal | None]:
    out: list[Decimal | None] = [None] * len(values)
    if len(values) < period:
        return out
    current = sum(values[:period], D(0)) / D(period)
    out[period - 1] = current
    for i in range(period, len(values)):
        current = (current * D(period - 1) + values[i]) / D(period)
        out[i] = current
    return out


def atr(candles: Sequence[Candle], period: int = 14) -> list[Decimal | None]:
    return wilders(true_ranges(candles), period)


def macd(
    values: Sequence[Decimal],
) -> tuple[list[Decimal | None], list[Decimal | None], list[Decimal | None]]:
    fast, slow = ema(values, 12), ema(values, 26)
    line: list[Decimal | None] = []
    for fast_value, slow_value in zip(fast, slow, strict=True):
        line.append(
            fast_value - slow_value
            if fast_value is not None and slow_value is not None
            else None
        )
    available = [value for value in line if value is not None]
    compact_signal = ema(available, 9)
    signal: list[Decimal | None] = [None] * len(values)
    offset = next((i for i, value in enumerate(line) if value is not None), len(values))
    for i, value in enumerate(compact_signal):
        signal[offset + i] = value
    histogram: list[Decimal | None] = []
    for line_value, signal_value in zip(line, signal, strict=True):
        histogram.append(
            line_value - signal_value
            if line_value is not None and signal_value is not None
            else None
        )
    return line, signal, histogram


def adx(candles: Sequence[Candle], period: int = 14) -> list[Decimal | None]:
    if not candles:
        return []
    trs = true_ranges(candles)
    plus = [D(0)]
    minus = [D(0)]
    for i in range(1, len(candles)):
        up = candles[i].high - candles[i - 1].high
        down = candles[i - 1].low - candles[i].low
        plus.append(up if up > down and up > 0 else D(0))
        minus.append(down if down > up and down > 0 else D(0))
    sm_tr, sm_plus, sm_minus = wilders(trs, period), wilders(plus, period), wilders(minus, period)
    dx: list[Decimal] = []
    start = period - 1
    for i in range(start, len(candles)):
        tr = sm_tr[i] or D(0)
        pdi = D(100) * (sm_plus[i] or D(0)) / tr if tr else D(0)
        mdi = D(100) * (sm_minus[i] or D(0)) / tr if tr else D(0)
        dx.append(D(100) * abs(pdi - mdi) / (pdi + mdi) if pdi + mdi else D(0))
    compact = wilders(dx, period)
    out: list[Decimal | None] = [None] * len(candles)
    for i, value in enumerate(compact):
        if start + i < len(out):
            out[start + i] = value
    return out


@dataclass(frozen=True, slots=True)
class Pivot:
    index: int
    kind: str
    price: Decimal


def confirmed_pivots(candles: Sequence[Candle], left: int = 2, right: int = 2) -> list[Pivot]:
    """Only closed candles with `right` later closed candles can confirm a pivot."""
    closed = [c for c in candles if c.is_closed]
    pivots: list[Pivot] = []
    for i in range(left, len(closed) - right):
        window = closed[i - left:i + right + 1]
        unique_high = sum(c.high == closed[i].high for c in window) == 1
        unique_low = sum(c.low == closed[i].low for c in window) == 1
        if closed[i].high == max(c.high for c in window) and unique_high:
            pivots.append(Pivot(i, "HIGH", closed[i].high))
        if closed[i].low == min(c.low for c in window) and unique_low:
            pivots.append(Pivot(i, "LOW", closed[i].low))
    return sorted(pivots, key=lambda pivot: pivot.index)


def market_structure(pivots: Sequence[Pivot]) -> list[str]:
    previous: dict[str, Decimal] = {}
    labels: list[str] = []
    for pivot in pivots:
        old = previous.get(pivot.kind)
        if old is not None:
            high_label = "HH" if pivot.price > old else "LH"
            low_label = "HL" if pivot.price > old else "LL"
            labels.append(high_label if pivot.kind == "HIGH" else low_label)
        previous[pivot.kind] = pivot.price
    return labels


def support_resistance_zones(
    pivots: Sequence[Pivot],
    current_atr: Decimal,
    width_atr: Decimal = D("0.25"),
) -> list[dict[str, str]]:
    width = max(D(0), current_atr * width_atr)
    return [
        {
            "kind": "resistance" if p.kind == "HIGH" else "support",
            "low": str(p.price - width),
            "high": str(p.price + width),
        }
        for p in pivots[-6:]
    ]


def breakout_retest(
    candles: Sequence[Candle],
    level: Decimal,
    direction: str,
    tolerance: Decimal,
) -> dict[str, bool]:
    if len(candles) < 2:
        return {"breakout": False, "retest": False}
    if direction == "BUY":
        breakout = any(c.close > level for c in candles[:-1])
        retest = breakout and candles[-1].low <= level + tolerance and candles[-1].close > level
    else:
        breakout = any(c.close < level for c in candles[:-1])
        retest = breakout and candles[-1].high >= level - tolerance and candles[-1].close < level
    return {"breakout": breakout, "retest": retest}


def timeframe_bias(candles: Sequence[Candle]) -> str:
    closes = [c.close for c in candles if c.is_closed]
    if len(closes) < 200:
        return "NEUTRAL"
    e20, e50, e200 = ema(closes, 20)[-1], ema(closes, 50)[-1], ema(closes, 200)[-1]
    if e20 is not None and e50 is not None and e200 is not None:
        if closes[-1] > e20 > e50 > e200:
            return "BULLISH"
        if closes[-1] < e20 < e50 < e200:
            return "BEARISH"
    return "NEUTRAL"


def multi_timeframe_alignment(series: dict[Timeframe, Sequence[Candle]]) -> dict[str, object]:
    biases = {
        timeframe.value: timeframe_bias(series.get(timeframe, ()))
        for timeframe in (Timeframe.D1, Timeframe.H4, Timeframe.H1)
    }
    non_neutral = [value for value in biases.values() if value != "NEUTRAL"]
    aligned = len(non_neutral) == 3 and len(set(non_neutral)) == 1
    return {
        "biases": biases,
        "aligned": aligned,
        "direction": non_neutral[0] if aligned else "NEUTRAL",
    }


def setup_family(evidence: dict[str, bool]) -> str:
    if evidence.get("breakout") and evidence.get("retest"):
        return "BREAKOUT_RETEST"
    if evidence.get("trend") and evidence.get("pullback"):
        return "TREND_PULLBACK"
    if evidence.get("reversal") and evidence.get("confirmation"):
        return "STRUCTURE_REVERSAL"
    return "NONE"


def quality_score(
    *,
    alignment: bool,
    trend: bool,
    momentum: bool,
    structure: bool,
    breakout: bool,
    retest: bool,
    data_quality: bool,
) -> int:
    weights = (20, 15, 15, 15, 10, 15, 10)
    flags: Iterable[bool] = (alignment, trend, momentum, structure, breakout, retest, data_quality)
    return sum(weight for weight, flag in zip(weights, flags, strict=True) if flag)


def analyze(candles: Sequence[Candle]) -> dict[str, object]:
    closed = [c for c in candles if c.is_closed]
    closes = [c.close for c in closed]
    pivots = confirmed_pivots(closed)
    atr_values = atr(closed)
    macd_line, signal, histogram = macd(closes)
    return {
        "ema20": ema(closes, 20)[-1] if closes else None,
        "ema50": ema(closes, 50)[-1] if closes else None,
        "ema200": ema(closes, 200)[-1] if closes else None,
        "rsi14": rsi(closes)[-1] if closes else None,
        "macd": macd_line[-1] if closes else None,
        "macdSignal": signal[-1] if closes else None,
        "macdHistogram": histogram[-1] if closes else None,
        "atr14": atr_values[-1] if closed else None,
        "adx14": adx(closed)[-1] if closed else None,
        "pivots": pivots,
        "structure": market_structure(pivots),
    }
