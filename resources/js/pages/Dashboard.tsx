import { Head, router, useForm } from '@inertiajs/react';
import { useState, type CSSProperties, type FormEvent } from 'react';
import { MarketCandlestickChart } from '../components/MarketCandlestickChart';
import type { DashboardDataState, DashboardFixture, DashboardPanel, DashboardSettings, Decision } from '../types/domain';

type Props = {
  decision: Decision;
  dashboard: DashboardFixture;
  dataState: DashboardDataState;
  settings: DashboardSettings;
};

const stateCards: Array<{
  label: string;
  key: 'marketBias' | 'tradeSetup' | 'riskPermission' | 'finalAction';
  icon: string;
}> = [
  { label: 'Market bias', key: 'marketBias', icon: '◎' },
  { label: 'Trade setup', key: 'tradeSetup', icon: '◇' },
  { label: 'Risk permission', key: 'riskPermission', icon: '◈' },
  { label: 'Final action', key: 'finalAction', icon: '→' },
];

const pretty = (value: string) => value.replaceAll('_', ' ');

const chartPoints = (values: number[]) => {
  const min = Math.min(...values);
  const span = Math.max(1, Math.max(...values) - min);
  return values.map((value, index) => {
    const x = values.length === 1 ? 0 : (index / (values.length - 1)) * 100;
    const y = 36 - ((value - min) / span) * 32;
    return `${x},${y}`;
  }).join(' ');
};

function AnalysisPanel({
  title,
  eyebrow,
  panel,
}: {
  title: string;
  eyebrow: string;
  panel: DashboardPanel;
}) {
  return (
    <article className="panel analysis-panel">
      <div className="panel-heading">
        <div>
          <p className="panel-eyebrow">{eyebrow}</p>
          <h2>{title}</h2>
        </div>
        <span className="status-chip muted">{panel.status}</span>
      </div>
      <dl className="metric-list">
        {panel.items.map((item) => (
          <div key={item.label}>
            <dt>{item.label}</dt>
            <dd>{item.value}</dd>
          </div>
        ))}
      </dl>
    </article>
  );
}

function SettingsModal({ settings, onClose }: { settings: DashboardSettings; onClose: () => void }) {
  const { data, setData, put, processing, recentlySuccessful, errors } = useForm<DashboardSettings>(settings);
  const submit = (event: FormEvent) => {
    event.preventDefault();
    put('/api/v1/settings', { preserveScroll: true });
  };
  const number = (value: string) => Number(value);
  return (
    <div className="modal-backdrop" role="presentation">
      <section className="settings-modal" role="dialog" aria-modal="true" aria-labelledby="settings-title">
        <div className="panel-heading">
          <div><p className="panel-eyebrow">LOCAL · SINGLE ACCOUNT</p><h2 id="settings-title">Paper dashboard settings</h2></div>
          <button className="icon-button" type="button" onClick={onClose} aria-label="Close settings">×</button>
        </div>
        <form onSubmit={submit}>
          <fieldset>
            <legend>Account and risk</legend>
            <label>Name<input value={data.account.name} onChange={(e) => setData('account', { ...data.account, name: e.target.value })} /></label>
            <label>Equity<input type="number" min="100" max="10000000" step="0.01" value={data.account.equity} onChange={(e) => setData('account', { ...data.account, equity: number(e.target.value) })} /></label>
            <label>Risk / trade %<input type="number" min="0.1" max="2" step="0.1" value={data.risk.riskPerTradePercent} onChange={(e) => setData('risk', { ...data.risk, riskPerTradePercent: number(e.target.value) })} /></label>
            <label>Daily cap %<input type="number" min="0.5" max="5" step="0.1" value={data.risk.dailyLossCapPercent} onChange={(e) => setData('risk', { ...data.risk, dailyLossCapPercent: number(e.target.value) })} /></label>
            <label>Max trades<input type="number" min="1" max="10" value={data.risk.maxTradesPerDay} onChange={(e) => setData('risk', { ...data.risk, maxTradesPerDay: number(e.target.value) })} /></label>
            <label>Loss cooldown (min)<input type="number" min="15" max="10080" value={data.risk.lossCooldownMinutes} onChange={(e) => setData('risk', { ...data.risk, lossCooldownMinutes: number(e.target.value) })} /></label>
            <label>Minimum R:R<input type="number" min="2" max="5" step="0.1" value={data.risk.minimumRiskReward} onChange={(e) => setData('risk', { ...data.risk, minimumRiskReward: number(e.target.value) })} /></label>
            <label>Display target ($)<input type="number" min="0" max="1000000" step="0.01" value={data.risk.dailyProfitTarget ?? ''} onChange={(e) => setData('risk', { ...data.risk, dailyProfitTarget: e.target.value === '' ? null : number(e.target.value) })} /></label>
          </fieldset>
          <fieldset>
            <legend>Manual XAUUSD broker specification</legend>
            <label>Tick size<input type="number" min="0.00000001" step="any" value={data.symbol.tickSize} onChange={(e) => setData('symbol', { ...data.symbol, tickSize: number(e.target.value) })} /></label>
            <label>Tick value<input type="number" min="0.00000001" step="any" value={data.symbol.tickValue} onChange={(e) => setData('symbol', { ...data.symbol, tickValue: number(e.target.value) })} /></label>
            <label>Contract size<input type="number" min="0.00000001" step="any" value={data.symbol.contractSize} onChange={(e) => setData('symbol', { ...data.symbol, contractSize: number(e.target.value) })} /></label>
            <label>Minimum volume<input type="number" min="0.00000001" step="any" value={data.symbol.minimumVolume} onChange={(e) => setData('symbol', { ...data.symbol, minimumVolume: number(e.target.value) })} /></label>
            <label>Maximum volume<input type="number" min="0.00000001" step="any" value={data.symbol.maximumVolume} onChange={(e) => setData('symbol', { ...data.symbol, maximumVolume: number(e.target.value) })} /></label>
            <label>Volume step<input type="number" min="0.00000001" step="any" value={data.symbol.volumeStep} onChange={(e) => setData('symbol', { ...data.symbol, volumeStep: number(e.target.value) })} /></label>
          </fieldset>
          <p className="form-note">Paper only. Martingale is prohibited. The profit target is display-only and never changes sizing.</p>
          {Object.keys(errors).length > 0 && <p className="form-error">{Object.values(errors)[0]}</p>}
          <div className="form-actions">
            <span>{recentlySuccessful ? 'Saved — dashboard reloaded.' : ''}</span>
            <button className="primary-button" disabled={processing} type="submit">{processing ? 'Saving…' : 'Save settings'}</button>
          </div>
        </form>
      </section>
    </div>
  );
}

