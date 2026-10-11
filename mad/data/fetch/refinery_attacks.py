"""Reported physical attacks on oil refineries worldwide.

Writes public/data/global/refinery_attacks.json: a running list of events
with the nations involved (attacker > attacked), the date, the facility,
and linked sources. Global, not per ticker: an attack on a Russian
refinery is relevant to MPC whichever ticker the page is showing.

Deliberately no volume/loss column. Outlets report losses in mixed units
(barrels per day, percent of national capacity, tonnes), usually from
anonymous industry sources, and the figure for any single strike is
rarely published. A column that is mostly blank or mixes units would
mislead more than it informs.

Pipeline
1. Seed: data/fetch/seed/refinery_attacks_seed.json. Hand-verified
   entries, never edited by this script. Anchors the history.
2. Discovery (at most once per REFRESH_AFTER_HOURS, only when
   ANTHROPIC_API_KEY is set): BBC and Al Jazeera RSS plus a GDELT
   keyword query. Candidates are pre-filtered by keyword, fetched as
   full text, and passed to Claude for structured extraction.
3. Extraction guard: every extracted event must carry a verbatim
   evidence quote that actually appears in the article text. An event
   whose quote cannot be found is dropped, so the model cannot add
   facts the article does not contain. Fields the article does not
   state are left null, never inferred.
4. Merge: events describing the same strike (same attacked nation,
   dates within a day, overlapping facility name) are combined and
   their sources unioned.
5. State: the accumulated list lives in state/refinery_attacks.json
   (git-committed by the workflow). This matters: the FTP deploy
   deletes remote files that are missing from the local build, so
   the output JSON is rewritten from state on EVERY run, including
   runs where discovery is skipped or fails.
"""
from __future__ import annotations

import json
import math
import os
import re
import sys
import time
from datetime import datetime, timedelta, timezone
from email.utils import parsedate_to_datetime
from pathlib import Path
from urllib.parse import urlparse
from xml.etree import ElementTree

import requests

from common import post, utc_now_iso, write_json

HERE = Path(__file__).resolve().parent
STATE_PATH = HERE / "state" / "refinery_attacks.json"
SEED_PATH = HERE / "seed" / "refinery_attacks_seed.json"

REFRESH_AFTER_HOURS = 20
FIRST_RUN_BACKFILL_DAYS = 90
RECURRING_LOOKBACK_DAYS = 3
MAX_CANDIDATES_PER_RUN = 25
MAX_CANDIDATES_FIRST_RUN = 60
PROCESSED_URL_RETENTION_DAYS = 120
ARTICLE_TEXT_CHARS = 6000

MODEL = "claude-haiku-4-5"
MAX_OUTPUT_TOKENS = 700
INPUT_PRICE_PER_MTOK = 1.00
OUTPUT_PRICE_PER_MTOK = 5.00
MONTHLY_BUDGET_USD = 2.00

RSS_FEEDS = [
    "https://feeds.bbci.co.uk/news/world/rss.xml",
    "https://feeds.bbci.co.uk/news/world/middle_east/rss.xml",
    "https://feeds.bbci.co.uk/news/business/rss.xml",
    "https://www.aljazeera.com/xml/rss/all.xml",
]
MAX_CONSECUTIVE_GDELT_FAILURES = 2
GDELT_PAUSE_SECONDS = 10  # GDELT throttles bursts well beyond its stated one request per 5 seconds
GDELT_URL = "https://api.gdeltproject.org/api/v2/doc/doc"
GDELT_QUERY = (
    "(refinery OR refineries) "
    "(drone OR drones OR strike OR strikes OR attack OR attacked OR missile OR explosion OR shelling) "
    "sourcelang:english"
)

FEED_USER_AGENT = (
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36"
)

