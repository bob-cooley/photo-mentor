import type { MarketData } from "../types";

export default function TwoWeekMovementCard({ market, loading }: { market: MarketData | null; loading: boolean }) {
  const pct = market?.twoWeekChangePercent ?? null;
  const up = (pct ?? 0) >= 0;

  return (
    <div className="card movement-card">
      <h2 className="card-title">2-Week Movement</h2>
      {loading && <div className="skeleton" style={{ height: 100 }} />}
      {!loading && pct != null && (
        <div className={`movement-visual ${up ? "up" : "down"}`}>
          <svg viewBox="0 0 100 100" className="movement-arrow" aria-hidden="true">
            {up ? (
              <path d="M50 90 L50 10 M50 10 L28 34 M50 10 L72 34" />
            ) : (
              <path d="M50 10 L50 90 M50 90 L28 66 M50 90 L72 66" />
            )}
            <text x="50" y="56" textAnchor="middle" className="movement-label">
              {up ? "UP" : "DOWN"}
            </text>
          </svg>
          <div className="movement-pct">
            {Math.abs(pct).toFixed(1)}% {up ? "increase" : "decrease"}
          </div>
        </div>
      )}
    </div>
  );
}
