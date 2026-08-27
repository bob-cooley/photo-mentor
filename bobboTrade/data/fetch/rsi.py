"""Fetch the 14-period daily RSI from Twelve Data and write
public/data/<TICKER>/rsi.json.

Requires env var TWELVEDATA_API_KEY (the same key market.py already uses).
RSI (Relative Strength Index) is a bounded 0-100 momentum oscillator;
the dashboard shows the latest completed daily value plus a plain-
language zone (overbought / neutral / oversold). Twelve Data's free
tier includes the /rsi technical-indicator endpoint.
"""
import sys

from common import get, get_required_env, utc_now_iso, write_json

TWELVEDATA_BASE = "https://api.twelvedata.com"
RSI_INTERVAL = "1day"
RSI_TIME_PERIOD = 14

# Conventional RSI thresholds. Kept here (not the frontend) so the stored
# zone and the number can never disagree.
OVERBOUGHT = 70
OVERSOLD = 30


def zone_for(rsi: float) -> str:
    if rsi >= OVERBOUGHT:
        return "overbought"
    if rsi <= OVERSOLD:
        return "oversold"
    return "neutral"


def fetch_rsi(ticker: str) -> dict:
    api_key = get_required_env("TWELVEDATA_API_KEY")

    data = get(
        f"{TWELVEDATA_BASE}/rsi",
        params={
            "symbol": ticker,
            "interval": RSI_INTERVAL,
            "time_period": RSI_TIME_PERIOD,
            "series_type": "close",
            "apikey": api_key,
        },
    ).json()
    if data.get("status") == "error":
        raise RuntimeError(f"Twelve Data RSI error for {ticker}: {data.get('message')}")

    values = data.get("values") or []
    if not values:
        raise RuntimeError(f"Twelve Data returned no RSI values for {ticker}")

    # Twelve Data returns newest-first; take the latest completed period.
    latest = values[0]
    rsi = round(float(latest["rsi"]), 2)

    return {
        "ticker": ticker,
        "fetchedAt": utc_now_iso(),
        "source": "twelvedata.com",
        "period": RSI_TIME_PERIOD,
        "interval": RSI_INTERVAL,
        "asOf": latest.get("datetime"),
        "rsi": rsi,
        "zone": zone_for(rsi),
    }


def main(ticker: str) -> None:
    write_json(ticker, "rsi.json", fetch_rsi(ticker))


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "MPC")