# Outlets accepted for auto-discovered events, keyed by registrable domain.
OUTLETS = {
    "bbc.co.uk": "BBC",
    "bbc.com": "BBC",
    "aljazeera.com": "Al Jazeera",
    "reuters.com": "Reuters",
    "apnews.com": "AP",
    "bloomberg.com": "Bloomberg",
    "cnn.com": "CNN",
    "cnbc.com": "CNBC",
    "theguardian.com": "The Guardian",
    "dw.com": "DW",
    "france24.com": "France 24",
    "euronews.com": "Euronews",
    "aa.com.tr": "Anadolu",
    "kyivindependent.com": "Kyiv Independent",
    "pravda.com.ua": "Ukrainska Pravda",
    "timesofisrael.com": "Times of Israel",
    "pbs.org": "PBS NewsHour",
    "npr.org": "NPR",
    "ft.com": "Financial Times",
    "wsj.com": "Wall Street Journal",
    "nytimes.com": "New York Times",
    "washingtonpost.com": "Washington Post",
    "rferl.org": "RFE/RL",
    "gulfnews.com": "Gulf News",
    "kuna.net.kw": "KUNA",
}
WIRE_NAMES = {"Reuters", "AP", "Bloomberg"}

REFINERY_RE = re.compile(r"\brefiner(?:y|ies)\b", re.I)
ATTACK_RE = re.compile(
    r"\b(drones?|strikes?|struck|attacks?|attacked|missiles?|explosions?|blasts?|shell(?:ed|ing)|"
    r"bomb(?:ed|ing)|sabotage|rockets?|hit|hits|fire|ablaze)\b",
    re.I,
)

NATION_ALIASES = {
    "russian federation": "Russia",
    "russian": "Russia",
    "ukrainian": "Ukraine",
    "iranian": "Iran",
    "israeli": "Israel",
    "us": "United States",
    "u.s.": "United States",
    "usa": "United States",
    "united states of america": "United States",
    "america": "United States",
    "uk": "United Kingdom",
    "uae": "United Arab Emirates",
    "saudi": "Saudi Arabia",
    "ksa": "Saudi Arabia",
}
ATTRIBUTIONS = {"claimed", "reported", "disputed", "unattributed"}

FACILITY_STOPWORDS = {
    "refinery", "refineries", "oil", "plant", "the", "of", "and", "complex", "petroleum",
    "company", "lukoil", "rosneft", "gazprom", "neft", "state", "owned", "oao", "pjsc",
    "jsc", "energy", "energies", "national", "group", "works", "unit", "units",
    "petrochemical", "processing", "refining", "facility",
}

SYSTEM_PROMPT = """You extract structured facts about physical attacks on OIL REFINERIES from a news article.

Return ONLY a JSON object, no prose, in this exact shape:
{"events": [{"facility": string, "attacked_nation": string, "attacker": string or null, "attribution": "claimed"|"reported"|"disputed"|"unattributed", "date": "YYYY-MM-DD" or null, "evidence": string}]}

Rules:
- Include only attacks that actually happened on an oil refinery (a plant that refines crude oil). Do NOT include oil depots, storage tanks, terminals, ports, pipelines, oil or gas fields, gas processing plants, LNG plants, power stations, or ships.
- Do NOT include threats, warnings, hypotheticals, drills, or accidents with no stated attack. If the article reports a fire with no stated cause, leave it out.
- If the article describes no qualifying attack, return {"events": []}.
- facility: the refinery's name or its location as the article gives it. attacked_nation: the country where the refinery is located.
- attacker: the nation or armed group the ARTICLE says carried out the attack. null if the article does not say. Never infer an attacker from who is at war with whom.
- attribution: "claimed" if the attacker (or its military) says it did it; "reported" if the article states the attacker as fact or cites officials or sources without a claim; "disputed" if the article says the attacker denies it or the attribution is contested; "unattributed" if no attacker is named.
- date: the date the attack happened, in YYYY-MM-DD. If the article gives only a weekday or "overnight", resolve it against the publication date provided. null if it cannot be determined.
- evidence: one short verbatim quote (at most 200 characters, copied exactly from the article text) that shows the attack on the refinery happened. If you cannot quote it exactly, omit the event.
- One event per refinery per attack. If one article covers several refineries, list each separately.
- Use only what the article states. Do not add outside knowledge."""


# ---------------------------------------------------------------- state

def load_json(path: Path) -> dict | None:
    if not path.exists():
        return None
    try:
        return json.loads(path.read_text())
    except (json.JSONDecodeError, OSError) as exc:
        print(f"[bobboTrade] Couldn't read {path.name}: {exc}", file=sys.stderr)
        return None


