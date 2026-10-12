"""Recent analyst rating changes, written to
public/data/<TICKER>/rating_changes.json.

Why this exists: the consensus module (analyst.py, Finnhub) is a monthly
snapshot of how many analysts say Buy/Hold/Sell, and that tally moves a
handful of times a year no matter how often it is fetched. What changes
day to day is the individual events: a firm upgrades, downgrades, starts
coverage, or moves its price target. Finnhub's upgrade/downgrade and
price-target endpoints are premium (confirmed: HTTP 403 on the free key),
so this reads Yahoo Finance's per-event history through yfinance.

Verified from GitHub's runners on 2026-10-12: MPC and COP both return a
few hundred rows with date, firm, from/to grade, and current/prior price
target.

Yahoo's endpoint is unofficial and can break or throttle without notice,
so:
- it is fetched at most every REFRESH_AFTER_HOURS, not on every 5-minute
  run;
- a failed fetch reuses the last good payload, and the output file is
  rewritten every run (the FTP deploy deletes remote files missing from
  the build, which has already wiped good data twice);
- a failure is not retried more often than RETRY_AFTER_MINUTES.
"""
from __future__ import annotations

import json
import sys
from datetime import date, datetime, timedelta, timezone
from pathlib import Path

from common import utc_now_iso, write_json

STATE_DIR = Path(__file__).resolve().parent / "state"
REFRESH_AFTER_HOURS = 3
RETRY_AFTER_MINUTES = 30
WINDOW_DAYS = 60
MAX_CHANGES = 20

KIND_BY_ACTION = {
    "up": "upgrade",
    "down": "downgrade",
    "init": "initiated",
    "main": "maintained",
    "reit": "maintained",
}


def state_path(ticker: str) -> Path:
    return STATE_DIR / f"rating_changes_{ticker}.json"


def load_state(ticker: str) -> dict | None:
    path = state_path(ticker)
    if not path.exists():
        return None
    try:
        return json.loads(path.read_text())
    except (json.JSONDecodeError, OSError) as exc:
        print(f"[bobboTrade] Couldn't read rating-changes state ({path}): {exc}", file=sys.stderr)
        return None


def write_state(ticker: str, state: dict) -> None:
    STATE_DIR.mkdir(parents=True, exist_ok=True)
    state_path(ticker).write_text(json.dumps(state, indent=2))


def parse_iso(stamp: str | None) -> datetime | None:
    if not stamp:
        return None
    try:
        when = datetime.fromisoformat(stamp)
    except ValueError:
        return None
    return when if when.tzinfo else when.replace(tzinfo=timezone.utc)


def age(stamp: str | None) -> timedelta | None:
    when = parse_iso(stamp)
    return datetime.now(timezone.utc) - when if when else None


def clean_text(value) -> str | None:
    if value is None:
        return None
    if isinstance(value, float) and value != value:  # NaN
        return None
    text = str(value).strip()
    return text or None


def clean_number(value) -> float | None:
    """Yahoo uses 0.0 and NaN to mean 'no price target'."""
    try:
        number = float(value)
    except (TypeError, ValueError):
        return None
    if number != number or number == 0.0:
        return None
    return number


def normalize_rows(rows: list[dict], today: date, window_days: int = WINDOW_DAYS) -> list[dict]:
    cutoff = today - timedelta(days=window_days)
    changes = []
    for row in rows:
        stamp = str(row.get("GradeDate", ""))[:10]
        try:
            when = date.fromisoformat(stamp)
        except ValueError:
            continue
        if when < cutoff or when > today:
            continue

        firm = clean_text(row.get("Firm"))
        if not firm:
            continue

        kind = KIND_BY_ACTION.get((clean_text(row.get("Action")) or "").lower(), "maintained")
        target_from = clean_number(row.get("priorPriceTarget"))
        target_to = clean_number(row.get("currentPriceTarget"))

        # A "maintained" entry with no price-target change is a reiteration:
        # nothing happened, so it is noise in a list of changes.
        if kind == "maintained" and (target_to is None or target_from == target_to):
            continue

        changes.append(
            {
                "date": when.isoformat(),
                "firm": firm,
                "kind": kind,
                "fromGrade": clean_text(row.get("FromGrade")),
                "toGrade": clean_text(row.get("ToGrade")),
                "targetAction": clean_text(row.get("priceTargetAction")),
                "targetFrom": target_from,
                "targetTo": target_to,
            }
        )

    changes.sort(key=lambda c: c["date"], reverse=True)
    return changes[:MAX_CHANGES]


def fetch_rows(ticker: str) -> list[dict]:
    import yfinance as yf  # lazy: only needed on a real fetch

    frame = yf.Ticker(ticker).upgrades_downgrades
    if frame is None or len(frame) == 0:
        raise RuntimeError(f"Yahoo returned no rating history for {ticker}")
    return frame.reset_index().to_dict("records")


def empty_payload(ticker: str) -> dict:
    return {
        "ticker": ticker,
        "fetchedAt": utc_now_iso(),
        "source": "finance.yahoo.com",
        "windowDays": WINDOW_DAYS,
        "status": "unavailable",
        "changes": [],
    }


def build_payload(ticker: str) -> dict:
    state = load_state(ticker) or {}
    cached = state.get("payload")

    fetched_age = age(cached.get("fetchedAt")) if cached else None
    if cached and fetched_age is not None and fetched_age < timedelta(hours=REFRESH_AFTER_HOURS):
        print(f"[bobboTrade] Rating changes for {ticker} still fresh, reusing cached payload.")
        return cached

    failure_age = age(state.get("lastFailureAt"))
    if cached and failure_age is not None and failure_age < timedelta(minutes=RETRY_AFTER_MINUTES):
        print(f"[bobboTrade] Rating changes for {ticker}: recent failure, reusing cached payload.")
        return cached

    try:
        rows = fetch_rows(ticker)
        payload = {
            "ticker": ticker,
            "fetchedAt": utc_now_iso(),
            "source": "finance.yahoo.com",
            "windowDays": WINDOW_DAYS,
            "status": "ok",
            "changes": normalize_rows(rows, datetime.now(timezone.utc).date()),
        }
        write_state(ticker, {"payload": payload})
        return payload
    except Exception as exc:  # noqa: BLE001 - an unofficial source must never fail the run
        print(f"[bobboTrade] Rating changes fetch failed for {ticker}: {exc}", file=sys.stderr)
        write_state(ticker, {"payload": cached, "lastFailureAt": utc_now_iso()})
        return cached if cached else empty_payload(ticker)


def main(ticker: str) -> None:
    write_json(ticker, "rating_changes.json", build_payload(ticker))


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "MPC")
