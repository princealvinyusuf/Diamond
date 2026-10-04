from datetime import datetime, timedelta, timezone

import pytest

from diamond_quant.analysis_cli import execute


def request() -> dict[str, object]:
    start = datetime(2025, 1, 1, tzinfo=timezone.utc)

    def rows(hours: int) -> list[dict[str, object]]:
        return [
            {
                "openTime": (start + timedelta(hours=hours * index)).isoformat(),
                "open": 2000 + index,
                "high": 2002 + index,
                "low": 1998 + index,
                "close": 2001 + index,
                "volume": None,
                "isClosed": True,
            }
            for index in range(210)
        ]

    return {
        "contractVersion": "1.0.0",
        "symbol": "XAUUSD",
        "asOf": "2026-01-01T00:00:00+00:00",
        "series": {"D1": rows(24), "H4": rows(4), "H1": rows(1)},
    }


def test_execute_is_deterministic_and_emits_evidence() -> None:
    payload = request()
    result = execute(payload)
    assert result == execute(payload)
    assert result["alignment"] == {
        "biases": {"D1": "BULLISH", "H4": "BULLISH", "H1": "BULLISH"},
        "aligned": True,
        "direction": "BULLISH",
    }
    assert result["setup"]["direction"] == "BUY"
    assert result["paperPlan"]["paperOnly"] is True
    assert result["paperPlan"]["actionable"] is False
    assert result["paperPlan"]["targets"][0] > result["paperPlan"]["entry"]
    assert result["paperPlan"]["grossRiskReward"] >= 2
    assert 0 <= result["setup"]["qualityScore"] <= 100
    assert result["features"]["H4"]["ema200"] is not None


def test_execute_rejects_open_or_out_of_order_candles() -> None:
    payload = request()
    payload["series"]["H4"][-1]["isClosed"] = False
    with pytest.raises(ValueError, match="closed candles"):
        execute(payload)
