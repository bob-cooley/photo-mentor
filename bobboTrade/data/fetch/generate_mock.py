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
            value, unit = round(random.uniform(70, 90), 1), "percent"
        elif "inventories" in ind["id"]:
            value, unit = round(random.uniform(20, 250), 1), "million_barrels"
        else:
            value, unit = round(random.uniform(60, 85), 2), "usd_per_barrel"
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
            "id": "mock-0",
            "headline": f"Refiner stocks climb as {config['name']} leads sector gains",
            "summary": "Shares of major refiners rose Tuesday as crack spreads widened.",
            "source": "CNBC",
            "url": "https://www.cnbc.com/",
            "fullText": (
                "Shares of major refiners rose Tuesday as crack spreads widened on tighter "
                "gasoline supply heading into the fall.\n\n"
                f"{config['name']} led the sector, up more than 2% in afternoon trading, as "
                "analysts pointed to strong refining margins and resilient demand.\n\n"
                "This is placeholder mock text for local development — the real pipeline "
                "extracts genuine article text via trafilatura from CNBC/wire-service URLs."
            ),
            "publishedAt": (now - timedelta(hours=6)).isoformat(),
            "relevance": 1.0,
        },
        {
            "id": "mock-energy-0",
            "headline": "Oil prices dip as traders weigh supply outlook",
            "summary": "Crude futures slipped Tuesday amid mixed signals on global supply.",
            "source": "CNBC",
            "url": "https://www.cnbc.com/",
            "fullText": (
                "Crude oil futures slipped Tuesday as traders weighed mixed signals on global "
                "supply, with West Texas Intermediate down about 1.5% in afternoon trading.\n\n"
                "This is placeholder mock text for local development — the real pipeline pulls "
                "genuine energy-sector reporting from CNBC's public Energy RSS feed, which isn't "
                "ticker-scoped: it covers crude prices, OPEC, and geopolitical events that move a "
                "refiner's stock without ever mentioning the company by name."
            ),
            "publishedAt": (now - timedelta(hours=14)).isoformat(),
            "relevance": 0.85,
        },
        {
            "id": "mock-1",
            "headline": f"{config['name']}: Quarterly Report",
            "summary": "Quarterly report for the period ended 2026-06-30.",
            "source": "SEC EDGAR",
            "url": "https://www.sec.gov/",
            "fullText": None,
            "publishedAt": (now - timedelta(days=2)).isoformat(),
            "relevance": 0.7,
        },
        {
            "id": "mock-2",
            "headline": f"{config['name']}: Announced quarterly earnings",
            "summary": "",
            "source": "SEC EDGAR",
            "url": "https://www.sec.gov/",
            "fullText": None,
            "publishedAt": (now - timedelta(days=5)).isoformat(),
            "relevance": 0.9,
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
    now = datetime.now(timezone.utc)

    def month_ago(n: int) -> str:
        y, m = now.year, now.month - n
        while m <= 0:
            m += 12
            y -= 1
        return f"{y:04d}-{m:02d}"

    # Falling Buy count across the prior 3 months into the current one —
    # exercises the "Buy ratings declining" warning path in the card.
    return {
        "ticker": ticker,
        "fetchedAt": utc_now_iso(),
        "source": "mock",
        "consensus": "HOLD",
        "counts": {"buy": 8, "hold": 11, "sell": 2},
        "history": [
            {"period": month_ago(1), "buy": 10, "hold": 10, "sell": 1, "consensus": "BUY"},
            {"period": month_ago(2), "buy": 11, "hold": 9, "sell": 1, "consensus": "BUY"},
            {"period": month_ago(3), "buy": 12, "hold": 8, "sell": 1, "consensus": "BUY"},
        ],
        "priceTarget": {"average": 178.5, "high": 210.0, "low": 150.0},
    }


def generate_rsi(ticker: str) -> dict:
    # Fixed in the overbought zone so local dev exercises the RSICard's
    # "may be due for a pullback" copy — the real pipeline pulls the live
    # value from Twelve Data.
    rsi_value = 74.2
    if rsi_value >= 70:
        zone = "overbought"
    elif rsi_value <= 30:
        zone = "oversold"
    else:
        zone = "neutral"
    return {
        "ticker": ticker,
        "fetchedAt": utc_now_iso(),
        "source": "mock",
        "period": 14,
        "interval": "1day",
        "asOf": datetime.now(timezone.utc).date().isoformat(),
        "rsi": rsi_value,
        "zone": zone,
    }


def generate_insight(ticker: str) -> dict:
    return {
        "ticker": ticker,
        "fetchedAt": utc_now_iso(),
        "text": (
            "Mock insight text, paragraph one — this is only shown during local development, "
            "standing in for what Claude actually writes from real price/news/energy data.\n\n"
            "Mock insight text, paragraph two — the real output is 3-5 sentences across two "
            "short paragraphs like this one, not a single run-on line."
        ),
        "status": "ok",
        "usage": {"month": datetime.now(timezone.utc).strftime("%Y-%m"), "callsThisMonth": 3, "inputTokens": 2400, "outputTokens": 210, "estimatedCostUsd": 0.0034},
    }


def main(ticker: str) -> None:
    market = generate_market(ticker)
    write_json(ticker, "market.json", market)
    write_json(ticker, "intraday.json", generate_intraday(ticker, market["quote"]["price"]))
    write_json(ticker, "energy.json", generate_energy(ticker))
    write_json(ticker, "news.json", generate_news(ticker))
    write_json(ticker, "analyst.json", generate_analyst(ticker))
    write_json(ticker, "rsi.json", generate_rsi(ticker))
    write_json(ticker, "insight.json", generate_insight(ticker))


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "MPC")