def load_seed() -> tuple[str, list[dict]]:
    seed = load_json(SEED_PATH) or {}
    return seed.get("historyStart", "2025-02-03"), list(seed.get("events", []))


def load_state() -> dict:
    state = load_json(STATE_PATH) or {}
    state.setdefault("events", [])
    state.setdefault("processedUrls", {})
    state.setdefault("usage", {})
    return state


def write_state(state: dict) -> None:
    STATE_PATH.parent.mkdir(parents=True, exist_ok=True)
    STATE_PATH.write_text(json.dumps(state, indent=2, ensure_ascii=False))


# ------------------------------------------------------------ normalizing

def normalize_nation(name: str | None) -> str | None:
    if not name:
        return None
    cleaned = re.sub(r"\s+", " ", name).strip().strip(".")
    if cleaned.lower().startswith("the "):
        cleaned = cleaned[4:]
    alias = NATION_ALIASES.get(cleaned.lower())
    return alias or cleaned


def facility_tokens(facility: str) -> set[str]:
    return {t for t in re.findall(r"[a-z]+", facility.lower()) if t not in FACILITY_STOPWORDS and len(t) > 2}


def slugify(text: str) -> str:
    return re.sub(r"[^a-z0-9]+", "-", text.lower()).strip("-")


def outlet_for(url: str) -> str | None:
    host = (urlparse(url).hostname or "").lower()
    for domain, name in OUTLETS.items():
        if host == domain or host.endswith("." + domain):
            return name
    return None


def source_rank(source: dict) -> int:
    host = (urlparse(source.get("url", "")).hostname or "").lower()
    if host.endswith(".gov") or host.endswith(".mil") or host.endswith("un.org") or host.endswith("iea.org"):
        return 0
    if source.get("name") in WIRE_NAMES:
        return 1
    return 2


def same_event(a: dict, b: dict) -> bool:
    if (a.get("attackedNation") or "").lower() != (b.get("attackedNation") or "").lower():
        return False
    try:
        da = datetime.fromisoformat(a["date"])
        db = datetime.fromisoformat(b["date"])
    except (KeyError, ValueError):
        return False
    if abs((da - db).days) > 1:
        return False
    ta, tb = facility_tokens(a.get("facility", "")), facility_tokens(b.get("facility", ""))
    if ta and tb:
        return bool(ta & tb)
    return (a.get("facility", "").lower() == b.get("facility", "").lower())


def merge_event(events: list[dict], new: dict) -> bool:
    """Fold `new` into `events`. Returns True if it created a new entry."""
    for existing in events:
        if not same_event(existing, new):
            continue
        known = {s["url"] for s in existing.get("sources", [])}
        for source in new.get("sources", []):
            if source["url"] not in known:
                existing.setdefault("sources", []).append(source)
        existing["sources"].sort(key=source_rank)
        if not existing.get("attacker") and new.get("attacker"):
            existing["attacker"] = new["attacker"]
            existing["attribution"] = new.get("attribution", existing.get("attribution"))
        return False
    events.append(new)
    return True


# ------------------------------------------------------------- discovery

def parse_feed(url: str) -> list[dict]:
    try:
        resp = requests.get(url, headers={"User-Agent": FEED_USER_AGENT}, timeout=15)
        resp.raise_for_status()
        root = ElementTree.fromstring(resp.content)
    except (requests.exceptions.RequestException, ElementTree.ParseError) as exc:
        print(f"[bobboTrade] Refinery attacks: feed failed ({url}): {exc}", file=sys.stderr)
        return []
    items = []
    for item in root.findall(".//item"):
        link = (item.findtext("link") or "").strip()
        pub_raw = item.findtext("pubDate")
        try:
            published = parsedate_to_datetime(pub_raw).astimezone(timezone.utc) if pub_raw else None
        except (TypeError, ValueError):
            published = None
        items.append(
            {
                "url": link.split("?")[0],
                "title": (item.findtext("title") or "").strip(),
                "summary": re.sub(r"<[^>]+>", "", item.findtext("description") or "").strip(),
                "published": published,
            }
        )
    return items


