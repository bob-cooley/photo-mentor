import { useState } from "react";
import type { DividendData, PortfolioConfig } from "../types";
import { formatCurrency, formatYmd } from "../lib/format";
import InfoPopup from "./InfoPopup";
import DividendHistoryModal from "./DividendHistoryModal";

export default function DividendsCard({
  dividends,
  portfolio,
  loading,
  ticker,
}: {
  dividends: DividendData | null;
  portfolio: PortfolioConfig | null;
  loading: boolean;
  ticker: string;
}) {
  const [historyOpen, setHistoryOpen] = useState(false);

  const current = dividends?.current ?? null;
  const shares = portfolio?.shares ?? null;
  const expected = current && shares != null ? shares * current.perShare : null;
  const paid = current?.status === "paid";

  return (
    <div className="card dividends-card">
      <div className="card-title-row">
        <h2 className="card-title">Dividends</h2>
        <InfoPopup
          label="Dividends"
          whatIsThis={`A dividend is a cash payment ${ticker} makes to shareholders each quarter, quoted as an amount per share. Your payment is that amount multiplied by the number of shares you hold, deposited on the payment date.`}
          rightNow={
            current
              ? `For ${current.quarter}, ${ticker} is paying ${formatCurrency(current.perShare)} per share${
                  current.payDate
                    ? `, ${paid ? "paid" : "payable"} ${formatYmd(current.payDate)}`
                    : ""
                }.${
                  expected != null
                    ? ` On ${shares?.toLocaleString()} shares that's about ${formatCurrency(expected, 2)}.`
                    : " Enter a share count in the Portfolio module to see your total."
                }`
              : "No dividend data is available right now."
          }
          bottomLine="Dividends are only one part of total return — a rising payout is a good sign, a cut is a warning sign, but neither is a reason to act on its own."
        />
      </div>

      {loading && <div className="skeleton" style={{ height: 128 }} />}

      {!loading && !current && <p className="empty-state">No dividend data available.</p>}

      {!loading && current && (
        <>
          <div className="dividend-rows">
            <div className="dividend-row">
              <span>Quarter</span>
              <strong>{current.quarter}</strong>
            </div>
            <div className="dividend-row">
              <span>Per share</span>
              <strong className="dividend-pershare">{formatCurrency(current.perShare)}</strong>
            </div>
            <div className="dividend-row">
              <span>Expected payment</span>
              {expected != null ? (
                <strong>{formatCurrency(expected, 2)}</strong>
              ) : (
                <span className="dividend-hint">Set shares in Portfolio</span>
              )}
            </div>
            <div className="dividend-row">
              <span>{paid ? "Paid" : "Payment date"}</span>
              <strong>{current.payDate ? formatYmd(current.payDate) : "—"}</strong>
            </div>
          </div>

          {expected != null && shares != null && (
            <p className="dividend-basis">
              {shares.toLocaleString()} shares &times; {formatCurrency(current.perShare)}
            </p>
          )}

          {dividends && dividends.history.length > 0 && (
            <button className="dividend-history-btn" onClick={() => setHistoryOpen(true)}>
              5-year history
            </button>
          )}
        </>
      )}

      {historyOpen && dividends && (
        <DividendHistoryModal
          ticker={ticker}
          history={dividends.history}
          onClose={() => setHistoryOpen(false)}
        />
      )}
    </div>
  );
}
