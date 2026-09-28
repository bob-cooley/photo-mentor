"""Compare today's trading volume against its 20-day average, computed
locally from the price history market.py already fetched
(public/data/<TICKER>/market.json), and write
public/data/<TICKER>/volume.json.

Used to make its own Twelve Data /time_series call — a second, fully
redundant one, since market.py's own daily history call already
includes volume per bar (see rsi.py for the identical fix and the
same reasoning: with two tickers sharing one free-tier per-minute
rate limit, these duplicate calls were tipping runs into 429s, which
then meant the FTP deploy step DELETED the previous run's still-good
volume.json — files missing from the local build get removed, not
skipped — so it wasn't just going stale, it was disappearing).
"""
import json
import sys

from common import OUTPUT_ROOT, utc_now_iso, write_json

WINDOW = 20
HIGH_RATIO = 1.5
LOW_RATIO = 0.7


def classify(ratio: float) -> str:
    if ratio > HIGH_RATIO:
        return "high"
    if ratio < LOW_RATIO:
        return "low"
    return "normal"


def load_history(ticker: str) -> list[dict]:
    market_path = OUTPUT_ROOT / ticker / "market.json"
    if not market_path.exists():
        raise RuntimeError(f"No local market.json for {ticker} — market.py must run before volume.py")
    payload = json.loads(market_path.read_text())
    history = payload.get("history") or []
    if len(history) < 2:
        raise RuntimeError(f"Not enough price history for {ticker} to compute volume ({len(history)} days)")
    return history


def fetch_volume(ticker: str) -> dict:
    history = load_history(ticker)
    # market.py's history is ascending by date; take the trailing window.
    window = history[-WINDOW:]
    volumes = [row["volume"] for row in window]

    today_volume = volumes[-1]
    avg_volume = sum(volumes) / len(volumes)
    ratio = today_volume / avg_volume if avg_volume else 0.0

    return {
        "fetchedAt": utc_now_iso(),
        "source": "computed from twelvedata.com daily history",
        "volume": today_volume,
        "avgVolume": round(avg_volume),
        "ratio": round(ratio, 2),
        "classification": classify(ratio),
        "asOf": window[-1]["time"],
    }


def main(ticker: str) -> None:
    write_json(ticker, "volume.json", fetch_volume(ticker))


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "MPC")
