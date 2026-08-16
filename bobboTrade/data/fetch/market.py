"""Fetch price/volume history and the current quote from Twelve Data,
and write public/data/<TICKER>/market.json.

Requires env var TWELVEDATA_API_KEY. (Financial Modeling Prep was the
original choice here, but its free tier turned out to whitelist only
mega-cap tickers — MPC/VLO/PSX all 402 on quote, history, and analyst
endpoints. Twelve Data's free tier covers all US equities instead.)
"""
import sys

from common import get, get_required_env, utc_now_iso, write_json

TWELVEDATA_BASE = "https://api.twelvedata.com"


def fetch_market(ticker: str) -> dict:
    api_key = get_required_env("TWELVEDATA_API_KEY")

    quote = get(f"{TWELVEDATA_BASE}/quote", params={"symbol": ticker, "apikey": api_key}).json()
    if quote.get("status") == "error":
        raise RuntimeError(f"Twelve Data quote error for {ticker}: {quote.get('message')}")

    series = get(
        f"{TWELVEDATA_BASE}/time_series",
        params={"symbol": ticker, "interval": "1day", "outputsize": 1825, "apikey": api_key},
    ).json()
    if series.get("status") == "error":
        raise RuntimeError(f"Twelve Data time_series error for {ticker}: {series.get('message')}")

    # Twelve Data returns newest-first; the chart wants ascending order.
    history = sorted(
        (
            {
                "time": row["datetime"],
                "open": float(row["open"]),
                "high": float(row["high"]),
                "low": float(row["low"]),
                "close": float(row["close"]),
                "volume": int(row["volume"]),
            }
            for row in series.get("values", [])
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
        "source": "twelvedata.com",
        "quote": {
            "price": float(quote["close"]),
            "change": float(quote["change"]),
            "changePercent": float(quote["percent_change"]),
            "previousClose": float(quote["previous_close"]),
            "marketCap": None,
        },
        "history": history,
        "twoWeekChangePercent": round(two_week_change_percent, 2),
    }


def main(ticker: str) -> None:
    payload = fetch_market(ticker)
    write_json(ticker, "market.json", payload)


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "MPC")
