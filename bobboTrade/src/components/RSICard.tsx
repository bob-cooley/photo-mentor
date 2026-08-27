import type { RSIData } from "../types";
import InfoPopup from "./InfoPopup";

const ZONE_LABEL: Record<string, string> = {
  overbought: "Overbought",
  neutral: "Neutral",
  oversold: "Oversold",
};

// Plain-language read of the current value. Kept deliberately hedged —
// RSI is a "worth watching" signal, not a trade instruction.
function interpret(rsi: number, ticker: string): string {
  const v = Math.round(rsi);
  if (rsi >= 70) {
    return `At ${v}, ${ticker} is in overbought territory — it has risen faster than usual recently and may be due for a pullback. This doesn't mean sell immediately, but it's worth watching closely.`;
  }
  if (rsi <= 30) {
    return `At ${v}, ${ticker} is in oversold territory — it has fallen faster than usual recently and may be due for a bounce. This doesn't mean buy immediately, but it's worth watching closely.`;
  }
  if (rsi >= 55) {
    return `At ${v}, ${ticker} sits in the upper half of the neutral range — some upward momentum, nothing extreme.`;
  }
  if (rsi <= 45) {
    return `At ${v}, ${ticker} sits in the lower half of the neutral range — some downward momentum, nothing extreme.`;
  }
  return `At ${v}, ${ticker} is squarely neutral — recent gains and losses have been roughly balanced, with no momentum extreme in either direction.`;
}

export default function RSICard({
  rsi,
  loading,
  ticker,
}: {
  rsi: RSIData | null;
  loading: boolean;
  ticker: string;
}) {
  const value = rsi?.rsi ?? null;
  const zone = rsi?.zone ?? "neutral";
  const markerPct = value != null ? Math.max(0, Math.min(100, value)) : 0;

  return (
    <div className="card rsi-card">
      <div className="card-title-row">
        <h2 className="card-title">Relative Strength (RSI)</h2>
        {value != null && (
          <InfoPopup
            label="Relative Strength Index (RSI)"
            whatIsThis="RSI measures how fast a stock's price has been moving. It runs from 0 to 100. Readings above 70 suggest the stock has been climbing unusually fast (overbought); below 30 suggests it has been falling unusually fast (oversold); in between is considered normal."
            rightNow={interpret(value, ticker)}
          />
        )}
      </div>

      {loading && <div className="skeleton" style={{ height: 96 }} />}
      {!loading && value == null && <p className="empty-state">No RSI data available.</p>}
      {!loading && value != null && (
        <>
          <div className="rsi-readout">
            <span className="rsi-big">{value.toFixed(0)}</span>
            <span className={`rsi-zone-label rsi-zone-${zone}`}>{ZONE_LABEL[zone]}</span>
          </div>

          <div className="rsi-scale">
            <div className="rsi-track">
              <div className="rsi-marker" style={{ left: `${markerPct}%` }} />
            </div>
            <div className="rsi-scale-ticks">
              {[0, 30, 70, 100].map((tick) => (
                <span
                  key={tick}
                  style={{
                    left: `${tick}%`,
                    transform:
                      tick === 0 ? "translateX(0)" : tick === 100 ? "translateX(-100%)" : "translateX(-50%)",
                  }}
                >
                  {tick}
                </span>
              ))}
            </div>
          </div>
        </>
      )}
    </div>
  );
}
