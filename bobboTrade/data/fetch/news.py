"""Build the news module from primary sources only: SEC EDGAR filings and
the company's own Investor Relations RSS feed. No wire-service
aggregation (Reuters/Bloomberg/AP) — none of those offer a free public
API, so v1 deliberately limits itself to primary-source material. See
the build spec's news requirements and the "primary sources only" call.

No API key required. SEC requests a descriptive User-Agent identifying
the requester (see https://www.sec.gov/os/webmaster-faq#developers).
"""
import re
import sys
from html import unescape

import feedparser

from common import get, load_stock_config, utc_now_iso, write_json

SEC_USER_AGENT = "bobboTrade dashboard (bob@bobcooleyphoto.com)"

MATERIAL_FORMS = {"8-K", "10-Q", "10-K"}
MAX_ARTICLES = 10


def strip_html(text: str) -> str:
    return unescape(re.sub(r"<[^>]+>", "", text or "")).strip()


def first_sentence(text: str, max_len: int = 220) -> str:
    text = strip_html(text)
    match = re.search(r"(.+?[.!?])(\s|$)", text)
    sentence = match.group(1) if match else text
    return sentence[:max_len].rstrip()


def fetch_sec_filings(cik: str) -> list[dict]:
    resp = get(
        f"https://data.sec.gov/submissions/CIK{cik}.json",
        headers={"User-Agent": SEC_USER_AGENT},
    ).json()
    recent = resp.get("filings", {}).get("recent", {})
    forms = recent.get("form", [])
    dates = recent.get("filingDate", [])
    accession_numbers = recent.get("accessionNumber", [])
    primary_docs = recent.get("primaryDocument", [])
    descriptions = recent.get("primaryDocDescription", [])
    company_name = resp.get("name", "")

    articles = []
    for i, form in enumerate(forms):
        if form not in MATERIAL_FORMS:
            continue
        accession = accession_numbers[i].replace("-", "")
        doc = primary_docs[i] if i < len(primary_docs) else ""
        url = f"https://www.sec.gov/Archives/edgar/data/{int(cik)}/{accession}/{doc}"
        description = descriptions[i] if i < len(descriptions) and descriptions[i] else form
        articles.append(
            {
                "id": f"sec-{accession_numbers[i]}",
                "headline": f"{company_name}: {form} filed",
                "summary": description,
                "source": "SEC EDGAR",
                "url": url,
                "publishedAt": dates[i],
                "relevance": 0.9 if form == "8-K" else 0.7,
            }
        )
        if len(articles) >= MAX_ARTICLES:
            break
    return articles


def fetch_ir_feed(feed_url: str) -> list[dict]:
    parsed = feedparser.parse(feed_url)
    articles = []
    for entry in parsed.entries[:MAX_ARTICLES]:
        published = entry.get("published", entry.get("updated", ""))
        articles.append(
            {
                "id": f"ir-{entry.get('id', entry.get('link', ''))}",
                "headline": strip_html(entry.get("title", "")),
                "summary": first_sentence(entry.get("summary", "")),
                "source": "Investor Relations",
                "url": entry.get("link", ""),
                "publishedAt": published,
                "relevance": 0.85,
            }
        )
    return articles


def fetch_news(ticker: str) -> dict:
    config = load_stock_config(ticker)

    articles: list[dict] = []
    try:
        articles += fetch_sec_filings(config["cik"])
    except Exception as exc:  # noqa: BLE001 — one source failing shouldn't kill the module
        print(f"[bobboTrade] SEC EDGAR fetch failed for {ticker}: {exc}", file=sys.stderr)

    try:
        articles += fetch_ir_feed(config["irFeedUrl"])
    except Exception as exc:  # noqa: BLE001
        print(f"[bobboTrade] IR feed fetch failed for {ticker}: {exc}", file=sys.stderr)

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
