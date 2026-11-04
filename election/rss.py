"""Race calls from news RSS headlines.

Reads the politics feeds of several outlets and looks for headlines that name
a winner ("Luria defeats Kiggans...", "Darline Graham falls to Democrat Annie
Andrews"). A race is only marked called when at least MIN_OUTLETS different
outlets agree on the same winner and none point the other way.
"""
import re
import urllib.request
import xml.etree.ElementTree as ET
from email.utils import parsedate_to_datetime
from datetime import datetime, timezone

UA = "bobcooleyphoto-election-board/1.0 (https://bobcooleyphoto.com/election/)"
FEEDS = {
    "nbc": "https://feeds.nbcnews.com/nbcnews/public/politics",
    "fox": "https://moxie.foxnews.com/google-publisher/politics.xml",
    "abc": "https://feeds.abcnews.com/abcnews/politicsheadlines",
    "cbs": "https://www.cbsnews.com/latest/rss/politics",
    "npr": "https://feeds.npr.org/1014/rss.xml",
    "nyt": "https://rss.nytimes.com/services/xml/rss/nyt/Politics.xml",
    "pbs": "https://www.pbs.org/newshour/feeds/rss/politics",
    "hill": "https://thehill.com/homenews/campaign/feed/",
    "politico": "https://rss.politico.com/politics-news.xml",
}
MIN_OUTLETS = 2
# Ignore anything published before polls started closing.
SINCE = datetime(2026, 11, 3, 22, 0, tzinfo=timezone.utc)

WIN = r"(?:wins?|won|after winning|defeats|beats|ousts|unseats|flips|holds|keeps|retains|sails|cruises|is elected|elected|re-?elected|projected to win|declared (?:the )?winner|victory|victorious)"
LOSE = r"(?:loses|lost|falls to|concedes|ousted|defeated|unseated|after loss|loss|in defeat)"
# Headlines with these words are previews, leads or primaries, not calls.
SKIP = re.compile(r"\?|\b(?:lead|leads|leading|ahead|edge|early|could|may|might|would|if|poll|polls|primary|runoff|nomination|endorse[sd]?|race between|tests|watch for)\b", re.I)
SUFFIX = {"jr.", "sr.", "jr", "sr", "ii", "iii", "iv"}
STATES = ["alabama", "alaska", "arizona", "arkansas", "california", "colorado", "connecticut",
          "delaware", "florida", "georgia", "hawaii", "idaho", "illinois", "indiana", "iowa",
          "kansas", "kentucky", "louisiana", "maine", "maryland", "massachusetts", "michigan",
          "minnesota", "mississippi", "missouri", "montana", "nebraska", "nevada", "new hampshire",
          "new jersey", "new mexico", "new york", "north carolina", "north dakota", "ohio",
          "oklahoma", "oregon", "pennsylvania", "rhode island", "south carolina", "south dakota",
          "tennessee", "texas", "utah", "vermont", "virginia", "washington", "west virginia",
          "wisconsin", "wyoming"]
OFFICE = {"senate": r"senate|senator", "house": r"house|congress|district", "governor": r"governor|gubernatorial"}


def fetch_headlines(log=print):
    heads = []
    for outlet, url in FEEDS.items():
        try:
            req = urllib.request.Request(url, headers={"User-Agent": UA})
            with urllib.request.urlopen(req, timeout=15) as r:
                root = ET.fromstring(r.read())
        except Exception as e:
            log(f"  ! rss {outlet}: {type(e).__name__}")
            continue
        for it in root.iter("item"):
            title = (it.findtext("title") or "").strip()
            try:
                when = parsedate_to_datetime(it.findtext("pubDate") or "")
                if when.tzinfo is None:
                    when = when.replace(tzinfo=timezone.utc)
            except Exception:
                continue
            if title and when >= SINCE:
                heads.append((outlet, title))
    return heads


def name_parts(full):
    toks = [t for t in full.replace(",", " ").split() if t.lower() not in SUFFIX]
    if len(toks) < 2:
        return full, full
    first = toks[0]
    rest = [t for t in toks[1:] if not re.fullmatch(r"[A-Z]\.", t)]
    last = " ".join(rest) if rest else toks[-1]
    return f"{first} {last}", last


def other_state(title, state):
    t = title.lower()
    for st in STATES:
        if st == state or st in state:
            continue
        if re.search(rf"\b{st}\b", t) and not (st == "virginia" and "west virginia" in t and state == "west virginia"):
            return True
    return False


AMBIGUOUS = set()  # last names that appear in more than one tracked race
LAST_HEADS = []    # headlines from the most recent fetch, reused for chamber control

CONTROL = re.compile(
    r"\b(democrats?|dems?|republicans?|gop)\b[^.?!;:]{0,30}?"
    r"\b(?:wins?|won|takes?|took|retakes?|retook|flips?|flipped|keeps?|kept|holds?|held|retains?|"
    r"retained|clinch(?:es)?|clinched|seizes?|seized|captures?|captured|projected to (?:win|take|keep|hold|retain))\b"
    r"[^.?!;:]{0,20}?\b(house|senate)\b", re.I)
