import { useEffect } from "react";
import type { InsightData } from "../types";
import { formatDate } from "../lib/format";

// Real-world per-call cost is ~$0.001 — two decimal places would show
// "$0.00" for weeks, which defeats the point of a spend monitor.
function formatUsageCost(usd: number): string {
  return usd > 0 && usd < 0.01 ? `$${usd.toFixed(4)}` : `$${usd.toFixed(2)}`;
}

const MONTHLY_BUDGET_USD = 3.0;

export default function UsagePopup({ insight, onClose }: { insight: InsightData | null; onClose: () => void }) {
  useEffect(() => {
    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key === "Escape") onClose();
    };
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, [onClose]);

  const usage = insight?.usage;

  return (
    <div className="article-modal-backdrop" onClick={onClose}>
      <div className="usage-popup" onClick={(e) => e.stopPropagation()}>
        <div className="article-modal-header">
          <h2 className="usage-popup-title">AI Usage This Month</h2>
          <button className="article-modal-close" onClick={onClose} aria-label="Close">
            &#10005;
          </button>
        </div>
        {usage ? (
          <>
            <div className="usage-popup-row">
              <span>Estimated cost</span>
              <span>{formatUsageCost(usage.estimatedCostUsd)}</span>
            </div>
            <div className="usage-popup-row">
              <span>Monthly budget</span>
              <span>${MONTHLY_BUDGET_USD.toFixed(2)}</span>
            </div>
            <div className="usage-popup-row">
              <span>Calls this month</span>
              <span>{usage.callsThisMonth}</span>
            </div>
            {insight?.fetchedAt && (
              <div className="usage-popup-row">
                <span>Last updated</span>
                <span>{formatDate(insight.fetchedAt)}</span>
              </div>
            )}
            <p className="usage-popup-note">
              Runs at most once an hour, so it won't change more often than that even when the page refreshes
              sooner.
            </p>
          </>
        ) : (
          <p className="usage-popup-note">No usage data yet.</p>
        )}
      </div>
    </div>
  );
}
