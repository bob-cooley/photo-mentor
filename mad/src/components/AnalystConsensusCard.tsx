import { useState } from "react";
import type { AnalystData, RatingChange, RatingChangesData } from "../types";
import InfoPopup from "./InfoPopup";

const RATING_COLOR: Record<string, string> = {
  BUY: "var(--up)",
  HOLD: "var(--hold)",
  SELL: "var(--down)",
};

function interpret(analyst: AnalystData): { rightNow: string; bottomLine: string } {
  const { buy, hold, sell } = analyst.counts;
  const total = buy + hold + sell;
  const tally = `Right now, out of ${total} analysts, ${buy} say Buy, ${hold} say Hold, and ${sell} say Sell`;

  if (analyst.consensus === "BUY") {
    return {
      rightNow:
        `${tally} — so the group leans toward Buy. ` +
        `In plain terms, most of the experts who follow this company closely think the stock is more likely to rise than fall.`,
      bottomLine: `A positive sign — though analysts are often wrong, and this is just one opinion among many.`,
    };
  }
  if (analyst.consensus === "SELL") {
    return {
      rightNow:
        `${tally} — so the group leans toward Sell. ` +
        `In plain terms, more of the experts who follow this company closely think the stock is likely to fall than rise.`,
      bottomLine: `A cautious sign — though analysts are often wrong, and this is just one thing to weigh.`,
    };
  }
  return {
    rightNow:
      `${tally} — so the group lands on Hold. ` +
      `In plain terms, the experts who follow this company closely are split, or think the stock is priced about right — no strong push either way.`,
    bottomLine: `No clear signal here; the analysts don't see an obvious bargain or an obvious problem.`,
  };
}

function monthLabel(period: string): string {
  const [year, month] = period.split("-");
  if (!year || !month) return period;
  const date = new Date(Number(year), Number(month) - 1, 1);
  return `${date.toLocaleString("en-US", { month: "short" })} '${year.slice(2)}`;
}

const PREVIEW_CHANGES = 5;

function shortDate(ymd: string): string {
  const [y, m, d] = ymd.split("-").map(Number);
  if (!y || !m || !d) return ymd;
  return new Date(y, m - 1, d).toLocaleDateString("en-US", { month: "short", day: "numeric" });
}

function money(n: number): string {
  return `$${Math.round(n).toLocaleString("en-US")}`;
}

// One plain-language sentence per event. Grades are shown exactly as the
// firm worded them (Overweight, Outperform, Neutral...), not forced into
// Buy/Hold/Sell, since the mapping differs between firms.
function describeChange(c: RatingChange): { text: string; tone: "up" | "down" | "" } {
  const target =
    c.targetTo != null && c.targetFrom != null && c.targetFrom !== c.targetTo
      ? `${c.targetTo > c.targetFrom ? "raised" : "lowered"} its price target from ${money(c.targetFrom)} to ${money(c.targetTo)}`
      : c.targetTo != null
        ? `set a price target of ${money(c.targetTo)}`
        : null;

  switch (c.kind) {
    case "upgrade":
      return { text: `${c.firm} upgraded${c.fromGrade ? ` from ${c.fromGrade}` : ""} to ${c.toGrade ?? "a higher rating"}`, tone: "up" };
    case "downgrade":
      return { text: `${c.firm} downgraded${c.fromGrade ? ` from ${c.fromGrade}` : ""} to ${c.toGrade ?? "a lower rating"}`, tone: "down" };
    case "initiated":
      return { text: `${c.firm} started coverage${c.toGrade ? ` at ${c.toGrade}` : ""}`, tone: "" };
    default:
      return {
        text: target ? `${c.firm} ${target}${c.toGrade ? ` (${c.toGrade})` : ""}` : `${c.firm} reiterated ${c.toGrade ?? "its rating"}`,
        tone: c.targetTo != null && c.targetFrom != null ? (c.targetTo > c.targetFrom ? "up" : c.targetTo < c.targetFrom ? "down" : "") : "",
      };
  }
}

function ChangeList({ changes }: { changes: RatingChange[] }) {
  return (
    <ul className="rating-changes-list">
      {changes.map((c, i) => {
        const { text, tone } = describeChange(c);
        return (
          <li key={`${c.date}-${c.firm}-${i}`}>
            <span className="rating-change-date">{shortDate(c.date)}</span>
            <span className={`rating-change-text ${tone}`}>{text}</span>
          </li>
        );
      })}
    </ul>
  );
}

