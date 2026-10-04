"""Diamond deterministic analysis and historical replay (no live execution)."""

from .backtest import CostModel, PartitionPlan, ReplayEngine, Signal
from .domain import Candle, Decision, Quote, SymbolSpec
from .technical import analyze, quality_score

__all__ = [
    "Candle", "CostModel", "Decision", "PartitionPlan", "Quote", "ReplayEngine",
    "Signal", "SymbolSpec", "analyze", "quality_score",
]
__version__ = "0.3.0"
