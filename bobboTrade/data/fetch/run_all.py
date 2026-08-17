"""Orchestrator: runs every fetch module for every configured ticker.

Used both by the scheduled GitHub Actions job and for local runs. A
module that's missing its API key (SystemExit(78) from
common.get_required_env) is skipped with a warning rather than failing
the whole run — other modules and other tickers still get updated.
"""
import sys

import ai_insight
import analyst
import energy
import market
import news
from common import STOCKS_CONFIG_ROOT

MODULES = [
    ("market", market.main),
    ("energy", energy.main),
    ("analyst", analyst.main),
    ("news", news.main),
    ("ai_insight", ai_insight.main),
]


def discover_tickers() -> list[str]:
    if not STOCKS_CONFIG_ROOT.exists():
        return []
    return sorted(p.name for p in STOCKS_CONFIG_ROOT.iterdir() if (p / "config.json").exists())


def main() -> None:
    tickers = discover_tickers()
    if not tickers:
        print("[bobboTrade] No stock configs found under src/config/stocks/", file=sys.stderr)
        raise SystemExit(1)

    for ticker in tickers:
        for name, fn in MODULES:
            try:
                fn(ticker)
            except SystemExit as exc:
                if exc.code == 78:
                    print(f"[bobboTrade] Skipped {name} for {ticker} (missing config or not due yet).")
                else:
                    raise
            except Exception as exc:  # noqa: BLE001 — one module failing shouldn't block the rest
                print(f"[bobboTrade] {name} failed for {ticker}: {exc}", file=sys.stderr)


if __name__ == "__main__":
    main()
