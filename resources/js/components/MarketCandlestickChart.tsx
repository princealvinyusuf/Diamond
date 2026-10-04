import { CandlestickSeries, ColorType, createChart, type Time } from 'lightweight-charts';
import { useEffect, useRef } from 'react';
import type { NormalizedCandle } from '../types/domain';

export function MarketCandlestickChart({ candles }: { candles: NormalizedCandle[] }) {
  const container = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!container.current || candles.length === 0) return;
    const chart = createChart(container.current, {
      autoSize: true,
      layout: { background: { type: ColorType.Solid, color: '#0b0e13' }, textColor: '#737b87' },
      grid: { vertLines: { color: '#20242c' }, horzLines: { color: '#20242c' } },
      timeScale: { borderColor: '#252a33', timeVisible: true },
      rightPriceScale: { borderColor: '#252a33' },
    });
    const series = chart.addSeries(CandlestickSeries, {
      upColor: '#d8b951',
      downColor: '#7a3432',
      borderUpColor: '#d8b951',
      borderDownColor: '#d45c53',
      wickUpColor: '#d8b951',
      wickDownColor: '#d45c53',
    });
    series.setData(candles.map((candle) => ({
      time: Math.floor(new Date(candle.openTime).getTime() / 1000) as Time,
      open: candle.open,
      high: candle.high,
      low: candle.low,
      close: candle.close,
    })));
    chart.timeScale().fitContent();
    return () => chart.remove();
  }, [candles]);

  return <div className="provider-chart" ref={container} aria-label="Read-only provider H4 candlestick chart" />;
}
