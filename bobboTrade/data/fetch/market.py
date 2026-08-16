"""Fetch price/volume history and the current quote from Financial
Modeling Prep, and write public/data/<TICKER>/market.json.

Requires env var FMP_API_KEY.
"""
import sys

from common import get, get_required_env, utc_now_iso, write_json

FMP_BASE = "https://financialmodelingprep.com/api/v3"


def fetch_market(ticker: str) -> dict:
    api_key = get_required_env("FMP_API_KEY")

    quote_resp = get(f"{FMP_BASE}/quote/{ticker}", params={"apikey": api_key}).json()
    if not quote_resp:
        raise RuntimeError(f"FMP returned no quote data for {ticker}")
    quote = quote_resp[0]

    hist_resp = get(
        f"{FMP_BASE}/historical-price-full/{ticker}",
        params={"apikey": api_key, "timeseries": 1825},
    ).json()
    raw_history = hist_resp.get("historical", [])
    # FMP returns newest-first; the chart wants ascending chronological order.
    history = sorted(
        (
            {
                "time": row["date"],
                "open": row["open"],
                "high": row["high"],
                "low": row["low"],
                "close": row["close"],
                "volume": row["volume"],
            }
            for row in raw_history
        ),
        key=lambda p: p["time"],
    )

    two_week_change_percent = 0.0
    if len(history) >= 11:
        prior = history[-11]["close"]
        latest = history[-1]["close"]
        if prior:
            two_week_change_percent = (latest - prior) / prior * 100

    return {
        "ticker": ticker,
        "fetchedAt": utc_now_iso(),
        "source": "financialmodelingprep.com",
        "quote": {
            "price": quote.get("price"),
            "change": quote.get("change"),
            "changePercent": quote.get("changesPercentage"),
            "previousClose": quote.get("previousClose"),
            "marketCap": quote.get("marketCap"),
        },
        "history": history,
        "twoWeekChangePercent": round(two_week_change_percent, 2),
    }


def main(ticker: str) -> None:
    payload = fetch_market(ticker)
    write_json(ticker, "market.json", payload)


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "MPC")
