"""Derive the US Gulf Coast 3-2-1 crack spread from EIA weekly spot
prices and write public/data/<TICKER>/crack_spread.json.

Requires env var EIA_API_KEY (already used by energy.py). EIA has no
single pre-calculated 3-2-1 series, so it's built here from three spot
prices, all Gulf Coast (PADD 3), the pricing point most relevant to a
Gulf Coast refiner like MPC:

  3-2-1 = (2 x gasoline + 1 x diesel - 3 x crude) / 3        [$/barrel]

Gasoline and diesel come from EIA in $/gallon and are converted to
$/barrel (x42) before the arithmetic; Brent is already $/barrel.

The crack spread is a market-wide number — the same for every ticker on
the dashboard — so the EIA fetch runs once per pipeline run (memoized
below) and the identical payload is written into each ticker's folder,
keeping the frontend's "one JSON file per card per ticker" contract
intact without N redundant API round-trips.
"""
from __future__ import annotations

import sys
from datetime import datetime, timedelta

from common import get, get_required_env, utc_now_iso, write_json

EIA_BASE = "https://api.eia.gov/v2"
GALLONS_PER_BARREL = 42

# EIA v2 spot-price series: (route, facets[series][], frequency).
GASOLINE_GC = ("petroleum/pri/spt/data", "EER_EPMRR_PF4_RGC_DPG", "weekly")  # RBOB regular, Gulf Coast, $/gal
DIESEL_GC = ("petroleum/pri/spt/data", "EER_EPD2DXL0_PF4_RGC_DPG", "weekly")  # ULSD, Gulf Coast, $/gal
BRENT = ("petroleum/pri/spt/data", "RBRTE", "daily")  # Brent, $/bbl

# Week-over-week change smaller than this (in $/bbl) reads as "stable"
# rather than a real expansion/compression — spot prices wobble a few
# cents week to week without the margin picture actually changing.
STABLE_BAND = 0.25


def _fetch_rows(api_key: str, series: tuple[str, str, str], length: int) -> list[dict]:
    route, series_id, frequency = series
    resp = get(
        f"{EIA_BASE}/{route}",
        params={
            "api_key": api_key,
            "frequency": frequency,
            "data[0]": "value",
            "facets[series][]": series_id,
            "sort[0][column]": "period",
            "sort[0][direction]": "desc",
            "length": length,
        },
    ).json()
    rows = resp.get("response", {}).get("data", [])
    return [r for r in rows if r.get("value") is not None]


def _parse_date(period: str) -> datetime | None:
    try:
        return datetime.strptime(period, "%Y-%m-%d")
    except (ValueError, TypeError):
        return None


def _row_nearest(rows: list[dict], target: datetime) -> dict:
    """The crude series is daily; pick the row closest to `target` so the
    crude leg lines up with the weekly product legs."""
    dated = [(r, _parse_date(r.get("period", ""))) for r in rows]
    dated = [(r, d) for r, d in dated if d is not None]
    return min(dated, key=lambda pair: abs((pair[1] - target).days))[0]


def _spread_321(gasoline_per_gal: float, diesel_per_gal: float, crude_per_bbl: float) -> float:
    gasoline_per_bbl = gasoline_per_gal * GALLONS_PER_BARREL
    diesel_per_bbl = diesel_per_gal * GALLONS_PER_BARREL
    return (2 * gasoline_per_bbl + diesel_per_bbl - 3 * crude_per_bbl) / 3


def _trend(change: float) -> str:
    if change > STABLE_BAND:
        return "expanding"
    if change < -STABLE_BAND:
        return "compressing"
    return "stable"


_CACHED_PAYLOAD: dict | None = None


def fetch_crack_spread() -> dict:
    global _CACHED_PAYLOAD
    if _CACHED_PAYLOAD is not None:
        return _CACHED_PAYLOAD

    api_key = get_required_env("EIA_API_KEY")

    gasoline = _fetch_rows(api_key, GASOLINE_GC, length=2)
    diesel = _fetch_rows(api_key, DIESEL_GC, length=2)
    crude = _fetch_rows(api_key, BRENT, length=15)
    if len(gasoline) < 2 or len(diesel) < 2 or len(crude) < 2:
        raise RuntimeError("EIA returned too few spot-price rows to build the crack spread")

    latest_date = _parse_date(gasoline[0]["period"])
    crude_now = crude[0]
    crude_prior = _row_nearest(crude, latest_date - timedelta(days=7)) if latest_date else crude[-1]

    current = _spread_321(
        float(gasoline[0]["value"]), float(diesel[0]["value"]), float(crude_now["value"])
    )
    prior = _spread_321(
        float(gasoline[1]["value"]), float(diesel[1]["value"]), float(crude_prior["value"])
    )
    change = current - prior

    _CACHED_PAYLOAD = {
        "fetchedAt": utc_now_iso(),
        "source": "eia.gov",
        "value": round(current, 2),
        "unit": "$/barrel",
        "trend": _trend(change),
        "changeWeekly": round(change, 2),
        "asOf": gasoline[0]["period"],
    }
    return _CACHED_PAYLOAD


def main(ticker: str) -> None:
    write_json(ticker, "crack_spread.json", fetch_crack_spread())


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "MPC")
