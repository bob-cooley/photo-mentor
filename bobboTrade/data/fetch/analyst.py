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


def fetch_analyst(ticker: str) -> dict:
    api_key = get_required_env("FINNHUB_API_KEY")

    trends = get(f"{FINNHUB_BASE}/stock/recommendation", params={"symbol": ticker, "token": api_key}).json()
    if not trends:
        raise RuntimeError(f"Finnhub returned no recommendation trends for {ticker}")

    # Trends are ordered newest-period-first.
    latest = trends[0]
    buy = (latest.get("strongBuy", 0) or 0) + (latest.get("buy", 0) or 0)
    hold = latest.get("hold", 0) or 0
    sell = (latest.get("strongSell", 0) or 0) + (latest.get("sell", 0) or 0)

    return {
        "ticker": ticker,
        "fetchedAt": utc_now_iso(),
        "source": "finnhub.io",
        "consensus": consensus_from_counts(buy, hold, sell),
        "counts": {"buy": buy, "hold": hold, "sell": sell},
        "priceTarget": {"average": None, "high": None, "low": None},
    }


def main(ticker: str) -> None:
    payload = fetch_analyst(ticker)
    write_json(ticker, "analyst.json", payload)


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "MPC")
