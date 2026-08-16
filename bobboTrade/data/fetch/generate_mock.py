"""Generate realistic-shaped sample data for local development, so the
frontend can be built and tested before FMP/EIA API keys exist. Never
used in the deployed build — the scheduled pipeline overwrites these
files with real data on every run.
"""
import random
import sys
from datetime import datetime, timedelta, timezone
from zoneinfo import ZoneInfo

from common import load_stock_config, utc_now_iso, write_json


def generate_history(days: int, start_price: float) -> list[dict]:
    history = []
    price = start_price
    today = datetime.now(timezone.utc).date()
    for i in range(days, 0, -1):
        date = today - timedelta(days=i)
        if date.weekday() >= 5:
            continue
        drift = random.uniform(-0.018, 0.018)
        open_p = price
        close_p = round(open_p * (1 + drift), 2)
        high = round(max(open_p, close_p) * (1 + random.uniform(0, 0.01)), 2)
        low = round(min(open_p, close_p) * (1 - random.uniform(0, 0.01)), 2)
        history.append(
            {
                "time": date.isoformat(),
                "open": open_p,
                "high": high,
                "low": low,
                "close": close_p,
                "volume": random.randint(2_000_000, 9_000_000),
            }
        )
        price = close_p
    return history


def generate_market(ticker: str) -> dict:
    history = generate_history(365, 165.0)
    latest = history[-1]
    prev_close = history[-2]["close"]
    change = round(latest["close"] - prev_close, 2)
    two_week_prior = history[-11]["close"] if len(history) >= 11 else history[0]["close"]
    return {
        "ticker": ticker,
        "fetchedAt": utc_now_iso(),
        "source": "mock",
        "quote": {
            "price": latest["close"],
            "change": change,
            "changePercent": round(change / prev_close * 100, 2),
            "previousClose": prev_close,
            "marketCap": 55_000_000_000,
        },
        "history": history,
        "twoWeekChangePercent": round((latest["close"] - two_week_prior) / two_week_prior * 100, 2),
    }


def generate_energy(ticker: str) -> dict:
    config = load_stock_config(ticker)
    indicators = []
    for ind in config.get("energyIndicators", []):
        if ind.get("derived"):
            continue
        if "utilization" in ind["id"]:
            value, unit = round(random.uniform(70, 90), 1), "%"
        elif "inventories" in ind["id"]:
            value, unit = round(random.uniform(20_000, 250_000), 0), "kbbl"
        else:
            value, unit = round(random.uniform(60, 85), 2), "$/bbl"
        indicators.append(
            {
                "id": ind["id"],
                "label": ind["label"],
                "value": value,
                "unit": unit,
                "asOf": datetime.now(timezone.utc).date().isoformat(),
            }
        )
    return {"ticker": ticker, "fetchedAt": utc_now_iso(), "source": "mock", "indicators": indicators}


def generate_news(ticker: str) -> dict:
    config = load_stock_config(ticker)
    now = datetime.now(timezone.utc)
    articles = [
        {
            "id": "mock-1",
            "headline": f"{config['name']} files quarterly report",
            "summary": "Routine 10-Q filing covering the most recent fiscal quarter.",
            "source": "SEC EDGAR",
            "url": "https://www.sec.gov/",
            "publishedAt": (now - timedelta(days=2)).isoformat(),
            "relevance": 0.7,
        },
        {
            "id": "mock-2",
            "headline": f"{config['name']} announces refinery maintenance schedule",
            "summary": "Company outlines planned turnaround activity for the upcoming quarter.",
            "source": "Investor Relations",
            "url": "https://ir.marathonpetroleum.com/",
            "publishedAt": (now - timedelta(days=5)).isoformat(),
            "relevance": 0.85,
        },
    ]
    return {"ticker": ticker, "fetchedAt": utc_now_iso(), "articles": articles}


def generate_intraday(ticker: str, start_price: float) -> dict:
    exchange_tz = ZoneInfo("America/New_York")
    bars = []
    price = start_price
    today = datetime.now(exchange_tz).date()

    trading_days: list = []
    day = today
    while len(trading_days) < 7:
        if day.weekday() < 5:
            trading_days.append(day)
        day -= timedelta(days=1)
    trading_days.reverse()

    for day in trading_days:
        session_start = datetime.combine(day, datetime.min.time(), tzinfo=exchange_tz).replace(hour=9, minute=30)
        for step in range(78):  # 6.5hr session / 5min bars
            bar_time = session_start + timedelta(minutes=5 * step)
            drift = random.uniform(-0.004, 0.004)
            open_p = price
            close_p = round(open_p * (1 + drift), 2)
            high = round(max(open_p, close_p) * (1 + random.uniform(0, 0.002)), 2)
            low = round(min(open_p, close_p) * (1 - random.uniform(0, 0.002)), 2)
            bars.append(
                {
                    "time": int(bar_time.timestamp()),
                    "open": open_p,
                    "high": high,
                    "low": low,
                    "close": close_p,
                    "volume": random.randint(5_000, 60_000),
                }
            )
            price = close_p

    return {
        "ticker": ticker,
        "fetchedAt": utc_now_iso(),
        "source": "mock",
        "interval": "5min",
        "bars": bars,
    }


def generate_analyst(ticker: str) -> dict:
    return {
        "ticker": ticker,
        "fetchedAt": utc_now_iso(),
        "source": "mock",
        "consensus": "HOLD",
        "counts": {"buy": 8, "hold": 11, "sell": 2},
        "priceTarget": {"average": 178.5, "high": 210.0, "low": 150.0},
    }


def main(ticker: str) -> None:
    market = generate_market(ticker)
    write_json(ticker, "market.json", market)
    write_json(ticker, "intraday.json", generate_intraday(ticker, market["quote"]["price"]))
    write_json(ticker, "energy.json", generate_energy(ticker))
    write_json(ticker, "news.json", generate_news(ticker))
    write_json(ticker, "analyst.json", generate_analyst(ticker))


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "MPC")