export default function AnalystConsensusCard({
  analyst,
  ratingChanges,
  loading,
}: {
  analyst: AnalystData | null;
  ratingChanges: RatingChangesData | null;
  loading: boolean;
}) {
  const [allChangesOpen, setAllChangesOpen] = useState(false);
  const changes = ratingChanges?.changes ?? [];
  // Prior monthly snapshots come newest-first; show them oldest-first so
  // the mini table reads left-to-right in time, ending with the current
  // month. A Buy count that only ever falls across that whole span is
  // the warning signal called out below the table.
  const priorMonths = analyst?.history ? [...analyst.history].slice(0, 3).reverse() : [];
  const trendRows = analyst
    ? [
        ...priorMonths,
        {
          // The newest snapshot is whatever month Finnhub last published,
          // not necessarily the month the page was fetched in.
          period: analyst.period ?? "",
          buy: analyst.counts.buy,
          hold: analyst.counts.hold,
          sell: analyst.counts.sell,
          consensus: analyst.consensus,
        },
      ]
    : [];
  const buySeries = trendRows.map((row) => row.buy);
  const buyDeclining =
    buySeries.length >= 2 &&
    buySeries.every((value, i) => i === 0 || value <= buySeries[i - 1]) &&
    buySeries[buySeries.length - 1] < buySeries[0];
  const explain = analyst ? interpret(analyst) : null;

  return (
    <div className="card">
      <div className="card-title-row">
        <h2 className="card-title">Analyst Consensus</h2>
        {explain && (
          <InfoPopup
            label="Analyst Consensus"
            whatIsThis="Wall Street analysts who study this company for a living vote on whether they think you should buy, hold, or sell the stock. This shows how that vote is split, and the average of their price predictions."
            rightNow={explain.rightNow}
            bottomLine={explain.bottomLine}
          />
        )}
      </div>
      {loading && <div className="skeleton" style={{ height: 64 }} />}
      {!loading && !analyst && <p className="empty-state">No consensus data available.</p>}
      {!loading && analyst && (
        <div className="consensus-body">
          <div className="consensus-big" style={{ color: RATING_COLOR[analyst.consensus] }}>
            {analyst.consensus}
          </div>
          <div className="consensus-breakdown">
            <span>
              <strong>{analyst.counts.buy}</strong> Buy
            </span>
            <span>
              <strong>{analyst.counts.hold}</strong> Hold
            </span>
            <span>
              <strong>{analyst.counts.sell}</strong> Sell
            </span>
          </div>
          {analyst.priceTarget.average != null && (
            <div className="consensus-target">
              Avg target ${analyst.priceTarget.average.toFixed(2)}
            </div>
          )}
          {priorMonths.length > 0 && (
            <div className="consensus-trend">
              <div className="consensus-trend-title">Trend</div>
              <table className="consensus-trend-table">
                <thead>
                  <tr>
                    <th>Month</th>
                    <th>Buy</th>
                    <th>Hold</th>
                    <th>Sell</th>
                  </tr>
                </thead>
                <tbody>
                  {trendRows.map((row, i) => (
                    <tr
                      key={row.period}
                      className={i === trendRows.length - 1 ? "consensus-trend-current" : undefined}
                    >
                      <td>{row.period ? monthLabel(row.period) : "Latest"}</td>
                      <td>{row.buy}</td>
                      <td>{row.hold}</td>
                      <td>{row.sell}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {buyDeclining && (
                <p className="consensus-trend-warning">Buy ratings declining month-over-month</p>
              )}
            </div>
          )}
          {changes.length > 0 && (
            <div className="rating-changes">
              <div className="consensus-trend-title">Recent changes</div>
              <ChangeList changes={changes.slice(0, PREVIEW_CHANGES)} />
              {changes.length > PREVIEW_CHANGES && (
                <button className="rating-changes-more" onClick={() => setAllChangesOpen(true)}>
                  Show all {changes.length}
                </button>
              )}
              <p className="rating-changes-source">Last {ratingChanges?.windowDays ?? 60} days. Source: Yahoo Finance.</p>
            </div>
          )}
        </div>
      )}
      {allChangesOpen && (
        <div className="article-modal-backdrop" onClick={() => setAllChangesOpen(false)}>
          <div className="article-modal" onClick={(e) => e.stopPropagation()}>
            <div className="article-modal-header">
              <div className="article-modal-meta">
                <span className="article-modal-source">Analyst rating changes</span>
                <span className="news-dot">·</span>
                <span>Last {ratingChanges?.windowDays ?? 60} days</span>
              </div>
              <button className="article-modal-close" onClick={() => setAllChangesOpen(false)} aria-label="Close">
                &#10005;
              </button>
            </div>
            <div className="article-modal-body">
              <ChangeList changes={changes} />
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
