"""Fetch analyst rating consensus from Finnhub, and write
public/data/<TICKER>/analyst.json.

Display-only: this module must never be combined with news interpretation
or AI commentary (see build spec) — it's the raw consensus, nothing else.

Requires env var FINNHUB_API_KEY. (FMP and Twelve Data were tried first —
both dead ends: FMP's free tier 402s on MPC entirely, and Twelve Data
gates /recommendations and /price_target to paid plans. Finnhub's free
tier includes recommendation trends but not price targets, so
priceTarget is always null here.)
"""
import sys

from common import get, get_required_env, utc_now_iso, write_json

FINNHUB_BASE = "https://finnhub.io/api/v1"


def consensus_from_counts(buy: int, hold: int, sell: int) -> str:
    if buy >= hold and buy >= sell:
        return "BUY"
    if sell >= buy and sell >= hold:
        return "SELL"
    return "HOLD"


def summarize_trend(row: dict) -> dict:
    """Collapse one Finnhub recommendation-trend row into the same
    buy/hold/sell/consensus shape used for the current period. Finnhub's
    `period` is the first-of-month date ("2026-08-01"); the card only
    needs the month, so it's truncated to "YYYY-MM"."""
    buy = (row.get("strongBuy", 0) or 0) + (row.get("buy", 0) or 0)
    hold = row.get("hold", 0) or 0
    sell = (row.get("strongSell", 0) or 0) + (row.get("sell", 0) or 0)
    return {
        "period": (row.get("period") or "")[:7],
        "buy": buy,
        "hold": hold,
        "sell": sell,
        "consensus": consensus_from_counts(buy, hold, sell),
    }


def fetch_analyst(ticker: str) -> dict:
    api_key = get_required_env("FINNHUB_API_KEY")

    trends = get(f"{FINNHUB_BASE}/stock/recommendation", params={"symbol": ticker, "token": api_key}).json()
    if not trends:
        raise RuntimeError(f"Finnhub returned no recommendation trends for {ticker}")

    # Trends are ordered newest-period-first. Finnhub's free tier returns
    # roughly the last 4 monthly snapshots; keep the current one as the
    # top-level fields and the prior 3 as `history` so the card can show
    # a month-over-month trend (a falling Buy count is the warning signal).
    current = summarize_trend(trends[0])
    history = [summarize_trend(row) for row in trends[1:4]]

    return {
        "ticker": ticker,
        "fetchedAt": utc_now_iso(),
        "source": "finnhub.io",
        "consensus": current["consensus"],
        "counts": {"buy": current["buy"], "hold": current["hold"], "sell": current["sell"]},
        "history": history,
        "priceTarget": {"average": None, "high": None, "low": None},
    }


def main(ticker: str) -> None:
    payload = fetch_analyst(ticker)
    write_json(ticker, "analyst.json", payload)


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "MPC")
