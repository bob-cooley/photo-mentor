"""Generate a short, grounded "why did the stock move" explanation using
Claude, and write public/data/<TICKER>/insight.json.

This is the "Why MPC Moved" module the build spec always described as a
future AI reasoning layer. It's intentionally the simplest version that
could work: one API call, fed only the data this pipeline already
fetched (price move, energy indicators, recent filings) — no separate
extraction stage, since the inputs are already small structured JSON,
not large unstructured text that would need summarizing first.

Requires env var ANTHROPIC_API_KEY. Runs only once per calendar hour
even though the rest of the pipeline runs every 5 minutes during
market hours — the narrative doesn't need to update that often, and
Claude API calls cost money unlike every other data source here.

The hourly gate is based on the last successful call's timestamp
(persisted in ai_usage.json), not on wall-clock minute==0: the cron
fires at :00, but by the time this script actually runs — after
checkout, npm ci, pip install — the clock has usually already ticked
past :00. A minute==0 check would skip literally every run. Comparing
against the last call's hour is robust to that startup delay.

Cost control, in order of how much they actually protect you:
1. A hard monthly spend cap set in the Anthropic Console — see README.
   Nothing below is a substitute for that; it's the real backstop.
2. Structural: one non-agentic call per hour, never a loop.
3. This script's own circuit breaker: tracks cumulative estimated
   spend for the current calendar month (persisted as a small git-
   committed state file, see load_usage_summary/write_usage_state) and
   refuses to call the API once AI_MONTHLY_BUDGET_USD is reached,
   writing a "paused" state instead of calling anyway.
"""
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path

from common import OUTPUT_ROOT, get_required_env, load_stock_config, post, utc_now_iso, write_json

MODEL = "claude-haiku-4-5"
MAX_OUTPUT_TOKENS = 500
INPUT_PRICE_PER_MTOK = 1.00
OUTPUT_PRICE_PER_MTOK = 5.00
DEFAULT_MONTHLY_BUDGET_USD = 3.00

# Cross-run persistence for the usage/rate-limit state — see
# load_usage_summary()'s docstring for why this is a plain git-committed
# file rather than reading it back from the live site.
STATE_DIR = Path(__file__).resolve().parent / "state"

SYSTEM_PROMPT = """You help a non-trader understand why a stock they hold moved, in plain \
language. You are given the day's price move, recent price history, energy/refinery \
indicators (if the company is an oil refiner), and recent real-world news — both company-\
specific stories and broader market/sector stories (oil prices, geopolitical events, industry \
trends) that can move the stock without ever naming the company — nothing else.

Rules:
- Write 3-5 sentences as two short paragraphs, separated by a blank line. First paragraph: \
what the price did and the most concrete, real-world reason from the data — a specific news \
event, price move, or indicator, not a vague gesture at "market conditions". Second paragraph: \
one more layer of real context if the data supports it — a contributing factor, or the \
broader trend this fits into. Only write the second paragraph if you have something real to \
add; don't pad it out.
- Plain English, no unexplained jargon — if a term like "crack spread" or "basis points" is \
genuinely necessary, explain it in a few plain words right there rather than using it bare.
- Ground every claim in the data you were given. If the data doesn't clearly explain the \
move, say so plainly in the first paragraph ("Nothing in the data here explains today's move \
clearly") rather than inventing a reason.
- Never recommend buying, selling, or holding. Never say "should". You are explaining, not \
advising. A separate module already shows analyst consensus — do not duplicate or reference it.
- Do not mention that you are an AI or that this is a generated summary."""


def estimate_cost_usd(input_tokens: int, output_tokens: int) -> float:
    return (input_tokens / 1_000_000) * INPUT_PRICE_PER_MTOK + (output_tokens / 1_000_000) * OUTPUT_PRICE_PER_MTOK


def usage_state_path(ticker: str) -> Path:
    return STATE_DIR / f"ai_usage_{ticker}.json"


def load_usage_summary(ticker: str) -> dict:
    """Cross-run persistence with no database. Two earlier approaches
    both failed for real, not hypothetical, reasons: reading it back
    from the live site required logging in over HTTP, and Cloudflare's
    Bot Fight Mode blocks GitHub Actions' runner IPs with a JavaScript
    challenge ("Just a moment...") that no header spoofing can pass
    (confirmed directly — 3 separate deploys inside the same UTC hour
    each made a real Claude call before this was caught). A direct-to-
    origin bypass (skipping Cloudflare via Pair's own server hostname)
    doesn't reach the real site either — Pair's plain-HTTP vhost for
    this account serves a generic parking page, not bobboTrade's
    content, even with the correct Host header.

    So: no network call at all. This data (call count, token counts,
    estimated cost) isn't sensitive — unlike the portfolio share count,
    which deliberately stays off git entirely — so it's tracked as an
    ordinary small file in this same repo checkout, committed back to
    git at the end of a run that makes a real API call (see the
    "Commit AI usage state" step in deploy.yml). Reading it is just a
    local file read, no different from read_local_json() below."""
    current_month = datetime.now(timezone.utc).strftime("%Y-%m")
    path = usage_state_path(ticker)
    data = {}
    if path.exists():
        try:
            data = json.loads(path.read_text())
        except (json.JSONDecodeError, OSError) as exc:
            print(f"[bobboTrade] Failed to read AI usage state ({path}), falling back to zero: {exc}", file=sys.stderr)
            data = {}

    if data.get("month") != current_month:
        return {
            "month": current_month,
            "callsThisMonth": 0,
            "inputTokens": 0,
            "outputTokens": 0,
            "estimatedCostUsd": 0.0,
            "lastCallHour": None,
        }
    return {
        "month": current_month,
        "callsThisMonth": data.get("callsThisMonth", 0),
        "inputTokens": data.get("inputTokens", 0),
        "outputTokens": data.get("outputTokens", 0),
        "estimatedCostUsd": data.get("estimatedCostUsd", 0.0),
        "lastCallHour": data.get("lastCallHour"),
    }


