import type { AnalystData } from "../types";

const RATING_COLOR: Record<string, string> = {
  BUY: "var(--up)",
  HOLD: "var(--hold)",
  SELL: "var(--down)",
};

export default function AnalystConsensusCard({
  analyst,
  loading,
}: {
  analyst: AnalystData | null;
  loading: boolean;
}) {
  return (
    <div className="card">
      <h2 className="card-title">Analyst Consensus</h2>
      {loading && <div className="skeleton" style={{ height: 64 }} />}
      {!loading && !analyst && <p className="empty-state">No consensus data available.</p>}
      {!loading && analyst && (
        <>
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
        </>
      )}
    </div>
  );
}
