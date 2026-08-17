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
              <>
                <rect x="39" y="38" width="22" height="54" rx="4" />
                <path d="M50 8 L22 38 L78 38 Z" />
              </>
            ) : (
              <>
                <rect x="39" y="8" width="22" height="54" rx="4" />
                <path d="M50 92 L22 62 L78 62 Z" />
              </>
            )}
          </svg>
          <div className="movement-pct">
            {Math.abs(pct).toFixed(1)}% {up ? "increase" : "decrease"}
          </div>
        </div>
      )}
    </div>
  );
}