def write_usage_state(ticker: str, usage: dict) -> None:
    STATE_DIR.mkdir(parents=True, exist_ok=True)
    usage_state_path(ticker).write_text(json.dumps(usage, indent=2))


def build_user_message(ticker: str, config: dict, market: dict | None, energy: dict | None, news: dict | None) -> str:
    lines = [f"Ticker: {ticker} ({config.get('name', ticker)})"]

    if market:
        q = market.get("quote", {})
        lines.append(f"Today's change: {q.get('changePercent')}% (price ${q.get('price')})")
        lines.append(f"2-week change: {market.get('twoWeekChangePercent')}%")
        history = market.get("history", [])[-6:]
        if history:
            closes = ", ".join(f"{p['time']}: ${p['close']}" for p in history)
            lines.append(f"Last few days' closing prices: {closes}")
    else:
        lines.append("Price data: unavailable")

    if energy and energy.get("indicators"):
        parts = [f"{i['label']}: {i['value']} {i['unit']}" for i in energy["indicators"] if i.get("value") is not None]
        lines.append(f"Current energy/refining indicators: {'; '.join(parts)}" if parts else "Energy indicators: unavailable")
    else:
        lines.append("Energy indicators: unavailable")

    if news and news.get("articles"):
        # news.json now mixes ticker-specific stories with broader
        # market/sector news (see news.py) and is already sorted with
        # direct company mentions bumped to the top within the last
        # week — take more than just the top couple, and include each
        # article's summary/lede, not just the headline, so the model
        # has real substance to ground a longer explanation in.
        recent = news["articles"][:5]
        parts = []
        for a in recent:
            snippet = f"{a['publishedAt'][:10]} ({a['source']}): {a['headline']}"
            if a.get("summary"):
                snippet += f" — {a['summary']}"
            parts.append(snippet)
        lines.append("Recent news (company-specific and broader market/sector):\n" + "\n".join(f"- {p}" for p in parts))
    else:
        lines.append("Recent news: none available")

    return "\n".join(lines)


def call_claude(api_key: str, user_message: str) -> tuple[str, int, int]:
    resp = post(
        "https://api.anthropic.com/v1/messages",
        headers={
            "x-api-key": api_key,
            "anthropic-version": "2023-06-01",
            "content-type": "application/json",
        },
        json={
            "model": MODEL,
            "max_tokens": MAX_OUTPUT_TOKENS,
            "system": SYSTEM_PROMPT,
            "messages": [{"role": "user", "content": user_message}],
        },
    )
    body = resp.json()
    text = "".join(block.get("text", "") for block in body.get("content", []) if block.get("type") == "text")
    usage = body.get("usage", {})
    return text.strip(), usage.get("input_tokens", 0), usage.get("output_tokens", 0)


def read_local_json(ticker: str, filename: str) -> dict | None:
    """Read output another module already wrote earlier in this same
    run_all.py pass — ai_insight runs last in MODULES specifically so
    market/energy/news are already on disk. Re-fetching them here would
    silently double this run's calls to every other provider."""
    path = OUTPUT_ROOT / ticker / filename
    if not path.exists():
        return None
    try:
        return json.loads(path.read_text())
    except (json.JSONDecodeError, OSError):
        return None


def fetch_insight(ticker: str) -> dict:
    api_key = get_required_env("ANTHROPIC_API_KEY")
    config = load_stock_config(ticker)

    usage = load_usage_summary(ticker)
    current_hour = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H")
    if usage.get("lastCallHour") == current_hour:
        print(f"[bobboTrade] Skipping AI insight for {ticker} — already called this hour ({current_hour}).")
        raise SystemExit(78)

    market_data = read_local_json(ticker, "market.json")
    energy_data = read_local_json(ticker, "energy.json")
    news_data = read_local_json(ticker, "news.json")

    if usage["estimatedCostUsd"] >= DEFAULT_MONTHLY_BUDGET_USD:
        print(
            f"[bobboTrade] AI insight paused for {ticker}: this month's spend "
            f"(${usage['estimatedCostUsd']:.4f}) has reached the ${DEFAULT_MONTHLY_BUDGET_USD:.2f} budget."
        )
        return {
            "ticker": ticker,
            "fetchedAt": utc_now_iso(),
            "text": None,
            "status": "paused_budget",
            "usage": usage,
        }

    user_message = build_user_message(ticker, config, market_data, energy_data, news_data)
    text, input_tokens, output_tokens = call_claude(api_key, user_message)

    usage["callsThisMonth"] += 1
    usage["inputTokens"] += input_tokens
    usage["outputTokens"] += output_tokens
    usage["estimatedCostUsd"] = round(usage["estimatedCostUsd"] + estimate_cost_usd(input_tokens, output_tokens), 6)
    usage["lastCallHour"] = current_hour
    write_json(ticker, "ai_usage.json", usage)
    write_usage_state(ticker, usage)

    return {
        "ticker": ticker,
        "fetchedAt": utc_now_iso(),
        "text": text,
        "status": "ok",
        "usage": usage,
    }


def main(ticker: str) -> None:
    payload = fetch_insight(ticker)
    write_json(ticker, "insight.json", payload)


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "MPC")