def fetch_gdelt_window(start: datetime, end: datetime) -> list[dict] | None:
    """One GDELT query for [start, end]. None means the window failed."""
    params = {
        "query": GDELT_QUERY,
        "mode": "artlist",
        "format": "json",
        "maxrecords": 250,
        "sort": "datedesc",
        "startdatetime": start.strftime("%Y%m%d%H%M%S"),
        "enddatetime": end.strftime("%Y%m%d%H%M%S"),
    }
    try:
        resp = requests.get(GDELT_URL, params=params, headers={"User-Agent": FEED_USER_AGENT}, timeout=40)
        if resp.status_code == 429:
            # GDELT throttles bursts; one patient retry.
            time.sleep(15)
            resp = requests.get(GDELT_URL, params=params, headers={"User-Agent": FEED_USER_AGENT}, timeout=40)
        if resp.status_code != 200:
            print(f"[bobboTrade] Refinery attacks: GDELT returned {resp.status_code}, skipping window.", file=sys.stderr)
            return None
        articles = resp.json().get("articles", [])
    except (requests.exceptions.RequestException, ValueError) as exc:
        print(f"[bobboTrade] Refinery attacks: GDELT failed: {exc}", file=sys.stderr)
        return None
    items = []
    for art in articles:
        seen = art.get("seendate", "")
        try:
            published = datetime.strptime(seen, "%Y%m%dT%H%M%SZ").replace(tzinfo=timezone.utc)
        except ValueError:
            published = None
        items.append(
            {
                "url": (art.get("url") or "").split("?")[0],
                "title": (art.get("title") or "").strip(),
                "summary": "",
                "published": published,
            }
        )
    return items


def fetch_gdelt(backfill: bool) -> tuple[list[dict], bool]:
    """A single query returns at most 250 articles, newest first, which only
    reaches back about a week on a busy topic. A backfill therefore walks
    the history in weekly windows. Returns (items, every_window_succeeded)."""
    now = datetime.now(timezone.utc)
    if backfill:
        windows = [
            (now - timedelta(days=7 * (i + 1)), now - timedelta(days=7 * i))
            for i in range(math.ceil(FIRST_RUN_BACKFILL_DAYS / 7))
        ]
    else:
        windows = [(now - timedelta(days=RECURRING_LOOKBACK_DAYS), now)]

    items: list[dict] = []
    all_ok = True
    consecutive_failures = 0
    for index, (start, end) in enumerate(windows):
        if index:
            time.sleep(GDELT_PAUSE_SECONDS)
        result = fetch_gdelt_window(start, end)
        if result is None:
            all_ok = False
            consecutive_failures += 1
            if consecutive_failures >= MAX_CONSECUTIVE_GDELT_FAILURES:
                # GDELT is throttling this client. Further windows only burn
                # job time (a full crawl of failing windows took 12 minutes).
                print("[bobboTrade] Refinery attacks: GDELT is throttling, ending the crawl early.", file=sys.stderr)
                break
        else:
            consecutive_failures = 0
            items.extend(result)
    return items, all_ok


def gather_candidates(backfill: bool, processed: dict) -> tuple[list[dict], int, bool]:
    raw: list[dict] = []
    for feed in RSS_FEEDS:
        raw.extend(parse_feed(feed))
    gdelt_items, gdelt_ok = fetch_gdelt(backfill)
    raw.extend(gdelt_items)

    seen: set[str] = set()
    candidates = []
    for item in raw:
        url = item["url"]
        if not url or url in seen or url in processed:
            continue
        seen.add(url)
        if outlet_for(url) is None:
            continue
        blob = f"{item['title']} {item['summary']}"
        if not (REFINERY_RE.search(blob) and ATTACK_RE.search(blob)):
            continue
        candidates.append(item)

    far_past = datetime(1970, 1, 1, tzinfo=timezone.utc)
    candidates.sort(key=lambda c: c["published"] or far_past, reverse=True)
    cap = MAX_CANDIDATES_FIRST_RUN if backfill else MAX_CANDIDATES_PER_RUN
    return candidates[:cap], len(candidates), gdelt_ok


# ------------------------------------------------------------ extraction

