import { useState } from "react";
import type { MarketData, PortfolioConfig } from "../types";
import { formatCurrency } from "../lib/format";

export default function PortfolioCard({
  market,
  portfolio,
  onSaveShares,
}: {
  market: MarketData | null;
  portfolio: PortfolioConfig | null;
  onSaveShares: (shares: number | null) => Promise<boolean>;
}) {
  const [editing, setEditing] = useState(false);
  const [draftShares, setDraftShares] = useState("");
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState(false);
  const [trimShares, setTrimShares] = useState("");

  const shares = portfolio?.shares ?? null;
  const price = market?.quote.price ?? null;
  const value = shares != null && price != null ? shares * price : null;

  const trimCount = trimShares ? Number(trimShares) : null;
  const trimProceeds = trimCount != null && price != null ? trimCount * price : null;
  const remainingShares = shares != null && trimCount != null ? shares - trimCount : null;
  const remainingValue = remainingShares != null && price != null ? remainingShares * price : null;

  function startEdit() {
    setDraftShares(shares != null ? String(shares) : "");
    setSaveError(false);
    setEditing(true);
  }

  async function handleSave() {
    const trimmed = draftShares.trim();
    const parsed = trimmed === "" ? null : Number(trimmed);
    if (parsed !== null && (Number.isNaN(parsed) || parsed < 0)) {
      setSaveError(true);
      return;
    }
    setSaving(true);
    setSaveError(false);
    const ok = await onSaveShares(parsed);
    setSaving(false);
    if (ok) {
      setEditing(false);
    } else {
      setSaveError(true);
    }
  }

  return (
    <div className="card">
      <h2 className="card-title">Portfolio</h2>

      {editing && (
        <div className="portfolio-edit">
          <input
            className="portfolio-input"
            type="number"
            placeholder="Share count"
            value={draftShares}
            onChange={(e) => setDraftShares(e.target.value)}
            autoFocus
          />
          <div className="portfolio-edit-actions">
            <button className="portfolio-btn portfolio-btn-primary" onClick={handleSave} disabled={saving}>
              {saving ? "Saving…" : "Save"}
            </button>
            <button className="portfolio-btn" onClick={() => setEditing(false)} disabled={saving}>
              Cancel
            </button>
          </div>
          {saveError && <p className="portfolio-error">Couldn't save. Try again.</p>}
        </div>
      )}

      {!editing && shares != null && value != null && (
        <div className="portfolio-summary">
          <div className="portfolio-row">
            <span>Shares</span>
            <span className="portfolio-row-value">
              <strong>{shares.toLocaleString()}</strong>
              <button className="portfolio-edit-link" onClick={startEdit}>
                Edit
              </button>
            </span>
          </div>
          <div className="portfolio-row">
            <span>Value</span>
            <strong>{formatCurrency(value, 0)}</strong>
          </div>
        </div>
      )}

      {!editing && shares == null && (
        <>
          <p className="empty-state">Enter a share count to see position value.</p>
          <div className="portfolio-actions-right">
            <button className="portfolio-btn portfolio-btn-primary" onClick={startEdit}>
              Enter share count
            </button>
          </div>
        </>
      )}

      {!editing && shares != null && (
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
