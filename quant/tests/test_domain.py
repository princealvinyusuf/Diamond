from datetime import datetime, timezone
from decimal import Decimal

import pytest

from diamond_quant.domain import Candle, Decision, FinalAction, Quote, Timeframe


def test_quote_rejects_crossed_market() -> None:
    with pytest.raises(ValueError, match="bid <= ask"):
        Quote(
            provider="mock",
            symbol="XAUUSD",
            bid=Decimal("2401"),
            ask=Decimal("2400"),
            timestamp=datetime.now(timezone.utc),
            is_mock=True,
        )


def test_candle_rejects_invalid_ohlc() -> None:
    with pytest.raises(ValueError, match="Malformed OHLC"):
        Candle(
            provider="mock",
            symbol="XAUUSD",
            timeframe=Timeframe.H4,
            open_time=datetime.now(timezone.utc),
            open=Decimal("2400"),
            high=Decimal("2399"),
            low=Decimal("2398"),
            close=Decimal("2401"),
            volume=None,
            is_closed=True,
        )


def test_no_trade_requires_reason() -> None:
    with pytest.raises(ValueError, match="blocking reason"):
        Decision(final_action=FinalAction.NO_TRADE, block_reasons=())


def test_live_mode_is_impossible() -> None:
    with pytest.raises(ValueError, match="unsupported"):
        Decision(
            final_action=FinalAction.NO_TRADE,
            block_reasons=("No provider",),
            execution_mode="LIVE",
        )
