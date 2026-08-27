import type { CrackSpreadData } from "../types";
import InfoPopup from "./InfoPopup";

const TREND_META: Record<string, { label: string; className: string }> = {
  expanding: { label: "Expanding ↑", className: "crack-trend-expanding" },
  compressing: { label: "Compressing ↓", className: "crack-trend-compressing" },
  stable: { label: "Stable →", className: "crack-trend-stable" },
};

function interpret(value: number, trend: string, ticker: string): string {
  const v = Math.round(value);
  if (trend === "compressing") {
    return `At $${v}/barrel and compressing, refinery margins are tightening. This can be an early warning that ${ticker}'s earnings will soften in the coming weeks — worth watching if the trend continues.`;
  }
  if (trend === "expanding") {
    return `At $${v}/barrel and expanding, refinery margins are widening — a tailwind for ${ticker}'s earnings, since refiners make more on every barrel they process.`;
  }
  return `At $${v}/barrel and roughly flat week-over-week, refinery margins are holding steady. No near-term pressure on ${ticker}'s earnings from this in either direction.`;
}

export default function CrackSpreadCard({
  crackSpread,
  loading,
  ticker,
}: {
  crackSpread: CrackSpreadData | null;
  loading: boolean;
  ticker: string;
}) {
  const value = crackSpread?.value ?? null;
  const trend = crackSpread?.trend ?? "stable";
  const change = crackSpread?.changeWeekly ?? 0;
  const up = change >= 0;
  const meta = TREND_META[trend] ?? TREND_META.stable;

  return (
    <div className="card crack-card">
      <div className="card-title-row">
        <h2 className="card-title">Crack Spread</h2>
        {value != null && (
          <InfoPopup
            label="Crack Spread"
            whatIsThis="Crack spread is the profit margin oil refineries make — the difference between the cost of crude oil and the price they sell refined products like gasoline and diesel. When it's high, refineries are very profitable. When it's falling, profits are being squeezed."
            rightNow={interpret(value, trend, ticker)}
          />
        )}
      </div>

      {loading && <div className="skeleton" style={{ height: 96 }} />}
      {!loading && value == null && <p className="empty-state">No crack spread data available.</p>}
      {!loading && value != null && (
        <div className="crack-body">
          <div className="crack-value">
            ${value.toFixed(2)}
            <span className="crack-unit">/barrel</span>
          </div>
          <div className={`crack-change ${up ? "up" : "down"}`}>
            {up ? "↑" : "↓"} ${Math.abs(change).toFixed(2)} this week
          </div>
          <div className={`crack-trend ${meta.className}`}>{meta.label}</div>
        </div>
      )}
    </div>
  );
}
