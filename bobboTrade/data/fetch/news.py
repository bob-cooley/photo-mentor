"""Build the news module from real aggregated news (Finnhub company-news,
filtered to Tier-1 sources) plus SEC EDGAR filings as a factual
regulatory-event supplement.

v1 of this module was SEC-only: an Investor Relations RSS feed was the
original second source, but MPC's IR site (and most companies' IR
sites, generally) sits behind a Cloudflare bot challenge that returns a
JS interstitial to any scripted client, key or no key. That got dropped,
and at the time no free wire-service aggregation API seemed to exist —
but that survey missed Finnhub's own `/company-news` endpoint, which is
free-tier and already in use here for analyst.py.

The build spec's News Requirements are a hard, explicit rule: "ONLY use
highly reliable sources," naming Reuters/Bloomberg/Financial
Times/Wall Street Journal/Associated Press as Tier 1 and explicitly
excluding Yahoo Finance, Motley Fool, generic aggregators, and clickbait
financial sites. Finnhub's raw feed mixes both — real wire content next
to exactly the sources the spec excludes — so every Finnhub item is
filtered through `is_tier_1_source()` before it's kept. This is an
allowlist, not just a blocklist of the four named examples: an
unrecognized source (Benzinga, Zacks, a press-release wire, etc.) is
dropped by default rather than assumed acceptable. In practice this
means many days will have zero or few Finnhub items and the feed leans
on the SEC-filings supplement — that's the correct tradeoff per the
spec's own priority order (reliability over completeness), not a bug.

No API key required for SEC. SEC requests a descriptive User-Agent
identifying the requester (see
https://www.sec.gov/os/webmaster-faq#developers). Finnhub news requires
FINNHUB_API_KEY (already configured for analyst.py); if missing, this
module falls back to SEC filings only rather than failing outright.
"""
from __future__ import annotations

import os
import re
import sys
from datetime import datetime, timedelta, timezone

import requests

from common import get, load_stock_config, utc_now_iso, write_json

SEC_USER_AGENT = "bobboTrade dashboard (bob@bobcooleyphoto.com)"
FINNHUB_BASE = "https://finnhub.io/api/v1"
NEWS_LOOKBACK_DAYS = 10

# Yahoo Finance genuinely syndicates real Reuters/AP wire content — Finnhub's
# `source` field just says "Yahoo" regardless, since that's the hosting
# domain, not who actually wrote it. Confirmed empirically (2026-08-26)
# against a live Yahoo Finance article: the page embeds
# `"yContentPartner":"Reuters"` in its hydration JSON, and the article body
# opens with the classic wire dateline convention ("NEW YORK, Aug 25
# (Reuters) - ..."). This only ever reads the page to answer "who wrote
# this" — the matched text is never stored or displayed, only the
# resulting source label and the original headline/summary Finnhub already
# gave us.
WIRE_PROBE_USER_AGENT = (
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36"
)
WIRE_PROBE_TIMEOUT = 8
MAX_WIRE_PROBES_PER_RUN = 6
CONTENT_PARTNER_PATTERN = re.compile(r'"yContentPartner"\s*:\s*"([^"]+)"')
WIRE_LEDE_PATTERN = re.compile(r"\((Reuters|AP|Associated Press)\)\s*[-–]")

# Spec's Tier-1 preferred sources (News Requirements section), normalized
# to lowercase for matching against whatever casing/punctuation Finnhub
# happens to return in its "source" field.
TIER_1_SOURCES = {
    "reuters",
    "bloomberg",
    "financial times",
    "wall street journal",
    "the wall street journal",
    "wsj",
    "associated press",
    "ap",
    "ap news",
}
# Long, distinctive names are also matched as a prefix (e.g. Finnhub
# returning "Reuters.com" or "Bloomberg News") — short acronyms like
# "wsj"/"ap" are exact-match only, since prefix-matching those would
# false-positive on unrelated source names.
TIER_1_PREFIXES = ("reuters", "bloomberg", "financial times", "wall street journal", "associated press")


def is_tier_1_source(source: str) -> bool:
    normalized = source.strip().lower()
    if normalized in TIER_1_SOURCES:
        return True
    return any(normalized.startswith(prefix) for prefix in TIER_1_PREFIXES)