def norm_text(text: str) -> str:
    text = text.replace("‘", "'").replace("’", "'").replace("“", '"').replace("”", '"')
    return re.sub(r"\s+", " ", text).strip().lower()


def extract_json_object(text: str) -> dict | None:
    start, end = text.find("{"), text.rfind("}")
    if start == -1 or end <= start:
        return None
    try:
        return json.loads(text[start : end + 1])
    except json.JSONDecodeError:
        return None


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
    text = "".join(b.get("text", "") for b in body.get("content", []) if b.get("type") == "text")
    usage = body.get("usage", {})
    return text, usage.get("input_tokens", 0), usage.get("output_tokens", 0)


def validate_event(raw: dict, article_text_norm: str, history_start: str, today: str) -> dict | None:
    facility = (raw.get("facility") or "").strip()
    nation = normalize_nation(raw.get("attacked_nation"))
    evidence = (raw.get("evidence") or "").strip()
    date = raw.get("date")
    if not facility or not nation or not evidence or not date:
        return None
    if not re.fullmatch(r"\d{4}-\d{2}-\d{2}", str(date)):
        return None
    if not (history_start <= date <= today):
        return None
    if norm_text(evidence) not in article_text_norm:
        return None
    # The quote that proves the attack must itself call the target a
    # refinery. "An oil facility" or "the plant" is not enough: the model
    # tends to stretch a headline's "refinery strikes" over a vaguer body.
    if not REFINERY_RE.search(evidence):
        return None
    attacker = normalize_nation(raw.get("attacker"))
    attribution = raw.get("attribution") if raw.get("attribution") in ATTRIBUTIONS else "unattributed"
    if not attacker:
        attacker, attribution = None, "unattributed"
    return {
        "date": date,
        "facility": facility,
        "attacker": attacker,
        "attackedNation": nation,
        "attribution": attribution,
    }


def month_key() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m")


def run_discovery(state: dict, events: list[dict], history_start: str, api_key: str) -> bool:
    """Returns True if discovery ran far enough to count as a completed cycle."""
    from news import fetch_full_text  # imported lazily: needs trafilatura

    usage = state["usage"]
    if usage.get("month") != month_key():
        usage.clear()
        usage.update({"month": month_key(), "calls": 0, "inputTokens": 0, "outputTokens": 0, "estimatedCostUsd": 0.0})

    backfill = not state.get("backfillDone")
    candidates, total_candidates, gdelt_ok = gather_candidates(backfill, state["processedUrls"])
    print(
        f"[bobboTrade] Refinery attacks: {len(candidates)} of {total_candidates} candidate "
        f"article(s) selected ({'backfill' if backfill else 'daily'})."
    )

    today = datetime.now(timezone.utc).strftime("%Y-%m-%d")
    added = 0
    for item in candidates:
        if usage.get("estimatedCostUsd", 0.0) >= MONTHLY_BUDGET_USD:
            print("[bobboTrade] Refinery attacks: monthly budget reached, stopping extraction.", file=sys.stderr)
            break

        url = item["url"]
        outlet = outlet_for(url)
        body = fetch_full_text(url)
        article = body if body else f"{item['title']}\n{item['summary']}"
        article = article[:ARTICLE_TEXT_CHARS]
        published = item["published"].strftime("%Y-%m-%d") if item["published"] else "unknown"
        message = (
            f"Publication date: {published}\nOutlet: {outlet}\nTitle: {item['title']}\n\nArticle text:\n{article}"
        )

        try:
            text, in_tok, out_tok = call_claude(api_key, message)
        except Exception as exc:  # noqa: BLE001 - leave URL unprocessed so a later run retries it
            print(f"[bobboTrade] Refinery attacks: extraction failed for {url}: {exc}", file=sys.stderr)
            continue

        usage["calls"] = usage.get("calls", 0) + 1
        usage["inputTokens"] = usage.get("inputTokens", 0) + in_tok
        usage["outputTokens"] = usage.get("outputTokens", 0) + out_tok
        usage["estimatedCostUsd"] = round(
            usage["inputTokens"] / 1e6 * INPUT_PRICE_PER_MTOK + usage["outputTokens"] / 1e6 * OUTPUT_PRICE_PER_MTOK, 6
        )
        state["processedUrls"][url] = utc_now_iso()

        parsed = extract_json_object(text) or {}
        article_norm = norm_text(f"{item['title']} {article}")
        for raw in parsed.get("events", []) or []:
            event = validate_event(raw, article_norm, history_start, today)
            if not event:
                continue
            event["id"] = f"{event['date']}-{slugify(event['facility'])[:40]}-{slugify(event['attackedNation'])}"
            event["sources"] = [{"name": outlet, "url": url}]
            event["origin"] = "auto"
            event["addedAt"] = utc_now_iso()
            if merge_event(events, event):
                added += 1

    # One-shot: a backfill is attempted once. GDELT throttles unpredictably,
    # and retrying a crawl on every cycle costs job time for little gain.
    # Anything the backfill missed is covered by the hand-verified seed and
    # by the daily discovery from here on.
    if backfill:
        state["backfillDone"] = True
        state["backfillComplete"] = bool(gdelt_ok and total_candidates <= len(candidates))

    print(f"[bobboTrade] Refinery attacks: {added} new event(s) added.")
    return True


