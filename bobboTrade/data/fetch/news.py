"""Build the news module from SEC EDGAR filings — primary-source only.
No wire-service aggregation (Reuters/Bloomberg/AP) — none of those offer
a free public API, so v1 deliberately limits itself to primary-source
material. See the build spec's news requirements and the "primary
sources only" call.

An Investor Relations RSS feed was the original second source, but
MPC's IR site (and most companies' IR sites, generally) sits behind a
Cloudflare bot challenge that returns a JS interstitial to any scripted
client, key or no key — confirmed by testing multiple plausible feed
paths, all either 403 or the challenge page itself. Dropped rather than
left in as a silently-failing dead call.

No API key required. SEC requests a descriptive User-Agent identifying
the requester (see https://www.sec.gov/os/webmaster-faq#developers).
"""
import sys

from common import get, load_stock_config, utc_now_iso, write_json

SEC_USER_AGENT = "bobboTrade dashboard (bob@bobcooleyphoto.com)"

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