MATERIAL_FORMS = {"8-K", "10-Q", "10-K"}
MAX_ARTICLES = 10

# Standard SEC Form 8-K item taxonomy (17 CFR 249.308), in plain
# language rather than the official legal phrasing — the target reader
# is a hobbyist following the stock, not a securities lawyer. "9.01"
# (Financial Statements and Exhibits) is deliberately excluded: it's
# boilerplate that tags along on nearly every 8-K and never carries
# meaning on its own.
PLAIN_ITEM_DESCRIPTIONS = {
    "1.01": "Signed a major new business agreement",
    "1.02": "Ended a major business agreement",
    "2.01": "Completed buying or selling a major asset",
    "2.02": "Announced quarterly earnings",
    "2.03": "Took on new debt",
    "2.05": "Announced costs from closing part of the business",
    "2.06": "Wrote down the value of some assets",
    "3.01": "Received a stock exchange compliance notice",
    "3.02": "Sold stock in a private transaction",
    "3.03": "Changed shareholder rights",
    "4.01": "Changed accounting firms",
    "4.02": "Corrected an earlier financial report",
    "5.01": "Changed who controls the company",
    "5.02": "Changed company leadership",
    "5.03": "Updated company bylaws",
    "5.07": "Held a shareholder vote",
    "5.08": "Received shareholder board nominations",
    "7.01": "Made a public announcement to investors",
    "8.01": "Announced a company update",
}
BOILERPLATE_ITEMS = {"9.01"}


def describe_items(items_field: str) -> list[str]:
    codes = [c.strip() for c in items_field.split(",") if c.strip()]
    meaningful = [c for c in codes if c not in BOILERPLATE_ITEMS] or codes
    return [PLAIN_ITEM_DESCRIPTIONS.get(c, f"Filed an update (Item {c})") for c in meaningful]


def headline_and_summary(company: str, form: str, items_field: str, report_date: str) -> tuple[str, str]:
    if form == "8-K":
        descriptions = describe_items(items_field)
        if descriptions:
            headline = f"{company}: {descriptions[0]}"
            # A single item would just repeat the headline verbatim as
            # the summary — better to show nothing than restate it.
            summary = "Also: " + "; ".join(descriptions[1:]) if len(descriptions) > 1 else ""
            return headline, summary
        return f"{company}: Filed an update with regulators", "Details not further specified."
    if form == "10-Q":
        period = f" for the period ended {report_date}" if report_date else ""
        return f"{company}: Quarterly Report", f"Quarterly financial report{period}."
    if form == "10-K":
        period = f" for the fiscal year ended {report_date}" if report_date else ""
        return f"{company}: Annual Report", f"Full-year financial report{period}."
    return f"{company}: Filed an update with regulators", form


def detect_wire_partner(url: str) -> tuple[str | None, str]:
    """Best-effort, single-attempt (no retries — this is a nice-to-have
    probe, not a critical API call, and retrying a slow/bot-defensive
    site 3x would add real latency to a job that runs every 5 minutes
    during market hours). Returns (partner_name_or_None, resolved_url) —
    resolved_url follows Finnhub's redirect link to the real article URL
    so the News card links straight to the source, not through Finnhub."""
    try:
        resp = requests.get(url, headers={"User-Agent": WIRE_PROBE_USER_AGENT}, timeout=WIRE_PROBE_TIMEOUT)
        if resp.status_code != 200:
            return None, url
        html = resp.text
        resolved_url = resp.url
    except requests.exceptions.RequestException:
        return None, url

    match = CONTENT_PARTNER_PATTERN.search(html)
    if match and is_tier_1_source(match.group(1)):
        return match.group(1), resolved_url

    match = WIRE_LEDE_PATTERN.search(html)
    if match:
        return match.group(1), resolved_url

    return None, url


