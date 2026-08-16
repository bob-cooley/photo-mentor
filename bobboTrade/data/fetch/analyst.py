"""Fetch analyst rating consensus and price target from Financial
Modeling Prep, and write public/data/<TICKER>/analyst.json.

Display-only: this module must never be combined with news interpretation
or AI commentary (see build spec) — it's the raw consensus, nothing else.

Requires env var FMP_API_KEY.
"""
import sys

from common import get, get_required_env, utc_now_iso, write_json

FMP_BASE = "https://financialmodelingprep.com/api/v3"


def consensus_from_counts(buy: int, hold: int, sell: int) -> str:
    if buy >= hold and buy >= sell:
        return "BUY"
    if sell >= buy and sell >= hold:
        return "SELL"
    return "HOLD"


def fetch_analyst(ticker: str) -> dict:
    api_key = get_required_env("FMP_API_KEY")

    grades_resp = get(f"{FMP_BASE}/grade-consensus/{ticker}", params={"apikey": api_key}).json()
    grades = grades_resp[0] if grades_resp else {}
    buy = (grades.get("strongBuy", 0) or 0) + (grades.get("buy", 0) or 0)
    hold = grades.get("hold", 0) or 0
    sell = (grades.get("strongSell", 0) or 0) + (grades.get("sell", 0) or 0)

    target_resp = get(f"{FMP_BASE}/price-target-consensus", params={"symbol": ticker, "apikey": api_key}).json()
    target = target_resp[0] if target_resp else {}

    return {
        "ticker": ticker,
        "fetchedAt": utc_now_iso(),
        "source": "financialmodelingprep.com",
        "consensus": consensus_from_counts(buy, hold, sell),
        "counts": {"buy": buy, "hold": hold, "sell": sell},
        "priceTarget": {
            "average": target.get("targetConsensus"),
            "high": target.get("targetHigh"),
            "low": target.get("targetLow"),
        },
    }


def main(ticker: str) -> None:
    payload = fetch_analyst(ticker)
    write_json(ticker, "analyst.json", payload)


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "MPC")
