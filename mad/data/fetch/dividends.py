"""Build the quarterly dividend history from SEC XBRL company facts and
write public/data/<TICKER>/dividends.json.

No API key. Twelve Data's /dividends endpoint 403s on the free tier for
these tickers (same paid-tier gate as its /recommendations and
/price_target — see analyst.py), so this reads the numbers straight from
each company's own SEC filings instead:
data.sec.gov/api/xbrl/companyconcept/CIK<cik>/us-gaap/
CommonStockDividendsPerShareDeclared.json — the authoritative source,
free, and not rate-limited the way the market-data providers are.

Two wrinkles handled here:
- The 10-K reports only a full-year dividend figure, no Q4 quarterly, so
  Q4 is derived as FY minus Q1+Q2+Q3.
- SEC data lags ~a quarter (a quarterly figure only lands when the 10-Q
  is filed, weeks after quarter end). For any quarter up to the current
  calendar quarter that isn't in the filings yet, the most recent known
  rate is carried forward — MPC and COP have held-or-raised every
  quarter for years and both publish the next quarter's dividend by
  press release well before the 10-Q, so the carried value is what they
  actually pay.

Payment dates aren't in XBRL at all; they're derived from each ticker's
fixed quarterly schedule (dividends.paymentDay in config.json).

Refreshes from SEC at most once per ~20h; the full payload is cached in
a git-committed state file (state/dividends_<TICKER>.json), the same
cross-run persistence pattern ai_insight.py uses.
"""
from __future__ import annotations

import json
import sys
from datetime import date, datetime, timedelta, timezone
from pathlib import Path

from common import get, load_stock_config, utc_now_iso, write_json

SEC_CONCEPT_URL = (
    "https://data.sec.gov/api/xbrl/companyconcept/CIK{cik}/us-gaap/{concept}.json"
)
# Some filers tag dividends declared, some tag cash paid — try both.
SEC_CONCEPTS = ("CommonStockDividendsPerShareDeclared", "CommonStockDividendsPerShareCashPaid")
SEC_USER_AGENT = "bobboTrade dashboard (bob@bobcooleyphoto.com)"

STATE_DIR = Path(__file__).resolve().parent / "state"
REFRESH_AFTER_HOURS = 20
HISTORY_QUARTERS = 20
DEFAULT_PAYMENT_DAY = 15


def state_path(ticker: str) -> Path:
    return STATE_DIR / f"dividends_{ticker}.json"


def load_state(ticker: str) -> dict | None:
    path = state_path(ticker)
    if not path.exists():
        return None
    try:
        return json.loads(path.read_text())
    except (json.JSONDecodeError, OSError) as exc:
        print(f"[bobboTrade] Couldn't read dividends state ({path}): {exc}", file=sys.stderr)
        return None


def write_state(ticker: str, payload: dict) -> None:
    STATE_DIR.mkdir(parents=True, exist_ok=True)
    state_path(ticker).write_text(json.dumps(payload, indent=2))


def is_fresh(state: dict | None) -> bool:
    if not state:
        return False
    try:
        fetched = datetime.fromisoformat(state["payload"]["fetchedAt"])
    except (KeyError, TypeError, ValueError):
        return False
    if fetched.tzinfo is None:
        fetched = fetched.replace(tzinfo=timezone.utc)
    return datetime.now(timezone.utc) - fetched < timedelta(hours=REFRESH_AFTER_HOURS)


def quarter_of(d: date) -> int:
    return (d.month - 1) // 3 + 1


def next_quarter(key: tuple[int, int]) -> tuple[int, int]:
    year, q = key
    return (year + 1, 1) if q == 4 else (year, q + 1)


def payment_date_for(year: int, q: int, payment_day: int) -> str:
    return date(year, q * 3, min(payment_day, 28)).isoformat()


