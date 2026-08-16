"""Fetch EIA series for a stock's configured energy indicators and write
public/data/<TICKER>/energy.json.

Requires env var EIA_API_KEY. Indicator definitions (which EIA series map
to which stock) live in src/config/stocks/<TICKER>/config.json so this
script stays generic across future tickers.
"""
from __future__ import annotations

import sys

from common import get, get_required_env, load_stock_config, utc_now_iso, write_json

EIA_BASE = "https://api.eia.gov/v2"

# EIA series IDs map to a v2 route + facet + frequency, not a flat lookup —
# this table translates the dotted "PET.XXXX.D" style IDs used in stock
# configs into the v2 API's route/series-id/frequency shape. The v2 API
# times out if frequency is omitted for a route with multiple frequencies,
# so it must always be passed explicitly.
EIA_ROUTES = {
    "PET.RWTC.D": ("petroleum/pri/spt/data", "RWTC", "daily"),
    "PET.RBRTE.D": ("petroleum/pri/spt/data", "RBRTE", "daily"),
    "PET.WPULEUS3.W": ("petroleum/pnp/wiup/data", "WPULEUS3", "weekly"),
    "PET.WCRSTUS1.W": ("petroleum/stoc/wstk/data", "WCRSTUS1", "weekly"),
    "PET.WGTSTUS1.W": ("petroleum/stoc/wstk/data", "WGTSTUS1", "weekly"),
    "PET.WDISTUS1.W": ("petroleum/stoc/wstk/data", "WDISTUS1", "weekly"),
}

UNITS = {
    "PET.RWTC.D": "$/bbl",
    "PET.RBRTE.D": "$/bbl",
    "PET.WPULEUS3.W": "%",
    "PET.WCRSTUS1.W": "kbbl",
    "PET.WGTSTUS1.W": "kbbl",
    "PET.WDISTUS1.W": "kbbl",
}


def fetch_series(api_key: str, series_id: str) -> tuple[float | None, str | None]:
    route, series, frequency = EIA_ROUTES[series_id]
    resp = get(
        f"{EIA_BASE}/{route}",
        params={
            "api_key": api_key,
            "frequency": frequency,
            "data[0]": "value",
            "facets[series][]": series,
            "sort[0][column]": "period",
            "sort[0][direction]": "desc",
            "length": 1,
        },
    ).json()
    rows = resp.get("response", {}).get("data", [])
    if not rows:
        return None, None
    row = rows[0]
    value = row.get("value")
    return (float(value) if value is not None else None), row.get("period")


def fetch_energy(ticker: str) -> dict:
    api_key = get_required_env("EIA_API_KEY")
    config = load_stock_config(ticker)

    indicators = []
    for indicator in config.get("energyIndicators", []):
        if indicator.get("derived") or "eiaSeries" not in indicator:
            continue
        series_id = indicator["eiaSeries"]
        if series_id not in EIA_ROUTES:
            continue
        value, period = fetch_series(api_key, series_id)
        indicators.append(
            {
                "id": indicator["id"],
                "label": indicator["label"],
                "value": value,
                "unit": UNITS.get(series_id, ""),
                "asOf": period,
            }
        )

    return {
        "ticker": ticker,
        "fetchedAt": utc_now_iso(),
        "source": "eia.gov",
        "indicators": indicators,
    }


def main(ticker: str) -> None:
    payload = fetch_energy(ticker)
    write_json(ticker, "energy.json", payload)


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "MPC")
