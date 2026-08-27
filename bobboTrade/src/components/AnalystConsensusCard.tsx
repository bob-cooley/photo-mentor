import type { AnalystData } from "../types";

const RATING_COLOR: Record<string, string> = {
  BUY: "var(--up)",
  HOLD: "var(--hold)",
  SELL: "var(--down)",
};

function monthLabel(period: string): string {
  const [year, month] = period.split("-");
  if (!year || !month) return period;
  const date = new Date(Number(year), Number(month) - 1, 1);
  return `${date.toLocaleString("en-US", { month: "short" })} '${year.slice(2)}`;
}

export default function AnalystConsensusCard({
  analyst,
  loading,
}: {
  analyst: AnalystData | null;
  loading: boolean;
}) {
  // Prior monthly snapshots come newest-first; show them oldest-first so
  // the mini table reads left-to-right in time, ending with the current
  // month. A Buy count that only ever falls across that whole span is
  // the warning signal called out below the table.
  const priorMonths = analyst?.history ? [...analyst.history].slice(0, 3).reverse() : [];
  const trendRows = analyst
    ? [
        ...priorMonths,
        {
          period: analyst.fetchedAt.slice(0, 7),
          buy: analyst.counts.buy,
          hold: analyst.counts.hold,
          sell: analyst.counts.sell,
          consensus: analyst.consensus,
        },
      ]
    : [];
  const buySeries = trendRows.map((row) => row.buy);
  const buyDeclining =
    buySeries.length >= 2 &&
    buySeries.every((value, i) => i === 0 || value <= buySeries[i - 1]) &&
    buySeries[buySeries.length - 1] < buySeries[0];

  return (
    <div className="card">
      <h2 className="card-title">Analyst Consensus</h2>
      {loading && <div className="skeleton" style={{ height: 64 }} />}
      {!loading && !analyst && <p className="empty-state">No consensus data available.</p>}
      {!loading && analyst && (
        <div className="consensus-body">
          <div className="consensus-big" style={{ color: RATING_COLOR[analyst.consensus] }}>
            {analyst.consensus}
          </div>
          <div className="consensus-breakdown">
            <span>
              <strong>{analyst.counts.buy}</strong> Buy
            </span>
            <span>
              <strong>{analyst.counts.hold}</strong> Hold
            </span>
            <span>
              <strong>{analyst.counts.sell}</strong> Sell
            </span>
          </div>
          {analyst.priceTarget.average != null && (
            <div className="consensus-target">
              Avg target ${analyst.priceTarget.average.toFixed(2)}
            </div>
          )}
          {priorMonths.length > 0 && (
            <div className="consensus-trend">
              <div className="consensus-trend-title">Trend</div>
              <table className="consensus-trend-table">
                <thead>
                  <tr>
                    <th>Month</th>
                    <th>Buy</th>
                    <th>Hold</th>
                    <th>Sell</th>
                  </tr>
                </thead>
                <tbody>
                  {trendRows.map((row, i) => (
                    <tr
                      key={row.period}
                      className={i === trendRows.length - 1 ? "consensus-trend-current" : undefined}
                    >
                      <td>{i === trendRows.length - 1 ? "Now" : monthLabel(row.period)}</td>
                      <td>{row.buy}</td>
                      <td>{row.hold}</td>
                      <td>{row.sell}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {buyDeclining && (
                <p className="consensus-trend-warning">Buy ratings declining month-over-month</p>
              )}
            </div>
          )}
        </div>
      )}
    </div>
  );
}
