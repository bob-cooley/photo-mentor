"""Fetch dividend history from Twelve Data and write
public/data/<TICKER>/dividends.json.

Requires env var TWELVEDATA_API_KEY.

Dividends only change about once a year for these holdings, so unlike
market.py this refreshes from the API at most once per ~20 hours. The
full payload is cached in a small git-committed state file
(state/dividends_<TICKER>.json) — the same cross-run persistence pattern
ai_insight.py uses — so the every-5-minute market-hours runs reuse the
cache instead of spending a Twelve Data credit (the free tier's
per-minute budget is tight; see rsi.py / volume.py for the history of
that biting).

Twelve Data's /dividends returns ex-date + per-share amount. If it also
returns payment_date that's used directly; otherwise the payment date is
derived from each ticker's fixed quarterly schedule in config.json
(dividends.paymentDay) — MPC and COP have paid like clockwork on the
same calendar day for years, and if that ever changes it's a one-line
config edit.
"""
from __future__ import annotations

import json
import sys
from datetime import date, datetime, timedelta, timezone
from pathlib import Path

from common import get, get_required_env, load_stock_config, utc_now_iso, write_json

TWELVEDATA_BASE = "https://api.twelvedata.com"
STATE_DIR = Path(__file__).resolve().parent / "state"

# How stale the cached payload may get before we spend an API credit.
REFRESH_AFTER_HOURS = 20
# How far back to ask for — a little over five years so the popup's
# "last five years, per quarter" table is always fully populated.
LOOKBACK_YEARS = 6
# Trailing quarters kept in the history list (5 years × 4).
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


def quarter_label(d: date) -> str:
    return f"{d.year} Q{quarter_of(d)}"


def derive_payment_date(ex: date, payment_day: int) -> str:
    """Pay on `payment_day` of the last month of the ex-date's calendar
    quarter (MPC pays ~the 10th of Mar/Jun/Sep/Dec, COP ~the 1st). If
    that lands on or before the ex-date — only possible for a late-in-
    the-quarter ex-date — roll to the next quarter."""
    last_month_of_quarter = quarter_of(ex) * 3
    pay = date(ex.year, last_month_of_quarter, min(payment_day, 28))
    if pay <= ex:
        month = last_month_of_quarter + 3
        year = ex.year + (month - 1) // 12
        month = (month - 1) % 12 + 1
        pay = date(year, month, min(payment_day, 28))
    return pay.isoformat()


def fetch_from_api(ticker: str, payment_day: int) -> dict:
    api_key = get_required_env("TWELVEDATA_API_KEY")
    start = (datetime.now(timezone.utc).date() - timedelta(days=LOOKBACK_YEARS * 366)).isoformat()

    resp = get(
        f"{TWELVEDATA_BASE}/dividends",
        params={"symbol": ticker, "apikey": api_key, "start_date": start},
    ).json()
    if resp.get("status") == "error":
        raise RuntimeError(f"Twelve Data dividends error for {ticker}: {resp.get('message')}")

    rows = resp.get("dividends") or []
    entries: list[dict] = []
    for row in rows:
        raw_ex = row.get("ex_date")
        try:
            amount = float(row.get("amount"))
        except (TypeError, ValueError):
            continue
        if not raw_ex or amount <= 0:
            continue
        try:
            ex = date.fromisoformat(raw_ex[:10])
        except (ValueError, TypeError):
            continue
        pay_date = None
        raw_pay = row.get("payment_date")
        if isinstance(raw_pay, str):
            try:
                pay_date = date.fromisoformat(raw_pay[:10]).isoformat()
            except ValueError:
                pay_date = None
        if pay_date is None:
            pay_date = derive_payment_date(ex, payment_day)
        pay = date.fromisoformat(pay_date)
        entries.append(
            {
                "quarter": quarter_label(pay),
                "year": pay.year,
                "q": quarter_of(pay),
                "perShare": round(amount, 4),
                "exDate": ex.isoformat(),
                "payDate": pay_date,
            }
        )

    # Newest first, de-duped by quarter (a stray special dividend in the
    # same quarter as a regular one would otherwise double a row).
    entries.sort(key=lambda e: e["payDate"], reverse=True)
    seen: set[str] = set()
    history: list[dict] = []
    for e in entries:
        if e["quarter"] in seen:
            continue
        seen.add(e["quarter"])
        history.append(e)
        if len(history) >= HISTORY_QUARTERS:
            break

    today = datetime.now(timezone.utc).date().isoformat()
    current = None
    for e in sorted(history, key=lambda e: e["payDate"]):
        if e["payDate"] >= today:
            current = {**e, "status": "upcoming"}
            break
    if current is None and history:
        current = {**history[0], "status": "paid"}

    return {
        "ticker": ticker,
        "fetchedAt": utc_now_iso(),
        "source": "twelvedata.com",
        "currency": resp.get("meta", {}).get("currency", "USD"),
        "current": current,
        "history": history,
    }


def build_payload(ticker: str) -> dict:
    config = load_stock_config(ticker)
    payment_day = int((config.get("dividends") or {}).get("paymentDay", DEFAULT_PAYMENT_DAY))

    state = load_state(ticker)
    if is_fresh(state):
        print(f"[bobboTrade] Dividends for {ticker} still fresh — reusing cached payload.")
        return state["payload"]

    try:
        payload = fetch_from_api(ticker, payment_day)
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
