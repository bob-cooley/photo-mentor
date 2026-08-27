import type { MarketData } from "../types";
import InfoPopup from "./InfoPopup";

function interpret(pct: number, price: number | null): string {
  const mag = Math.abs(pct);
  const rounded = mag.toFixed(1);
  const dir = pct >= 0 ? "Up" : "Down";

  // Turn the percentage into a concrete before/after dollar figure. Use
  // the real share price when we have it; otherwise fall back to a round
  // $100 so the sentence still makes sense.
  const now = price ?? 100;
  const then = now / (1 + pct / 100);
  const money = (n: number) => `$${n.toFixed(2)}`;
  const picture = `Picture a stock worth ${money(then)} two weeks ago that's worth ${money(now)} today`;

  if (mag < 2) {
    return (
      `${dir} ${rounded}% over two weeks is a small move. ` +
      `${picture} — barely different. ` +
      `Stock prices drift around like this all the time. ` +
      `Bottom line: not much has really changed, and this isn't telling you to do anything.`
    );
  }
  if (mag < 6) {
    return (
      `${dir} ${rounded}% over two weeks is a moderate move. ` +
      `${picture}. ` +
      `There's some real direction to it, but two weeks is a short window and moves this size often don't stick. ` +
      `Bottom line: worth noting, but not a reason to act on its own.`
    );
  }
  return (
    `${dir} ${rounded}% over two weeks is a large move for such a short stretch. ` +
    `${picture}. ` +
    `Something has been pushing the stock ${pct >= 0 ? "up" : "down"} — the news headlines and the other cards can help explain what. ` +
    `Bottom line: worth understanding the reason, but a jump this fast can fade just as quickly.`
  );
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
            rightNow={interpret(pct, market?.quote.price ?? null)}
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
