import type { AnalystData } from "../types";
import InfoPopup from "./InfoPopup";

const RATING_COLOR: Record<string, string> = {
  BUY: "var(--up)",
  HOLD: "var(--hold)",
  SELL: "var(--down)",
};

function interpret(analyst: AnalystData): string {
  const { buy, hold, sell } = analyst.counts;
  const total = buy + hold + sell;
  const tally = `Right now, out of ${total} analysts, ${buy} say Buy, ${hold} say Hold, and ${sell} say Sell`;

  if (analyst.consensus === "BUY") {
    return (
      `${tally} — so the group leans toward Buy. ` +
      `In plain terms, most of the experts who follow this company closely think the stock is more likely to rise than fall. ` +
      `Bottom line: a positive sign — though analysts are often wrong, and this is just one opinion among many.`
    );
  }
  if (analyst.consensus === "SELL") {
    return (
      `${tally} — so the group leans toward Sell. ` +
      `In plain terms, more of the experts who follow this company closely think the stock is likely to fall than rise. ` +
      `Bottom line: a cautious sign — though analysts are often wrong, and this is just one thing to weigh.`
    );
  }
  return (
    `${tally} — so the group lands on Hold. ` +
    `In plain terms, the experts who follow this company closely are split, or think the stock is priced about right — no strong push either way. ` +
    `Bottom line: no clear signal here; the analysts don't see an obvious bargain or an obvious problem.`
  );
}

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
      <div className="card-title-row">
        <h2 className="card-title">Analyst Consensus</h2>
        {analyst && (
          <InfoPopup
            label="Analyst Consensus"
            whatIsThis="Wall Street analysts who study this company for a living vote on whether they think you should buy, hold, or sell the stock. This shows how that vote is split, and the average of their price predictions."
            rightNow={interpret(analyst)}
          />
        )}
      </div>
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
