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
          <svg viewBox="0 0 100 70" className="movement-arrow" aria-hidden="true">
            {up ? (
              <>
                <rect x="39" y="37" width="22" height="27" rx="4" />
                <path d="M50 7 L22 37 L78 37 Z" />
              </>
            ) : (
              <>
                <rect x="39" y="6" width="22" height="27" rx="4" />
                <path d="M50 63 L22 33 L78 33 Z" />
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
