import type { InsiderData } from "../types";
import { formatCompactNumber } from "../lib/format";
import InfoPopup from "./InfoPopup";

const SENTIMENT_META: Record<string, { label: string; className: string }> = {
  bullish: { label: "Bullish", className: "insider-sentiment-bullish" },
  bearish: { label: "Bearish", className: "insider-sentiment-bearish" },
  neutral: { label: "Neutral", className: "insider-sentiment-neutral" },
};

function plural(n: number, word: string): string {
  return `${n} ${word}${n === 1 ? "" : "s"}`;
}

function interpret(d: InsiderData, ticker: string): { rightNow: string; bottomLine: string } {
  const net = formatCompactNumber(Math.abs(d.netShares));

  if (d.sentiment === "bearish") {
    return {
      rightNow:
        `Over the last 90 days, ${ticker} insiders sold more of their own shares than they bought — about ${net} shares sold on a net basis, across ${plural(d.sellCount, "sale")} and ${plural(d.buyCount, "purchase")}. ` +
        `In plain terms, the people running the company are trimming their personal stake.`,
      bottomLine:
        `A caution sign — insider selling doesn't guarantee the stock will drop, but it's one of the signals worth watching alongside the others.`,
    };
  }
  if (d.sentiment === "bullish") {
    return {
      rightNow:
        `Over the last 90 days, ${ticker} insiders bought more of their own shares than they sold — about ${net} shares bought on a net basis, across ${plural(d.buyCount, "purchase")} and ${plural(d.sellCount, "sale")}. ` +
        `In plain terms, the people running the company are putting their own money into the stock.`,
      bottomLine:
        `Often a vote of confidence — it's no guarantee, but insiders know the business better than anyone else.`,
    };
  }
  return {
    rightNow:
      `Over the last 90 days, ${ticker} insider buying and selling roughly cancelled out — ${plural(d.buyCount, "purchase")} and ${plural(d.sellCount, "sale")}, with little net change either way.`,
    bottomLine:
      `No clear signal here — insiders aren't collectively leaning for or against the stock right now.`,
  };
}

export default function InsiderCard({
  insider,
  loading,
  ticker,
}: {
  insider: InsiderData | null;
  loading: boolean;
  ticker: string;
}) {
  const meta = SENTIMENT_META[insider?.sentiment ?? "neutral"] ?? SENTIMENT_META.neutral;
  const explain = insider ? interpret(insider, ticker) : null;

  let netLine = "No net change in shares held";
  if (insider && insider.netShares !== 0) {
    netLine = `${formatCompactNumber(Math.abs(insider.netShares))} shares ${
      insider.netShares > 0 ? "bought" : "sold"
    } on a net basis`;
  }

  return (
    <div className="card insider-card">
      <div className="card-title-row">
        <h2 className="card-title">Insider Activity</h2>
        {explain && (
          <InfoPopup
            label="Insider Activity"
            whatIsThis="Company insiders — executives and board members — are required to report when they buy or sell their own company's stock. When insiders are selling heavily, it can be a sign they expect the stock to drop. When they're buying, it's often a vote of confidence."
            rightNow={explain.rightNow}
            bottomLine={explain.bottomLine}
          />
        )}
      </div>

      {loading && <div className="skeleton" style={{ height: 96 }} />}
      {!loading && !insider && <p className="empty-state">No insider data available.</p>}
      {!loading && insider && (
        <div className="insider-body">
          <div className={`insider-sentiment ${meta.className}`}>{meta.label}</div>
          <div className="insider-counts">
            {insider.buyCount} buying
            <span className="insider-dot">·</span>
            {insider.sellCount} selling
          </div>
          <div className="insider-net">{netLine} over the last 90 days</div>
        </div>
      )}
    </div>
  );
}
