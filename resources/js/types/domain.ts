export type MarketBias =
  | 'STRONG_BULLISH'
  | 'BULLISH'
  | 'NEUTRAL'
  | 'BEARISH'
  | 'STRONG_BEARISH';

export type TradeSetup =
  | 'WATCHING'
  | 'ARMED'
  | 'VALID'
  | 'BLOCKED'
  | 'EXPIRED'
  | 'OPEN'
  | 'CLOSED';

export interface Decision {
  contractVersion: '1.0.0';
  symbol: 'XAUUSD';
  timeframe: 'H1' | 'H4' | 'D1';
  asOf: string;
  marketBias: MarketBias;
  tradeSetup: TradeSetup;
  riskPermission: 'ALLOWED' | 'BLOCKED';
  finalAction: 'BUY' | 'SELL' | 'WAIT_FOR_CONFIRMATION' | 'NO_TRADE';
  newsRisk: 'LOW' | 'ELEVATED' | 'HIGH' | 'POST_EVENT_STABILIZATION';
  dataQuality: 'FRESH' | 'STALE' | 'DEGRADED' | 'UNAVAILABLE';
  technicalScore: number | null;
  fundamentalScore: number | null;
  entry: number | null;
  stopLoss: number | null;
  targets: number[];
  safeVolume: number | null;
  blockReasons: string[];
  pipelineTrace?: Array<{
    stage: 'data' | 'risk' | 'event' | 'regime' | 'bias' | 'setup' | 'economics' | 'sizing';
    passed: boolean;
    reasons: string[];
  }>;
  strategyVersion: string;
  configurationHash: string | null;
  isMock: boolean;
  executionMode: 'PAPER';
  technicalAnalysis?: TechnicalAnalysis | UnavailableAnalysis;
}

export interface NormalizedCandle {
  openTime: string;
  open: number;
  high: number;
  low: number;
  close: number;
  volume: number | null;
  isClosed: boolean;
}

export interface TechnicalAnalysis {
  contractVersion: '1.0.0';
  symbol: 'XAUUSD';
  asOf: string;
  bias: 'BULLISH' | 'BEARISH' | 'NEUTRAL';
  regime: 'TREND' | 'RANGE';
  features: Record<'D1' | 'H4' | 'H1', Record<string, number | null>>;
  structure: { labels: string[]; zones: Array<{ kind: string; low: string; high: string }> };
  alignment: {
    biases: Record<'D1' | 'H4' | 'H1', 'BULLISH' | 'BEARISH' | 'NEUTRAL'>;
    aligned: boolean;
    direction: 'BULLISH' | 'BEARISH' | 'NEUTRAL';
  };
  setup: {
    direction: 'BUY' | 'SELL' | null;
    family: string;
    confirmed: boolean;
    qualityScore: number;
    evidence: Record<string, boolean>;
  };
}

export interface UnavailableAnalysis {
  status: 'UNAVAILABLE';
  error: { code: string; message: string };
}

export interface DashboardDataState {
  mode: 'FIXTURE' | 'PROVIDER' | 'UNAVAILABLE';
  label: string;
  message: string;
  candles: NormalizedCandle[];
}

export interface DashboardFixture {
  quote: {
    displayPrice: string;
    change: string;
    session: string;
    sourceLabel: string;
  };
  chart: {
    label: string;
    priceHigh: string;
    priceMid: string;
    priceLow: string;
    candles: Array<{
      open: number;
      close: number;
      high: number;
      low: number;
    }>;
  };
  account: {
    name: string;
    equity: string;
    riskPerTrade: string;
    dailyLossLimit: string;
    tradesToday: string;
    cooldown: string;
    safeVolume: string;
  };
  technical: DashboardPanel;
  fundamental: DashboardPanel;
  eventRisk: {
    status: string;
    nextEvent: string;
    guidance: string;
  };
  explanation: {
    summary: string;
    steps: string[];
  };
  journal: {
    title: string;
    emptyState: string;
  };
  analytics: {
    title: string;
    status: string;
    statusDetail: string;
    sampleSize: string;
    expectancy: string;
    drawdown: string;
    metrics: Array<{ label: string; value: string }>;
    equityCurve: number[];
    drawdownCurve: number[];
  };
}

export interface DashboardPanel {
  status: string;
  items: Array<{ label: string; value: string }>;
}