CONGRESS = re.compile(
    r"\b(democrats?|dems?|republicans?|gop)\b[^.?!;:]{0,30}?"
    r"\b(?:wins?|won|takes?|took|retakes?|retook|flips?|flipped|keeps?|kept|holds?|held|retains?|"
    r"retained|clinch(?:es)?|clinched|seizes?|seized|captures?|captured|projected to (?:win|take|keep|hold|retain))\b"
    r"[^.?!;:]{0,25}?\b(?:congress|both chambers)\b", re.I)
CONTROL_SKIP = re.compile(
    r"\?|\b(?:could|would|may|might|if|fight|fights|battle|battles|bid|push|pushes|try|tries|"
    r"seek|seeks|aim|aims|hope|hopes|eye|eyes|vie|poised|on track|path|chance|chances|odds|need|"
    r"needs|plan|plans|what|how|why|when|whether|race|races|seat|seats|district)\b", re.I)


def control_calls(log=print):
    """{"house": "D"/"R", "senate": ...} where MIN_OUTLETS outlets agree on chamber control."""
    votes = {}
    for outlet, title in LAST_HEADS:
        if CONTROL_SKIP.search(title):
            continue
        for m in CONTROL.finditer(title):
            party = "R" if m.group(1).lower() in ("republican", "republicans", "gop") else "D"
            chambers = [m.group(2).lower()]
            # "... win control of House and Senate"
            more = re.match(r"\s*(?:and|&)\s+(?:the\s+)?(house|senate)\b", title[m.end():], re.I)
            if more:
                chambers.append(more.group(1).lower())
            for ch in chambers:
                votes.setdefault(ch, {}).setdefault(party, set()).add(outlet)
        for m in CONGRESS.finditer(title):
            party = "R" if m.group(1).lower() in ("republican", "republicans", "gop") else "D"
            for ch in ("house", "senate"):
                votes.setdefault(ch, {}).setdefault(party, set()).add(outlet)
    out = {}
    for chamber, by in votes.items():
        log(f"  rss control {chamber}: {{{', '.join(f'{p}: {sorted(o)}' for p, o in by.items())}}}")
        if len(by) == 1:
            party, outlets = next(iter(by.items()))
            if len(outlets) >= MIN_OUTLETS:
                out[chamber] = (party, sorted(outlets))
    return out


def mentions(title, cand, state, office):
    """Last name if the headline is about this candidate in this race."""
    full, last = name_parts(cand)
    if re.search(rf"\b{re.escape(full)}\b", title, re.I):
        return last
    if not re.search(rf"\b{re.escape(last)}\b", title, re.I):
        return None
    if re.search(rf"\b{re.escape(state)}\b", title, re.I):
        return last
    # last name + the office ("Pappas defeats ... Senate victory"), but only
    # when no other state is named (keeps Mike Collins out of Maine)
    if last.lower() not in AMBIGUOUS and re.search(rf"\b(?:{OFFICE[office]})\b", title, re.I) \
            and not other_state(title, state):
        return last
    return None


def signal(title, race_cands, state, office):
    """Party this headline says won the race, or None."""
    if SKIP.search(title):
        return None
    winners = set()
    for c in race_cands:
        last = mentions(title, c["name"], state, office)
        if not last:
            continue
        nm = re.escape(last)
        if re.search(rf"\b{nm}\b[^.?!,;:]{{0,25}}?\b{WIN}\b", title, re.I):
            winners.add(c["party"])
        if re.search(rf"\b{nm}\b[^.?!,;:]{{0,25}}?\b{LOSE}\b", title, re.I) or \
           re.search(rf"\b(?:defeats|beats|ousts|unseats|over)\s+(?:\S+\s+){{0,3}}?{nm}\b", title, re.I):
            winners.update(o["party"] for o in race_cands if o is not c)
    return winners.pop() if len(winners) == 1 else None


def calls(races, log=print):
    """races: list of (race_id, state, office, candidates). Returns {race_id: (party, outlets, headline)}."""
    seen = {}
    for rid, _, _, cands in races:
        for c in cands:
            seen.setdefault(name_parts(c["name"])[1].lower(), set()).add(rid)
    AMBIGUOUS.clear()
    AMBIGUOUS.update(k for k, v in seen.items() if len(v) > 1)
    heads = fetch_headlines(log)
    LAST_HEADS[:] = heads
    log(f"rss: {len(heads)} recent headlines")
    out = {}
    for rid, state, office, cands in races:
        if len(cands) < 2:
            continue
        votes = {}
        for outlet, title in heads:
            p = signal(title, cands, state, office)
            if p:
                votes.setdefault(p, {}).setdefault(outlet, title)
        if len(votes) == 1:
            party, by = next(iter(votes.items()))
            if len(by) >= MIN_OUTLETS:
                out[rid] = (party, sorted(by), next(iter(by.values())))
        elif len(votes) > 1:
            log(f"  ! rss conflict on {rid}: {votes}")
        if votes:
            log(f"  rss {rid}: {{{', '.join(f'{p}: {sorted(v)}' for p, v in votes.items())}}}")
    return out
