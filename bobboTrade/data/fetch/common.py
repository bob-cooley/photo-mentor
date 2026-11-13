"""Shared helpers for the bobboTrade data-fetch pipeline.

Every fetch script writes its output as static JSON under
public/data/<TICKER>/<name>.json, which the frontend loads at runtime
and the FTP deploy job ships as-is. Nothing here talks to the frontend
directly — the JSON file is the entire contract.
"""
from __future__ import annotations

import json
import os
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

import requests

BOBBOTRADE_ROOT = Path(__file__).resolve().parent.parent.parent
OUTPUT_ROOT = BOBBOTRADE_ROOT / "public" / "data"
STOCKS_CONFIG_ROOT = BOBBOTRADE_ROOT / "src" / "config" / "stocks"


def load_stock_config(ticker: str) -> dict:
    config_path = STOCKS_CONFIG_ROOT / ticker / "config.json"
    return json.loads(config_path.read_text())

DEFAULT_TIMEOUT = 20
MAX_ATTEMPTS = 3
RETRY_BACKOFF_SECONDS = 2

# 429 handling: up to 3 waits of 20s, 40s, 60s (or Retry-After if sent),
# so one blocked call can ride out a per-minute rate-limit window.
RATE_LIMIT_RETRIES = 3
RATE_LIMIT_BASE_WAIT_SECONDS = 20
RATE_LIMIT_MAX_WAIT_SECONDS = 65

# EIA's Akamai front 403s the default "python-requests/x.x" UA (and a
# plain custom one) specifically from GitHub Actions' runner IP ranges —
# confirmed by comparing identical requests from a residential IP (200)
# against three straight CI runs (403 every time). A browser-shaped
# header set clears it. SEC EDGAR still gets its own compliant UA via a
# per-call override in news.py, which takes precedence over this default.
DEFAULT_HEADERS = {
    "User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36",
    "Accept": "application/json, text/plain, */*",
    "Accept-Language": "en-US,en;q=0.9",
}


def utc_now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def get_required_env(name: str) -> str:
    value = os.environ.get(name)
    if not value:
        print(f"[bobboTrade] Missing required env var {name} — skipping this fetch.", file=sys.stderr)
        raise SystemExit(78)  # EX_CONFIG — treated as a soft skip by run_all.py
    return value


def write_json(ticker: str, filename: str, payload: dict) -> Path:
    out_dir = OUTPUT_ROOT / ticker
    out_dir.mkdir(parents=True, exist_ok=True)
    out_path = out_dir / filename
    out_path.write_text(json.dumps(payload, indent=2, ensure_ascii=False))
    print(f"[bobboTrade] Wrote {out_path.relative_to(BOBBOTRADE_ROOT)}")
    return out_path


def _request(method: str, url: str, **kwargs) -> requests.Response:
    """Shared retry loop — free-tier data providers (and the Claude API) are
    prone to transient timeouts and 5xx responses, which would otherwise
    take down an entire scheduled run over a single flaky request."""
    kwargs.setdefault("timeout", DEFAULT_TIMEOUT)
    headers = {**DEFAULT_HEADERS, **kwargs.pop("headers", {})}

    last_error: Exception | None = None
    attempt = 0
    rate_limit_hits = 0
    while True:
        attempt += 1
        try:
            resp = requests.request(method, url, headers=headers, **kwargs)
            if resp.status_code == 429 and rate_limit_hits < RATE_LIMIT_RETRIES:
                # Per-minute limits need a wait on the order of a minute, not
                # the 2 to 4 seconds that suits a transient 5xx.
                rate_limit_hits += 1
                time.sleep(_rate_limit_wait(resp, rate_limit_hits))
                attempt -= 1
                continue
            if resp.status_code in (500, 502, 503, 504) and attempt < MAX_ATTEMPTS:
                time.sleep(RETRY_BACKOFF_SECONDS * attempt)
                continue
            resp.raise_for_status()
            return resp
        except requests.exceptions.RequestException as exc:
            last_error = exc
            if isinstance(exc, requests.exceptions.HTTPError):
                raise
            if attempt < MAX_ATTEMPTS:
                time.sleep(RETRY_BACKOFF_SECONDS * attempt)
                continue
            raise last_error


def _rate_limit_wait(resp: requests.Response, hit: int) -> float:
    """Seconds to wait after a 429: the server's Retry-After when it sends a
    numeric one, otherwise a growing default. Capped so a bad header can't
    stall the whole scheduled run."""
    retry_after = resp.headers.get("Retry-After", "")
    if retry_after.isdigit():
        return min(float(retry_after), RATE_LIMIT_MAX_WAIT_SECONDS)
    return min(RATE_LIMIT_BASE_WAIT_SECONDS * hit, RATE_LIMIT_MAX_WAIT_SECONDS)


def get(url: str, **kwargs) -> requests.Response:
    return _request("GET", url, **kwargs)


def post(url: str, **kwargs) -> requests.Response:
    return _request("POST", url, **kwargs)