export default function Dashboard({ decision, dashboard, dataState, settings }: Props) {
  const [settingsOpen, setSettingsOpen] = useState(false);
  const fixtureMode = dataState.mode === 'FIXTURE';
  const providerMode = dataState.mode === 'PROVIDER' && dataState.candles.length > 0;
  return (
    <>
      <Head title="XAUUSD H4 dashboard" />
      <div className="app-frame">
        <header className="topbar">
          <a className="brand" href="#overview" aria-label="Diamond dashboard home">
            <span className="brand-mark">D</span>
            <span>DIAMOND</span>
          </a>
          <nav aria-label="Primary navigation">
            <a className="active" href="#overview">Overview</a>
            <a href="#journal">Journal</a>
            <a href="#analytics">Analytics</a>
          </nav>
          <div className="topbar-actions">
            <button className="secondary-button" type="button" onClick={() => setSettingsOpen(true)}>Settings</button>
            <span className="fixture-dot"><i /> {dataState.label}</span>
            <span className="paper">PAPER ONLY</span>
          </div>
        </header>

        <main className="shell" id="overview">
          <section className="notice" role="status">
            <strong>{dataState.mode}</strong>
            <span>{dataState.message}</span>
            <button className="refresh-button" type="button" onClick={() => router.reload()}>Refresh Analysis</button>
          </section>

          <section className="market-header" aria-labelledby="page-title">
            <div className="instrument">
              <span className="gold-orb">Au</span>
              <div>
                <p className="eyebrow">METALS · 4 HOUR DECISION SUPPORT</p>
                <h1 id="page-title">XAU / USD</h1>
                <p>Gold / U.S. Dollar <span>·</span> {dashboard.quote.session}</p>
              </div>
            </div>
            <div className="quote">
              <span>{dashboard.quote.sourceLabel}</span>
              <strong>{dashboard.quote.displayPrice}</strong>
              <small>{dashboard.quote.change}</small>
            </div>
          </section>

          <section className="states" aria-label="Decision states">
            {stateCards.map(({ label, key, icon }) => {
              const value = String(decision[key]);
              const blocked = value === 'BLOCKED' || value === 'NO_TRADE';
              return (
                <article className={blocked ? 'state-card blocked' : 'state-card'} key={key}>
                  <div className="state-title"><span>{icon}</span>{label}</div>
                  <strong>{pretty(value)}</strong>
                  <small>{blocked ? 'Safety gate engaged' : (fixtureMode ? 'Fixture state only' : 'Read-only analysis')}</small>
                </article>
              );
            })}
          </section>

          <section className="primary-grid">
            <article className="panel chart-panel">
              <div className="panel-heading">
                <div>
                  <p className="panel-eyebrow">PRICE CONTEXT</p>
                  <h2>{dashboard.chart.label}</h2>
                </div>
                <span className="status-chip">H4 · {dataState.mode}</span>
              </div>
              {providerMode ? (
                <MarketCandlestickChart candles={dataState.candles} />
              ) : fixtureMode ? (
              <>
              <div className="chart-wrap" aria-label="Static illustrative candlestick display">
                <div className="price-scale" aria-hidden="true">
                  <span>{dashboard.chart.priceHigh}</span>
                  <span>{dashboard.chart.priceMid}</span>
                  <span>{dashboard.chart.priceLow}</span>
                </div>
                <div className="chart-grid">
                  <div className="chart-line line-one" />
                  <div className="chart-line line-two" />
                  <div className="chart-line line-three" />
                  <div className="candles">
                    {dashboard.chart.candles.map((candle, index) => {
                      const rising = candle.close >= candle.open;
                      const style = {
                        '--open': `${100 - candle.open}%`,
                        '--close': `${100 - candle.close}%`,
                        '--high': `${100 - candle.high}%`,
                        '--low': `${100 - candle.low}%`,
                      } as CSSProperties;
                      return (
                        <span
                          className={`candle ${rising ? 'up' : 'down'}`}
                          style={style}
                          key={`${candle.open}-${candle.close}-${index}`}
                        >
                          <i />
                        </span>
                      );
                    })}
                  </div>
                  <span className="mock-watermark">ILLUSTRATIVE · NOT MARKET DATA</span>
                </div>
              </div>
              <div className="chart-footer">
                <span>Sep 29</span><span>Sep 30</span><span>Oct 01</span><span>Oct 02</span><span>Oct 03</span>
              </div>
              </>
              ) : (
                <div className="chart-unavailable" role="status">NO VERIFIED CANDLE DATA AVAILABLE</div>
              )}
            </article>

            <aside className="panel account-panel">
              <div className="panel-heading">
                <div>
                  <p className="panel-eyebrow">RISK ENVELOPE</p>
                  <h2>{dashboard.account.name}</h2>
                </div>
                <span className="shield">◇</span>
              </div>
              <div className="equity">
                <span>Paper equity</span>
                <strong>{dashboard.account.equity}</strong>
              </div>
              <dl className="metric-list">
                <div><dt>Risk per trade</dt><dd>{dashboard.account.riskPerTrade}</dd></div>
                <div><dt>Daily loss limit</dt><dd>{dashboard.account.dailyLossLimit}</dd></div>
                <div><dt>Trades today</dt><dd>{dashboard.account.tradesToday}</dd></div>
                <div><dt>Cooldown</dt><dd>{dashboard.account.cooldown}</dd></div>
                <div><dt>Safe volume</dt><dd className="warning-text">{dashboard.account.safeVolume}</dd></div>
              </dl>
              <p className="account-note">Sizing is shown only after a validated paper setup passes every gate.</p>
            </aside>
          </section>

          <section className="panel plan-card" aria-label="Paper plan">
            <div className="panel-heading">
              <div><p className="panel-eyebrow">NON-ACTIONABLE CONTEXT</p><h2>Paper plan</h2></div>
              <span className="status-chip blocked-chip">NO AUTOMATIC ORDER</span>
            </div>
            {decision.paperPlan ? (
              <>
                <dl className="plan-metrics">
                  <div><dt>Direction</dt><dd>{decision.paperPlan.direction}</dd></div>
                  <div><dt>Entry</dt><dd>{decision.paperPlan.entry.toFixed(2)}</dd></div>
                  <div><dt>Stop</dt><dd>{decision.paperPlan.stopLoss.toFixed(2)}</dd></div>
                  <div><dt>Target</dt><dd>{decision.paperPlan.targets[0]?.toFixed(2) ?? '—'}</dd></div>
                  <div><dt>Net R:R</dt><dd>{decision.paperPlan.netRiskReward.toFixed(2)} R</dd></div>
                  <div><dt>Safe volume</dt><dd>{decision.paperPlan.safeVolume?.toFixed(2) ?? 'Unavailable'}</dd></div>
                  <div><dt>Dollar risk</dt><dd>{decision.paperPlan.dollarRisk == null ? 'Unavailable' : `$${decision.paperPlan.dollarRisk.toFixed(2)}`}</dd></div>
                </dl>
                <button className="paper-action" type="button" disabled>
                  {decision.finalAction === 'NO_TRADE' ? 'Paper action blocked by gates' : 'Review in existing paper-order flow'}
                </button>
              </>
            ) : <p className="summary">No directional setup exists, so no entry, stop, target, or sizing was proposed.</p>}
          </section>

          <section className="analysis-grid">
            <AnalysisPanel title="Technical picture" eyebrow="SYSTEMATIC" panel={dashboard.technical} />
            <AnalysisPanel title="Fundamental context" eyebrow="MACRO" panel={dashboard.fundamental} />
            <article className="panel event-panel">
              <div className="panel-heading">
                <div><p className="panel-eyebrow">CALENDAR</p><h2>Event risk</h2></div>
                <span className="status-chip warning-chip">{dashboard.eventRisk.status}</span>
              </div>
              <strong>{dashboard.eventRisk.nextEvent}</strong>
              <p>{dashboard.eventRisk.guidance}</p>
            </article>
          </section>

          <section className="explanation-grid">
            <article className="panel explanation">
              <div className="panel-heading">
                <div><p className="panel-eyebrow">DECISION TRACE</p><h2>Why no trade?</h2></div>
                <span className="status-chip blocked-chip">{pretty(decision.finalAction)}</span>
              </div>
              <p className="summary">{dashboard.explanation.summary}</p>
              <ol>
                {dashboard.explanation.steps.map((step) => <li key={step}>{step}</li>)}
              </ol>
            </article>
            <article className="panel blockers">
              <p className="panel-eyebrow">HARD STOPS</p>
              <h2>Block reasons</h2>
              <ul>
                {decision.blockReasons.map((reason) => <li key={reason}><span>×</span>{reason}</li>)}
              </ul>
              <div className="quality-row">
                <span>Data quality <strong>{decision.dataQuality}</strong></span>
                <span>News risk <strong>{decision.newsRisk}</strong></span>
              </div>
            </article>
          </section>

          <section className="lower-grid">
            <article className="panel empty-panel" id="journal">
              <span className="empty-icon">≡</span>
              <div><p className="panel-eyebrow">JOURNAL</p><h2>{dashboard.journal.title}</h2><p>{dashboard.journal.emptyState}</p></div>
              <a href="#overview">View decision context <span>→</span></a>
            </article>
          </section>

          <section className="panel analytics-suite" id="analytics" aria-label="Mock backtest analytics">
            <div className="panel-heading">
              <div>
                <p className="panel-eyebrow">ANALYTICS · SYNTHETIC FIXTURE</p>
                <h2>{dashboard.analytics.title}</h2>
                <small>{dashboard.analytics.statusDetail}</small>
              </div>
              <span className="status-chip mock-status">{dashboard.analytics.status}</span>
            </div>
            <div className="analytics-metrics">
              {dashboard.analytics.metrics.map((metric) => (
                <div key={metric.label}><span>{metric.label}</span><strong>{metric.value}</strong></div>
              ))}
            </div>
            <div className="analytics-charts">
              <div>
                <span>Mock equity curve</span>
                <svg viewBox="0 0 100 40" role="img" aria-label="Synthetic fixture equity curve">
                  <polyline points={chartPoints(dashboard.analytics.equityCurve)} />
                </svg>
              </div>
              <div className="drawdown-chart">
                <span>Mock drawdown · {dashboard.analytics.drawdown}</span>
                <svg viewBox="0 0 100 40" role="img" aria-label="Synthetic fixture drawdown curve">
                  <polyline points={chartPoints(dashboard.analytics.drawdownCurve)} />
                </svg>
              </div>
            </div>
            <footer className="analytics-foot">
              <span>{dashboard.analytics.sampleSize}</span>
              <span>Expectancy {dashboard.analytics.expectancy}</span>
              <strong>ILLUSTRATIVE — NOT ACTUAL PERFORMANCE</strong>
            </footer>
          </section>

          <footer>
            <span>{dataState.mode} as of {new Date(decision.asOf).toISOString()}</span>
            <span>Contract v{decision.contractVersion} · {decision.strategyVersion}</span>
          </footer>
        </main>
      </div>
      {settingsOpen && <SettingsModal settings={settings} onClose={() => setSettingsOpen(false)} />}
    </>
  );
}
