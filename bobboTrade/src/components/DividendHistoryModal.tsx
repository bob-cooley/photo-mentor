import { useEffect } from "react";
import type { DividendPayment } from "../types";
import { formatCurrency } from "../lib/format";

// Per-share dividend by quarter for the trailing five years — a plain
// reference table, no math. Modal shell mirrors ArticleModal / InfoPopup.
export default function DividendHistoryModal({
  ticker,
  history,
  onClose,
}: {
  ticker: string;
  history: DividendPayment[];
  onClose: () => void;
}) {
  useEffect(() => {
    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key === "Escape") onClose();
    };
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, [onClose]);

  const byYear = new Map<number, (number | null)[]>();
  for (const p of history) {
    if (!byYear.has(p.year)) byYear.set(p.year, [null, null, null, null]);
    byYear.get(p.year)![p.q - 1] = p.perShare;
  }
  const years = [...byYear.keys()].sort((a, b) => b - a).slice(0, 5);

  return (
    <div className="article-modal-backdrop" onClick={onClose}>
      <div className="article-modal dividend-history-modal" onClick={(e) => e.stopPropagation()}>
        <div className="article-modal-header">
          <div className="article-modal-meta">
            <span className="article-modal-source">{ticker}</span>
            <span className="news-dot">·</span>
            <span>Dividend per share by quarter</span>
          </div>
          <button className="article-modal-close" onClick={onClose} aria-label="Close">
            &#10005;
          </button>
        </div>

        <div className="article-modal-body">
          <table className="dividend-history-table">
            <thead>
              <tr>
                <th>Year</th>
                <th>Q1</th>
                <th>Q2</th>
                <th>Q3</th>
                <th>Q4</th>
              </tr>
            </thead>
            <tbody>
              {years.map((year) => (
                <tr key={year}>
                  <td>{year}</td>
                  {byYear.get(year)!.map((amount, i) => (
                    <td key={i}>{amount != null ? formatCurrency(amount) : "—"}</td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
