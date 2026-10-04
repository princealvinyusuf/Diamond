from datetime import datetime
from typing import Protocol, Sequence

from .domain import Candle, Quote, SymbolSpec, Timeframe


class MarketDataProvider(Protocol):
    """Replaceable read-only market-data boundary."""

    def get_quote(self, symbol: str) -> Quote: ...

    def get_candles(
        self,
        symbol: str,
        timeframe: Timeframe,
        start: datetime,
        end: datetime,
    ) -> Sequence[Candle]: ...

    def get_symbol_spec(self, symbol: str) -> SymbolSpec: ...

    def health(self) -> dict[str, str | int | bool | None]: ...


class FundamentalDataProvider(Protocol):
    """Read-only macro boundary; numeric data must include provenance."""

    def observations(self, as_of: datetime) -> Sequence[dict[str, object]]: ...


class EconomicCalendarProvider(Protocol):
    """Read-only scheduled-event boundary."""

    def events(self, start: datetime, end: datetime) -> Sequence[dict[str, object]]: ...


# There is deliberately no broker execution provider in the foundation.
