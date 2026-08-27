"""Compare today's trading volume against its 20-day average, from Twelve
Data, and write public/data/<TICKER>/volume.json.

Requires env var TWELVEDATA_API_KEY (same key market.py uses). Volume on
its own says nothing about direction — the card pairs it with the price
move — but an unusually heavy or light day is worth surfacing.
"""
import sys

from common import get, get_required_env, utc_now_iso, write_json

TWELVEDATA_BASE = "https://api.twelvedata.com"
WINDOW = 20
HIGH_RATIO = 1.5
LOW_RATIO = 0.7


def classify(ratio: float) -> str:
    if ratio > HIGH_RATIO:
        return "high"
    if ratio < LOW_RATIO:
        return "low"
    return "normal"


def fetch_volume(ticker: str) -> dict:
    api_key = get_required_env("TWELVEDATA_API_KEY")

    series = get(
        f"{TWELVEDATA_BASE}/time_series",
        params={
            "symbol": ticker,
            "interval": "1day",
            "outputsize": WINDOW,
            "apikey": api_key,
        },
    ).json()
    if series.get("status") == "error":
        raise RuntimeError(f"Twelve Data time_series error for {ticker}: {series.get('message')}")

    values = series.get("values", []) or []
    volumes = [int(float(v["volume"])) for v in values if v.get("volume") not in (None, "")]
    if len(volumes) < 2:
        raise RuntimeError(f"Twelve Data returned too few volume rows for {ticker}")

    # Twelve Data returns newest-first.
    today_volume = volumes[0]
    avg_volume = sum(volumes) / len(volumes)
    ratio = today_volume / avg_volume if avg_volume else 0.0

    return {
        "fetchedAt": utc_now_iso(),
        "source": "twelvedata.com",
        "volume": today_volume,
        "avgVolume": round(avg_volume),
        "ratio": round(ratio, 2),
        "classification": classify(ratio),
        "asOf": values[0].get("datetime"),
    }


def main(ticker: str) -> None:
    write_json(ticker, "volume.json", fetch_volume(ticker))


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "MPC")