def fetch_company_news(ticker: str, api_key: str) -> list[dict]:
    today = datetime.now(timezone.utc).date()
    from_date = today - timedelta(days=NEWS_LOOKBACK_DAYS)
    items = get(
        f"{FINNHUB_BASE}/company-news",
        params={"symbol": ticker, "from": from_date.isoformat(), "to": today.isoformat(), "token": api_key},
    ).json()

    articles = []
    wire_probes_used = 0
    reclassified = 0
    for item in items:
        headline = item.get("headline")
        published = item.get("datetime")
        source = item.get("source") or ""
        url = item.get("url", "")
        if not headline or not published:
            continue

        if is_tier_1_source(source):
            pass  # already a recognized Tier-1 source, keep as-is
        elif source.strip().lower() == "yahoo" and url and wire_probes_used < MAX_WIRE_PROBES_PER_RUN:
            wire_probes_used += 1
            partner, url = detect_wire_partner(url)
            if partner is None:
                continue
            source = partner
            reclassified += 1
        else:
            continue

        articles.append(
            {
                "id": f"finnhub-{item.get('id', published)}",
                "headline": headline,
                "summary": (item.get("summary") or "")[:280],
                "source": source,
                "url": url,
                "publishedAt": datetime.fromtimestamp(published, tz=timezone.utc).isoformat(),
                "relevance": 1.0,
            }
        )
        if len(articles) >= MAX_ARTICLES:
            break

    raw_sources = sorted({item.get("source") or "?" for item in items})
    print(
        f"[bobboTrade] Finnhub company-news for {ticker}: {len(items)} raw articles, "
        f"{len(articles)} passed ({reclassified} reclassified via byline detection out of "
        f"{wire_probes_used} probes). Raw sources seen: {raw_sources}"
    )
    return articles


def fetch_sec_filings(cik: str) -> list[dict]:
    resp = get(
        f"https://data.sec.gov/submissions/CIK{cik}.json",
        headers={"User-Agent": SEC_USER_AGENT},
    ).json()
    recent = resp.get("filings", {}).get("recent", {})
    forms = recent.get("form", [])
    dates = recent.get("filingDate", [])
    report_dates = recent.get("reportDate", [])
    accession_numbers = recent.get("accessionNumber", [])
    primary_docs = recent.get("primaryDocument", [])
    items_fields = recent.get("items", [])
    company_name = resp.get("name", "")

    articles = []
    for i, form in enumerate(forms):
        if form not in MATERIAL_FORMS:
            continue
        accession = accession_numbers[i].replace("-", "")
        doc = primary_docs[i] if i < len(primary_docs) else ""
        url = f"https://www.sec.gov/Archives/edgar/data/{int(cik)}/{accession}/{doc}"
        items_field = items_fields[i] if i < len(items_fields) else ""
        report_date = report_dates[i] if i < len(report_dates) else ""
        headline, summary = headline_and_summary(company_name, form, items_field, report_date)
        articles.append(
            {
                "id": f"sec-{accession_numbers[i]}",
                "headline": headline,
                "summary": summary,
                "source": "SEC EDGAR",
                "url": url,
                "publishedAt": dates[i],
                "relevance": 0.9 if form == "8-K" else 0.7,
            }
        )
        if len(articles) >= MAX_ARTICLES:
            break
    return articles


def fetch_news(ticker: str) -> dict:
    config = load_stock_config(ticker)

    articles: list[dict] = []

    finnhub_key = os.environ.get("FINNHUB_API_KEY")
    if finnhub_key:
        try:
            articles += fetch_company_news(ticker, finnhub_key)
        except Exception as exc:  # noqa: BLE001 — one source failing shouldn't kill the module
            print(f"[bobboTrade] Finnhub company news fetch failed for {ticker}: {exc}", file=sys.stderr)
    else:
        print(f"[bobboTrade] FINNHUB_API_KEY not set — news falling back to SEC filings only.", file=sys.stderr)

    try:
        articles += fetch_sec_filings(config["cik"])
    except Exception as exc:  # noqa: BLE001 — one source failing shouldn't kill the module
        print(f"[bobboTrade] SEC EDGAR fetch failed for {ticker}: {exc}", file=sys.stderr)

    articles.sort(key=lambda a: a["publishedAt"], reverse=True)

    return {
        "ticker": ticker,
        "fetchedAt": utc_now_iso(),
        "articles": articles[:MAX_ARTICLES],
    }


def main(ticker: str) -> None:
    payload = fetch_news(ticker)
    write_json(ticker, "news.json", payload)


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "MPC")
