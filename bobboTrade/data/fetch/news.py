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

CNBC was added to the allowlist on 2026-08-26 despite not being named
in the spec's Tier-1 list: it's staff-reported network journalism (not
syndicated/aggregated content), has no subscription-newsletter funnel
biasing article framing the way Motley Fool does, and is rated by
media-bias trackers as factually solid on straight news specifically
(its TV commentary segments lean hype/entertainment, but that's not
what a news API surfaces). It was also, empirically, one of only four
sources Finnhub's free tier ever actually returns for MPC — the
others (Benzinga, SeekingAlpha, Yahoo) stayed excluded.

MarketWatch was added the same day on the same reasoning: Dow Jones-
owned (same parent as WSJ), staff-reported, no newsletter-funnel bias.
Benzinga and SeekingAlpha were deliberately left out even though they
show up constantly in the raw feed — Benzinga leans high-volume
press-release/reactive-headline wire rather than a newsroom, and
SeekingAlpha is contributor-opinion, not staff journalism; both are
closer in kind to what the spec's "clickbait"/"generic aggregator"
language is aimed at than to CNBC or MarketWatch. Both new sources go
through the exact same `detect_wire_partner()` check as everything
else (see below) — if a MarketWatch- or CNBC-hosted piece turns out to
actually be a Reuters/AP wire dispatch, the wire service is credited
as the source, not the hosting outlet.

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
import trafilatura

from common import get, load_stock_config, utc_now_iso, write_json

SEC_USER_AGENT = "bobboTrade dashboard (bob@bobcooleyphoto.com)"
FINNHUB_BASE = "https://finnhub.io/api/v1"
NEWS_LOOKBACK_DAYS = 10

# Any outlet — Yahoo especially, but CNBC and others too — can run a wire
# dispatch under its own domain. Finnhub's `source` field just says which
# domain hosted the article, not who actually wrote it. So every candidate
# article (Yahoo, or an already-Tier-1 source like CNBC) gets this check:
# if the page shows evidence of being a Reuters/AP/etc. wire piece, the
# wire service is the real primary source and gets used as the label
# instead. Two signals, confirmed empirically (2026-08-26) against real
# pages: Yahoo embeds `"yContentPartner":"Reuters"` in its hydration JSON;
# wire dispatches everywhere else still carry the classic dateline
# convention in the body text ("NEW YORK, Aug 25 (Reuters) - ..."). No
# match found (e.g. a real CNBC reporter byline, confirmed via CNBC's own
# author metadata) means the outlet itself is the primary source — keep
# its own label. This only ever reads the page to answer "who wrote
# this" — the matched text is never stored or displayed, only the
# resulting source label and the original headline/summary Finnhub
# already gave us.
WIRE_PROBE_USER_AGENT = (
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36"
)
WIRE_PROBE_TIMEOUT = 8
MAX_WIRE_PROBES_PER_RUN = 10
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
    "cnbc",
    "marketwatch",
}
# Long, distinctive names are also matched as a prefix (e.g. Finnhub
# returning "Reuters.com" or "Bloomberg News") — short acronyms like
# "wsj"/"ap" are exact-match only, since prefix-matching those would
# false-positive on unrelated source names.
TIER_1_PREFIXES = (
    "reuters",
    "bloomberg",
    "financial times",
    "wall street journal",
    "associated press",
    "cnbc",
    "marketwatch",
)


def is_tier_1_source(source: str) -> bool:
    normalized = source.strip().lower()
    if normalized in TIER_1_SOURCES:
        return True
    return any(normalized.startswith(prefix) for prefix in TIER_1_PREFIXES)

MATERIAL_FORMS = {"8-K", "10-Q", "10-K"}
MAX_ARTICLES = 10
# SEC filings are a supplement, not the main feed — capped much lower than
# MAX_ARTICLES so the card doesn't pad itself out with filings from months
# or years ago just because Tier-1 news coverage is thin that day. A
# shorter, genuinely-recent list reads better than a long stale one.
SEC_MAX_ARTICLES = 3

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


FULL_TEXT_TIMEOUT = 10
MAX_FULL_TEXT_FETCHES_PER_RUN = 8


def fetch_full_text(url: str) -> str | None:
    """Best-effort, single-attempt: fetch the article and extract clean
    body text (no ads/nav/boilerplate — trafilatura strips all of that),
    so the News card can show the story itself instead of just a link
    out. Only ever called for articles that already passed the Tier-1
    filter — this doesn't second-guess sourcing, just fetches the body
    once the source is already trusted."""
    try:
        resp = requests.get(url, headers={"User-Agent": WIRE_PROBE_USER_AGENT}, timeout=FULL_TEXT_TIMEOUT)
        if resp.status_code != 200:
            return None
        text = trafilatura.extract(resp.text, include_comments=False, include_tables=False)
        return text.strip() if text else None
    except requests.exceptions.RequestException:
        return None


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
    full_text_fetches = 0
    full_text_hits = 0
    for item in items:
        headline = item.get("headline")
        published = item.get("datetime")
        source = item.get("source") or ""
        url = item.get("url", "")
        if not headline or not published:
            continue

        already_tier1 = is_tier_1_source(source)
        if not already_tier1 and source.strip().lower() != "yahoo":
            continue  # not a Tier-1 outlet and not a candidate for wire-syndication detection

        if url and wire_probes_used < MAX_WIRE_PROBES_PER_RUN:
            wire_probes_used += 1
            partner, resolved_url = detect_wire_partner(url)
            if partner is not None:
                if partner.strip().lower() != source.strip().lower():
                    reclassified += 1
                source = partner
                url = resolved_url
            elif not already_tier1:
                continue  # Yahoo item with no detected primary source — drop, don't guess
            # else: already Tier-1 (e.g. CNBC) with no wire byline found — the outlet
            # itself is the primary source, keep its own label.
        elif not already_tier1:
            continue  # Yahoo item but probe budget exhausted — can't verify, don't guess

        full_text = None
        if url and full_text_fetches < MAX_FULL_TEXT_FETCHES_PER_RUN:
            full_text_fetches += 1
            full_text = fetch_full_text(url)
            if full_text:
                full_text_hits += 1

        articles.append(
            {
                "id": f"finnhub-{item.get('id', published)}",
                "headline": headline,
                "summary": (item.get("summary") or "")[:280],
                "source": source,
                "url": url,
                "fullText": full_text,
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
        f"{wire_probes_used} probes, {full_text_hits}/{full_text_fetches} full-text extractions "
        f"succeeded). Raw sources seen: {raw_sources}"
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
                "fullText": None,
                "publishedAt": dates[i],
                "relevance": 0.9 if form == "8-K" else 0.7,
            }
        )
        if len(articles) >= SEC_MAX_ARTICLES:
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
