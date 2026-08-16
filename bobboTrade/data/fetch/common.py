"""Shared helpers for the bobboTrade data-fetch pipeline.

Every fetch script writes its output as static JSON under
public/data/<TICKER>/<name>.json, which the frontend loads at runtime
and the FTP deploy job ships as-is. Nothing here talks to the frontend
directly — the JSON file is the entire contract.
"""
import json
import os
import sys
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


def get(url: str, **kwargs) -> requests.Response:
    kwargs.setdefault("timeout", DEFAULT_TIMEOUT)
    resp = requests.get(url, **kwargs)
    resp.raise_for_status()
    return resp
