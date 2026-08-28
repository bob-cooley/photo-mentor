"""Compute the 14-period daily RSI locally from the price history
market.py already fetched (public/data/<TICKER>/market.json), and
write public/data/<TICKER>/rsi.json.

Used to call Twelve Data's own /rsi technical-indicator endpoint
directly — a second Twelve Data call on top of the daily history
market.py already pulls. With two tickers sharing one free-tier
per-minute rate limit, that redundant call (plus volume.py's, see
that file) was enough to tip runs into 429s, which then meant the
FTP deploy step DELETED the previous run's still-good rsi.json (files
missing from the local build get removed, not skipped) — so an
intermittent rate limit was actually taking the card down entirely
between successful runs, not just leaving it stale. RSI is fully
derivable from the closing-price history already on disk, so no
extra API call is needed at all.
"""
import json
import sys

from common import OUTPUT_ROOT, utc_now_iso, write_json

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


def load_history(ticker: str) -> list[dict]:
    market_path = OUTPUT_ROOT / ticker / "market.json"
    if not market_path.exists():
        raise RuntimeError(f"No local market.json for {ticker} — market.py must run before rsi.py")
    payload = json.loads(market_path.read_text())
    history = payload.get("history") or []
    if len(history) < RSI_TIME_PERIOD + 1:
        raise RuntimeError(f"Not enough price history for {ticker} to compute RSI ({len(history)} days)")
    return history


def compute_rsi(closes: list[float], period: int = RSI_TIME_PERIOD) -> float:
    """Wilder's smoothed RSI — the standard formula (and what Twelve
    Data's own /rsi endpoint computes), not a naive unsmoothed average."""
    deltas = [closes[i] - closes[i - 1] for i in range(1, len(closes))]
    gains = [d if d > 0 else 0.0 for d in deltas]
    losses = [-d if d < 0 else 0.0 for d in deltas]

    avg_gain = sum(gains[:period]) / period
    avg_loss = sum(losses[:period]) / period
    for i in range(period, len(gains)):
        avg_gain = (avg_gain * (period - 1) + gains[i]) / period
        avg_loss = (avg_loss * (period - 1) + losses[i]) / period

    if avg_loss == 0:
        return 100.0
    rs = avg_gain / avg_loss
    return 100 - (100 / (1 + rs))


def fetch_rsi(ticker: str) -> dict:
    history = load_history(ticker)
    closes = [row["close"] for row in history]
    rsi = round(compute_rsi(closes), 2)

    return {
        "ticker": ticker,
        "fetchedAt": utc_now_iso(),
        "source": "computed from twelvedata.com daily history",
        "period": RSI_TIME_PERIOD,
        "interval": "1day",
        "asOf": history[-1]["time"],
        "rsi": rsi,
        "zone": zone_for(rsi),
    }


def main(ticker: str) -> None:
    write_json(ticker, "rsi.json", fetch_rsi(ticker))


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "MPC")
