import { useState } from "react";
import type { MarketData, PortfolioConfig } from "../types";
import { formatCurrency } from "../lib/format";

export default function PortfolioCard({
  market,
  portfolio,
}: {
  market: MarketData | null;
  portfolio: PortfolioConfig | null;
}) {
  const [manualShares, setManualShares] = useState<string>("");
  const [trimShares, setTrimShares] = useState<string>("");
  const shares = portfolio?.shares ?? (manualShares ? Number(manualShares) : null);
  const price = market?.quote.price ?? null;
  const value = shares != null && price != null ? shares * price : null;

  const trimCount = trimShares ? Number(trimShares) : null;
  const trimProceeds = trimCount != null && price != null ? trimCount * price : null;
  const remainingShares = shares != null && trimCount != null ? shares - trimCount : null;
  const remainingValue = remainingShares != null && price != null ? remainingShares * price : null;

  return (
    <div className="card">
      <h2 className="card-title">Portfolio</h2>
      {!portfolio && (
        <input
          className="portfolio-input"
          type="number"
          placeholder="Share count (not saved)"
          value={manualShares}
          onChange={(e) => setManualShares(e.target.value)}
        />
      )}
      {shares != null && value != null && (
        <div className="portfolio-summary">
          <div className="portfolio-row">
            <span>Shares</span>
            <strong>{shares.toLocaleString()}</strong>
          </div>
          <div className="portfolio-row">
            <span>Value</span>
            <strong>{formatCurrency(value, 0)}</strong>
          </div>
        </div>
      )}
      {shares == null && <p className="empty-state">Enter a share count to see position value.</p>}

      {shares != null && (
        <div className="portfolio-trim">
          <label className="portfolio-trim-label" htmlFor="trim-shares">
            Hypothetical trim
          </label>
          <input
            id="trim-shares"
            className="portfolio-input"
            type="number"
            placeholder="Shares to sell"
            value={trimShares}
            onChange={(e) => setTrimShares(e.target.value)}
          />
          {trimProceeds != null && remainingValue != null && (
            <div className="portfolio-summary">
              <div className="portfolio-row">
                <span>Proceeds</span>
                <strong>{formatCurrency(trimProceeds, 0)}</strong>
              </div>
              <div className="portfolio-row">
                <span>Remaining position</span>
                <strong>{formatCurrency(remainingValue, 0)}</strong>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
