"""Summarize the last 90 days of insider transactions from Finnhub and
write public/data/<TICKER>/insider.json.

Requires env var FINNHUB_API_KEY (same key analyst.py / news.py use).
Finnhub's /stock/insider-transactions returns one row per Form 4 line
item: `change` is the signed share count (negative = disposal), and
`transactionCode` is the SEC code — "P" for an open-market purchase,
"S" for an open-market sale. Only P/S rows are counted here; grants,
option exercises, and tax withholdings ("A", "M", "F", ...) aren't
discretionary buy/sell decisions and would muddy the sentiment read.
"""
from __future__ import annotations

import sys
from datetime import datetime, timedelta, timezone

from common import get, get_required_env, utc_now_iso, write_json

FINNHUB_BASE = "https://finnhub.io/api/v1"
LOOKBACK_DAYS = 90
OPEN_MARKET_CODES = {"P", "S"}

# Net direction only counts as directional if it's a clear majority of
# the gross activity — otherwise a near-even mix reads as "neutral".
DIRECTIONAL_THRESHOLD = 0.15


def _parse_date(value: str | None) -> datetime | None:
    if not value:
        return None
    try:
        return datetime.strptime(value[:10], "%Y-%m-%d").replace(tzinfo=timezone.utc)
    except ValueError:
        return None


def fetch_insider(ticker: str) -> dict:
    api_key = get_required_env("FINNHUB_API_KEY")

    today = datetime.now(timezone.utc)
    cutoff = today - timedelta(days=LOOKBACK_DAYS)
    payload = get(
        f"{FINNHUB_BASE}/stock/insider-transactions",
        params={
            "symbol": ticker,
            "from": cutoff.date().isoformat(),
            "to": today.date().isoformat(),
            "token": api_key,
        },
    ).json()
    rows = payload.get("data", []) or []

    buy_count = 0
    sell_count = 0
    net_shares = 0
    net_value = 0.0
    gross_shares = 0
    latest: datetime | None = None

    for row in rows:
        code = (row.get("transactionCode") or "").upper()
        if code not in OPEN_MARKET_CODES:
            continue
        when = _parse_date(row.get("transactionDate")) or _parse_date(row.get("filingDate"))
        if when is None or when < cutoff:
            continue

        change = row.get("change") or 0
        price = row.get("transactionPrice") or 0
        if change > 0:
            buy_count += 1
        elif change < 0:
            sell_count += 1
        else:
            continue

        net_shares += change
        net_value += change * price
        gross_shares += abs(change)
        if latest is None or when > latest:
            latest = when

    if gross_shares == 0:
        sentiment = "neutral"
    elif abs(net_shares) / gross_shares < DIRECTIONAL_THRESHOLD:
        sentiment = "neutral"
    elif net_shares > 0:
        sentiment = "bullish"
    else:
        sentiment = "bearish"

    return {
        "fetchedAt": utc_now_iso(),
        "source": "finnhub.io",
        "sentiment": sentiment,
        "buyCount": buy_count,
        "sellCount": sell_count,
        "netShares": int(net_shares),
        "netValue": round(net_value),
        "period": "90d",
        "asOf": latest.date().isoformat() if latest else today.date().isoformat(),
    }


def main(ticker: str) -> None:
    write_json(ticker, "insider.json", fetch_insider(ticker))


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "MPC")
