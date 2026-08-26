import type { InsightData, MarketData } from "../types";

// Real-world per-call cost is ~$0.001 — two decimal places would show
// "$0.00" for weeks, which defeats the point of a spend monitor.
function formatUsageCost(usd: number): string {
  return usd > 0 && usd < 0.01 ? `$${usd.toFixed(4)}` : `$${usd.toFixed(2)}`;
}

export default function InsightCard({
  insight,
  ticker,
  market,
}: {
  insight: InsightData | null;
  ticker: string;
  market: MarketData | null;
}) {
  const hasContent = insight?.status === "ok" && !!insight.text;
  const moveClass = market ? (market.quote.change >= 0 ? "up" : "down") : "";

  return (
    <>
      <div className={`card ai-placeholder ${hasContent ? "ai-insight-active" : ""}`}>
        <h2 className={`card-title ${moveClass}`}>Why {ticker} Moved</h2>
        {!insight && (
          <p className="ai-placeholder-text">Coming soon: a plain-English explanation of why the price moved.</p>
        )}
        {insight && insight.status === "paused_budget" && (
          <p className="ai-placeholder-text">Paused for the rest of this month to stay within the usage budget.</p>
        )}
        {insight && insight.status === "ok" && insight.text && (
          <p className="ai-placeholder-text ai-insight-text">{insight.text}</p>
        )}
      </div>
      {insight?.usage && (
        <div className="card ai-usage-card">
          <p className="ai-usage-footer">
            {formatUsageCost(insight.usage.estimatedCostUsd)} this month · {insight.usage.callsThisMonth} calls
          </p>
        </div>
      )}
    </>
  );
}
