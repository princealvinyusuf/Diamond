import type { DashboardFixture } from './domain';

// Compile-time fixture contract test, exercised by `npm run typecheck`.
const analyticsFixture = {
  quote: { displayPrice: 'mock', change: '0', session: 'mock', sourceLabel: 'MOCK' },
  chart: { label: 'mock', priceHigh: '1', priceMid: '0', priceLow: '-1', candles: [] },
  account: {
    name: 'paper', equity: '$0', riskPerTrade: '0%', dailyLossLimit: '0%',
    tradesToday: '0', cooldown: 'off', safeVolume: 'none',
  },
  technical: { status: 'mock', items: [] },
  fundamental: { status: 'mock', items: [] },
  eventRisk: { status: 'mock', nextEvent: 'none', guidance: 'mock' },
  explanation: { summary: 'mock', steps: [] },
  journal: { title: 'paper', emptyState: 'empty' },
  analytics: {
    title: 'Historical replay analytics',
    status: 'COMPLETED · FIXTURE',
    statusDetail: 'Synthetic data',
    sampleSize: '1 mock trade',
    expectancy: '0 R',
    drawdown: '0%',
    metrics: [{ label: 'Net return', value: '0%' }],
    equityCurve: [0, 1],
    drawdownCurve: [0, -1],
  },
} satisfies DashboardFixture;

void analyticsFixture;