# ---------------------------------------------------------------- output

def build_events(state: dict) -> list[dict]:
    """Seed entries first (authoritative), then accumulated auto events,
    merged so a seed entry and an auto entry for the same strike become one."""
    _, seed_events = load_seed()
    events: list[dict] = []
    for ev in seed_events:
        merge_event(events, json.loads(json.dumps(ev)))
    for ev in state.get("events", []):
        if ev.get("origin") == "seed":
            continue
        merge_event(events, json.loads(json.dumps(ev)))
    for ev in events:
        ev["sources"].sort(key=source_rank)
    events.sort(key=lambda e: e["date"], reverse=True)
    return events


def public_payload(events: list[dict], history_start: str, state: dict) -> dict:
    return {
        "fetchedAt": utc_now_iso(),
        "lastDiscoveryAt": state.get("lastRunAt"),
        "historyStart": history_start,
        "count": len(events),
        "events": [
            {
                "id": e["id"],
                "date": e["date"],
                "facility": e["facility"],
                "attacker": e.get("attacker"),
                "attackedNation": e["attackedNation"],
                "attribution": e.get("attribution", "unattributed"),
                "sources": e["sources"],
            }
            for e in events
        ],
    }


def is_due(state: dict) -> bool:
    last = state.get("lastRunAt")
    if not last:
        return True
    try:
        when = datetime.fromisoformat(last)
    except ValueError:
        return True
    if when.tzinfo is None:
        when = when.replace(tzinfo=timezone.utc)
    return datetime.now(timezone.utc) - when >= timedelta(hours=REFRESH_AFTER_HOURS)


def main(ticker: str | None = None) -> None:
    history_start, _ = load_seed()
    state = load_state()

    api_key = os.environ.get("ANTHROPIC_API_KEY")
    accumulated = [e for e in state["events"] if e.get("origin") != "seed"]

    if not api_key:
        print("[bobboTrade] Refinery attacks: ANTHROPIC_API_KEY not set, skipping discovery.")
    elif not is_due(state):
        print("[bobboTrade] Refinery attacks: discovery ran recently, reusing accumulated events.")
    else:
        try:
            if run_discovery(state, accumulated, history_start, api_key):
                state["lastRunAt"] = utc_now_iso()
        except Exception as exc:  # noqa: BLE001 - a failed cycle must never drop the card
            print(f"[bobboTrade] Refinery attacks: discovery failed: {exc}", file=sys.stderr)

    # Forget old processed-URL markers so the state file does not grow forever.
    cutoff = datetime.now(timezone.utc) - timedelta(days=PROCESSED_URL_RETENTION_DAYS)
    kept = {}
    for url, stamp in state["processedUrls"].items():
        try:
            when = datetime.fromisoformat(stamp)
            if when.tzinfo is None:
                when = when.replace(tzinfo=timezone.utc)
        except ValueError:
            continue
        if when >= cutoff:
            kept[url] = stamp
    state["processedUrls"] = kept
    state["events"] = accumulated
    write_state(state)

    events = build_events(state)
    write_json("global", "refinery_attacks.json", public_payload(events, history_start, state))


if __name__ == "__main__":
    main()
