from dataclasses import dataclass
from datetime import datetime, timezone
from decimal import Decimal
from enum import StrEnum


class Timeframe(StrEnum):
    H1 = "H1"
    H4 = "H4"
    D1 = "D1"


class FinalAction(StrEnum):
    BUY = "BUY"
    SELL = "SELL"
    WAIT_FOR_CONFIRMATION = "WAIT_FOR_CONFIRMATION"
    NO_TRADE = "NO_TRADE"


@dataclass(frozen=True, slots=True)
class Quote:
    provider: str
    symbol: str
    bid: Decimal
    ask: Decimal
    timestamp: datetime
    is_mock: bool = False

    def __post_init__(self) -> None:
        if self.symbol != "XAUUSD":
            raise ValueError("Foundation supports XAUUSD only")
        if self.bid <= 0 or self.ask <= 0 or self.ask < self.bid:
            raise ValueError("Quote must have positive bid <= ask")
        if self.timestamp.tzinfo is None or self.timestamp.utcoffset() != timezone.utc.utcoffset(None):
            raise ValueError("Quote timestamp must be UTC-aware")


@dataclass(frozen=True, slots=True)
class Candle:
    provider: str
    symbol: str
    timeframe: Timeframe
    open_time: datetime
    open: Decimal
    high: Decimal
    low: Decimal
    close: Decimal
    volume: Decimal | None
    is_closed: bool

    def __post_init__(self) -> None:
        values = (self.open, self.high, self.low, self.close)
        if self.symbol != "XAUUSD" or any(value <= 0 for value in values):
            raise ValueError("Candle symbol and prices are invalid")
        if self.low > min(self.open, self.close) or self.high < max(self.open, self.close):
            raise ValueError("Malformed OHLC candle")
        if self.high < self.low:
            raise ValueError("Candle high cannot be below low")


@dataclass(frozen=True, slots=True)
class SymbolSpec:
    provider: str
    symbol: str
    currency: str
    contract_size: Decimal
    tick_size: Decimal
    tick_value: Decimal
    volume_min: Decimal
    volume_max: Decimal
    volume_step: Decimal
    effective_at: datetime

    def __post_init__(self) -> None:
        numeric = (
            self.contract_size, self.tick_size, self.tick_value,
            self.volume_min, self.volume_max, self.volume_step,
        )
        if self.symbol != "XAUUSD" or any(value <= 0 for value in numeric):
            raise ValueError("Invalid symbol specification")
        if self.volume_min > self.volume_max:
            raise ValueError("Minimum volume cannot exceed maximum volume")


@dataclass(frozen=True, slots=True)
class Decision:
    final_action: FinalAction
    block_reasons: tuple[str, ...]
    execution_mode: str = "PAPER"

    def __post_init__(self) -> None:
        if self.execution_mode != "PAPER":
            raise ValueError("Live execution is intentionally unsupported")
        if self.final_action is FinalAction.NO_TRADE and not self.block_reasons:
            raise ValueError("NO_TRADE must explain at least one blocking reason")
