import type { MarketData } from "../types";
import InfoPopup from "./InfoPopup";

function interpret(pct: number): string {
  const mag = Math.abs(pct);
  const rounded = mag.toFixed(1);
  const dir = pct >= 0 ? "Up" : "Down";
  if (mag < 2) {
    return `${dir} ${rounded}% over 2 weeks — modest movement, not a strong signal either way.`;
  }
  if (mag < 6) {
    const noun = pct >= 0 ? "gain" : "decline";
    const tone = pct >= 0 ? "positive" : "negative";
    return `${dir} ${rounded}% over 2 weeks — a moderate ${noun}, showing some ${tone} momentum.`;
  }
  const noun = pct >= 0 ? "gain" : "decline";
  const move = pct >= 0 ? "up" : "down";
  return `${dir} ${rounded}% over 2 weeks — a large ${noun} for this period, a meaningful ${move} move.`;
}

export default function TwoWeekMovementCard({ market, loading }: { market: MarketData | null; loading: boolean }) {
  const pct = market?.twoWeekChangePercent ?? null;
  const up = (pct ?? 0) >= 0;

  return (
    <div className="card movement-card">
      <div className="card-title-row">
        <h2 className="card-title">2-Week Movement</h2>
        {pct != null && (
          <InfoPopup
            label="2-Week Movement"
            whatIsThis="The percent change in the closing price over the last 10 trading days (about two weeks). It's a quick read on short-term direction — whether the stock has been drifting up, drifting down, or going sideways. It says nothing about why the price moved."
            rightNow={interpret(pct)}
          />
        )}
      </div>
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
