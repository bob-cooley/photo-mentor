import type { CrackSpreadData } from "../types";
import InfoPopup from "./InfoPopup";

const TREND_META: Record<string, { label: string; className: string }> = {
  expanding: { label: "Expanding ↑", className: "crack-trend-expanding" },
  compressing: { label: "Compressing ↓", className: "crack-trend-compressing" },
  stable: { label: "Stable →", className: "crack-trend-stable" },
};

function interpret(value: number, trend: string, ticker: string): { rightNow: string; bottomLine: string } {
  const v = Math.round(value);
  if (trend === "compressing") {
    return {
      rightNow:
        `At $${v}/barrel and compressing, the gap between what crude oil costs and what gasoline and diesel sell for is shrinking. ` +
        `That means refineries like ${ticker} are making less profit on every barrel of oil they turn into fuel. ` +
        `Think of it like a store whose wholesale costs are creeping up toward what it can charge at the register — the thinner that gap gets, the less money it keeps. ` +
        `A shrinking gap can be an early sign that ${ticker}'s profits will weaken in the weeks ahead.`,
      bottomLine:
        `A caution sign — worth keeping an eye on if the gap keeps narrowing, but not something to act on today.`,
    };
  }
  if (trend === "expanding") {
    return {
      rightNow:
        `At $${v}/barrel and expanding, the gap between what crude oil costs and what gasoline and diesel sell for is getting bigger. ` +
        `That means refineries like ${ticker} are making more profit on every barrel of oil they turn into fuel. ` +
        `Think of it like a store buying goods wholesale for far less than it charges at the register — the wider that gap, the more money it keeps. ` +
        `Right now that gap is growing.`,
      bottomLine: `Good news for the stock, as long as the trend holds.`,
    };
  }
  return {
    rightNow:
      `At $${v}/barrel and about the same as last week, the gap between what crude oil costs and what gasoline and diesel sell for is holding steady. ` +
      `That gap is the profit refineries like ${ticker} make turning oil into fuel, and right now it's neither growing nor shrinking.`,
    bottomLine: `Nothing to react to here — this measure isn't pushing the stock up or down at the moment.`,
  };
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
  const explain = value != null ? interpret(value, trend, ticker) : null;

  return (
    <div className="card crack-card">
      <div className="card-title-row">
        <h2 className="card-title">Crack Spread</h2>
        {explain && (
          <InfoPopup
            label="Crack Spread"
            whatIsThis="Crack spread is the profit margin oil refineries make — the difference between the cost of crude oil and the price they sell refined products like gasoline and diesel. When it's high, refineries are very profitable. When it's falling, profits are being squeezed."
            rightNow={explain.rightNow}
            bottomLine={explain.bottomLine}
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
