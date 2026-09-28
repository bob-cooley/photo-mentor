import type { InsightData, MarketData } from "../types";

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
  const paragraphs = insight?.text ? insight.text.split(/\n\s*\n/).filter(Boolean) : [];

  return (
    <div className={`card ai-placeholder ${hasContent ? "ai-insight-active" : ""}`}>
      <h2 className={`card-title ai-insight-title ${moveClass}`}>
        <span>Why {ticker} Moved</span>
        {market && (
          <svg viewBox="0 0 100 70" className="ai-insight-arrow" aria-hidden="true">
            {moveClass === "up" ? (
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
        )}
      </h2>
      {!insight && (
        <p className="ai-placeholder-text">Coming soon: a plain-English explanation of why the price moved.</p>
      )}
      {insight && insight.status === "paused_budget" && (
        <p className="ai-placeholder-text">Paused for the rest of this month to stay within the usage budget.</p>
      )}
      {insight &&
        insight.status === "ok" &&
        paragraphs.map((p, i) => (
          <p key={i} className="ai-placeholder-text ai-insight-text">
            {p}
          </p>
        ))}
    </div>
  );
}