def fetch_sec_concept(cik: str) -> list[dict]:
    cik_padded = str(int(cik)).zfill(10)
    for concept in SEC_CONCEPTS:
        resp = get(
            SEC_CONCEPT_URL.format(cik=cik_padded, concept=concept),
            headers={"User-Agent": SEC_USER_AGENT},
        )
        if resp.status_code == 404:
            continue
        rows = resp.json().get("units", {}).get("USD/shares", [])
        if rows:
            return rows
    raise RuntimeError(f"SEC XBRL returned no dividend-per-share data for CIK {cik}")


def build_quarterly(rows: list[dict]) -> dict[tuple[int, int], float]:
    """Collapse XBRL duration facts into one dividend per calendar
    quarter. Quarterly 10-Q facts span ~3 months; the 10-K's full-year
    fact (~12 months) is used to back out Q4."""
    quarterly: dict[tuple[int, int], float] = {}
    annual: dict[int, float] = {}

    for row in rows:
        try:
            start = date.fromisoformat(row["start"])
            end = date.fromisoformat(row["end"])
            val = round(float(row["val"]), 4)
        except (KeyError, TypeError, ValueError):
            continue
        if val <= 0:
            continue
        span = (end - start).days
        if 80 <= span <= 100:
            quarterly[(end.year, quarter_of(end))] = val
        elif 350 <= span <= 380:
            annual[end.year] = val

    for year, fy_total in annual.items():
        first_three = [(year, q) for q in (1, 2, 3)]
        if (year, 4) not in quarterly and all(k in quarterly for k in first_three):
            q4 = round(fy_total - sum(quarterly[k] for k in first_three), 4)
            if q4 > 0:
                quarterly[(year, 4)] = q4

    return quarterly


def build_from_sec(ticker: str, cik: str, payment_day: int) -> dict:
    quarterly = build_quarterly(fetch_sec_concept(cik))
    if not quarterly:
        raise RuntimeError(f"No usable quarterly dividend figures for {ticker}")

    # Carry the latest known rate forward to the current calendar quarter
    # (SEC data lags a quarter; see module docstring).
    today = datetime.now(timezone.utc).date()
    current_key = (today.year, quarter_of(today))
    latest_key = max(quarterly)
    latest_rate = quarterly[latest_key]
    key = latest_key
    while key < current_key:
        key = next_quarter(key)
        quarterly.setdefault(key, latest_rate)

    ordered = sorted(quarterly, reverse=True)[:HISTORY_QUARTERS]
    history = [
        {
            "quarter": f"{year} Q{q}",
            "year": year,
            "q": q,
            "perShare": quarterly[(year, q)],
            "exDate": None,
            "payDate": payment_date_for(year, q, payment_day),
        }
        for (year, q) in ordered
    ]

    today_iso = today.isoformat()
    current_entry = next((e for e in history if (e["year"], e["q"]) == current_key), history[0])
    current = {
        **current_entry,
        "status": "upcoming" if current_entry["payDate"] >= today_iso else "paid",
    }

    return {
        "ticker": ticker,
        "fetchedAt": utc_now_iso(),
        "source": "SEC XBRL company facts (data.sec.gov)",
        "currency": "USD",
        "current": current,
        "history": history,
    }


def build_payload(ticker: str) -> dict:
    config = load_stock_config(ticker)
    cik = config.get("cik")
    if not cik:
        raise RuntimeError(f"{ticker} config has no cik — can't fetch dividends from SEC")
    payment_day = int((config.get("dividends") or {}).get("paymentDay", DEFAULT_PAYMENT_DAY))

    state = load_state(ticker)
    if is_fresh(state):
        print(f"[bobboTrade] Dividends for {ticker} still fresh — reusing cached payload.")
        return state["payload"]

    try:
        payload = build_from_sec(ticker, cik, payment_day)
        write_state(ticker, {"payload": payload})
        return payload
    except Exception as exc:  # noqa: BLE001 — a flaky fetch shouldn't drop the card
        if state and state.get("payload"):
            print(f"[bobboTrade] Dividends fetch failed for {ticker} ({exc}) — reusing cached payload.", file=sys.stderr)
            return state["payload"]
        raise


def main(ticker: str) -> None:
    write_json(ticker, "dividends.json", build_payload(ticker))


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "MPC")
