#!/usr/bin/env python3
"""Build election/data.json from Wikipedia race pages plus overrides.json.

Free and keyless: uses the public MediaWiki API (action=parse, wikitext).
Statewide races and chamber totals come from each page's {{Infobox election}};
House districts come from the {{Election box ...}} rows under the
"District N" heading of the state's House page. overrides.json wins over
anything scraped, so a bad or lagging Wikipedia edit can be corrected by hand.

Stdlib only. Usage: python3 fetch.py [--debug]
"""
import json
import re
import sys
import time
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

HERE = Path(__file__).resolve().parent
API = "https://en.wikipedia.org/w/api.php"
UA = "bobcooleyphoto-election-board/1.0 (https://bobcooleyphoto.com/election/)"
DEBUG = "--debug" in sys.argv

_page_cache = {}


def log(*a):
    print(*a, flush=True)


def get_wikitext(title):
    if title in _page_cache:
        return _page_cache[title]
    q = urllib.parse.urlencode({
        "action": "parse", "page": title, "prop": "wikitext|revid",
        "format": "json", "formatversion": "2", "redirects": "1",
    })
    req = urllib.request.Request(f"{API}?{q}", headers={"User-Agent": UA})
    text, revid = None, None
    for attempt in range(3):
        try:
            with urllib.request.urlopen(req, timeout=20) as r:
                body = json.load(r)
            if "error" in body:
                log(f"  ! {title}: {body['error'].get('info')}")
                break
            text = body["parse"]["wikitext"]
            text = re.sub(r"<!--.*?-->", "", text, flags=re.S)
            revid = body["parse"].get("revid")
            break
        except Exception as e:  # network hiccup: retry
            log(f"  ! {title}: {e} (attempt {attempt + 1})")
            time.sleep(2 * (attempt + 1))
    _page_cache[title] = (text, revid)
    return text, revid


# ---------- wikitext helpers ----------

def find_template(text, name_re, start=0):
    """Return (start, end) of the first {{...}} whose name matches name_re."""
    pat = re.compile(r"\{\{\s*(" + name_re + r")\s*[|}\n]", re.I)
    m = pat.search(text, start)
    if not m:
        return None
    i, depth = m.start(), 0
    while i < len(text) - 1:
        two = text[i:i + 2]
        if two == "{{":
            depth += 1
            i += 2
            continue
        if two == "}}":
            depth -= 1
            i += 2
            if depth == 0:
                return m.start(), i
            continue
        i += 1
    return None


def template_params(tpl):
    """Split a template's body on top-level pipes into {name: value}."""
    body = tpl[2:-2]
    parts, buf, depth_t, depth_l = [], [], 0, 0
    i = 0
    while i < len(body):
        two = body[i:i + 2]
        if two == "{{":
            depth_t += 1; buf.append(two); i += 2; continue
        if two == "}}":
            depth_t -= 1; buf.append(two); i += 2; continue
        if two == "[[":
            depth_l += 1; buf.append(two); i += 2; continue
        if two == "]]":
            depth_l -= 1; buf.append(two); i += 2; continue
        c = body[i]
        if c == "|" and depth_t == 0 and depth_l == 0:
            parts.append("".join(buf)); buf = []
        else:
            buf.append(c)
        i += 1
    parts.append("".join(buf))
    out = {}
    for p in parts[1:]:
        if "=" in p:
            k, v = p.split("=", 1)
            out[k.strip().lower()] = v.strip()
    return out


def clean(v):
    """Wikitext value -> plain text."""
    if not v:
        return ""
    v = re.sub(r"<ref[^>]*/>", "", v, flags=re.S)
    v = re.sub(r"<ref[^>]*>.*?</ref>", "", v, flags=re.S)
    v = re.sub(r"<!--.*?-->", "", v, flags=re.S)
    v = re.sub(r"<br\s*/?>", " ", v)
    v = re.sub(r"<[^>]+>", "", v)
    # {{nowrap|x}}, {{small|x}} -> x ; drop other templates
    for _ in range(3):
        v = re.sub(r"\{\{\s*(?:nowrap|small|nobold|ubl|plainlist)\s*\|([^{}]*)\}\}",
                   lambda m: m.group(1).split("|")[0], v, flags=re.I)
    v = re.sub(r"\{\{[^{}]*\}\}", "", v)
    v = re.sub(r"\[\[(?:[^|\]]*\|)?([^\]]*)\]\]", r"\1", v)
    v = v.replace("'''", "").replace("''", "")
    v = v.replace("&nbsp;", " ")
    return re.sub(r"\s+", " ", v).strip()


def person(v):
    return re.sub(r"\s*\([^)]*\)\s*$", "", clean(v)).strip()


def num(v):
    v = clean(v)
    m = re.search(r"\d[\d,]*(?:\.\d+)?", v)
    if not m:
        return None
    s = m.group(0).replace(",", "")
    return float(s) if "." in s else int(s)


def party_code(v):
    t = clean(v).lower()
    if "democrat" in t or "dfl" in t:
        return "D"
    if "republican" in t:
        return "R"
    return "O"


def is_set(v):
    t = clean(v).lower()
    return bool(t) and t not in {"tbd", "tba", "n/a", "?", "pending", "to be determined"}


# ---------- extractors ----------

def from_infobox(text):
    span = find_template(text, r"Infobox[ _]election")
    if not span:
        return None
    p = template_params(text[span[0]:span[1]])
    if DEBUG:
        keys = sorted(k for k in p if re.match(r"(candidate|nominee|party|popular_vote|percentage|after|seats_after|seats_before|seat_change)\d*", k))
        for k in keys:
            log(f"    {k} = {clean(p[k])[:80]}")
    cands = []
    for n in range(1, 7):
        name = person(p.get(f"candidate{n}") or p.get(f"nominee{n}"))
        if not name:
            continue
        cands.append({
            "name": name,
            "party": party_code(p.get(f"party{n}", "")),
            "votes": num(p.get(f"popular_vote{n}")),
            "pct": num(p.get(f"percentage{n}")),
            "seats": num(p.get(f"seats_after{n}")),
        })
    winner = None
    if is_set(p.get("after_election")):
        wname = clean(p["after_election"]).lower()
        for c in cands:
            if c["name"].lower() in wname or wname in c["name"].lower():
                winner = c["party"]
                break
        if winner is None and is_set(p.get("after_party")):
            winner = party_code(p["after_party"])
    return {"candidates": cands, "called": winner}


def district_section(text, district):
    """Wikitext of the '== District N ==' section (any heading level)."""
    heads = list(re.finditer(r"^(={2,4})\s*(.*?)\s*\1\s*$", text, re.M))
    for i, h in enumerate(heads):
        title = clean(h.group(2)).lower()
        if re.fullmatch(rf"district\s+{district}(?:st|nd|rd|th)?", title) or \
           re.fullmatch(rf"{district}(?:st|nd|rd|th)? district", title):
            level = len(h.group(1))
            end = len(text)
            for h2 in heads[i + 1:]:
                if len(h2.group(1)) <= level:
                    end = h2.start()
                    break
            return text[h.end():end]
    return None


def from_electionbox(text, district):
    sec = district_section(text, district)
    if sec is None:
        return None
    # Use the last {{Election box begin}} in the section: general election
    # results sit below the primary boxes.
    begins = [m.start() for m in re.finditer(r"\{\{\s*Election box begin", sec, re.I)]
    if begins:
        sec = sec[begins[-1]:]
        endm = re.search(r"\{\{\s*Election box end", sec, re.I)
        if endm:
            sec = sec[:endm.end()]
    cands, winner, pos = [], None, 0
    while True:
        span = find_template(sec, r"Election box (?:winning )?candidate[^|}]*", pos)
        if not span:
            break
        tpl = sec[span[0]:span[1]]
        pos = span[1]
        p = template_params(tpl)
        name = person(p.get("candidate"))
        if not name:
            continue
        c = {"name": name, "party": party_code(p.get("party", "")),
             "votes": num(p.get("votes")), "pct": num(p.get("percentage")), "seats": None}
        cands.append(c)
        if re.match(r"\{\{\s*Election box winning", tpl, re.I):
            winner = c["party"]
    if DEBUG:
        for c in cands:
            log(f"    {c}")
    return {"candidates": cands, "called": winner}


# ---------- assemble ----------

def merge(cands):
    """Fold fusion-ballot lines (same person, several parties) into one."""
    out = {}
    for c in cands:
        k = c["name"].lower()
        if k not in out:
            out[k] = dict(c)
            continue
        m = out[k]
        if m["party"] == "O" and c["party"] != "O":
            m["party"] = c["party"]
        if c["votes"] is not None:
            m["votes"] = (m["votes"] or 0) + c["votes"]
        if c["pct"] is not None:
            m["pct"] = (m["pct"] or 0) + c["pct"]
    return list(out.values())


def tidy(cands):
    """Keep the top two by votes (or listed order), D side first."""
    cands = merge(cands)
    # When every candidate has a raw vote count, trust it over the
    # hand-typed percentage fields (they drift out of sync mid-count).
    if cands and all(c["votes"] is not None for c in cands):
        t = sum(c["votes"] for c in cands)
        if t:
            for c in cands:
                c["pct"] = round(100 * c["votes"] / t, 1)
    ranked = sorted(cands, key=lambda c: -(c["votes"] or c["pct"] or 0)) if any(
        c["votes"] or c["pct"] for c in cands) else cands
    top = ranked[:2]
    others = ranked[2:]
    total = sum(c["votes"] or 0 for c in cands)
    if total:
        for c in cands:
            if c["votes"] is not None and c["pct"] is None:
                c["pct"] = round(100 * c["votes"] / total, 1)
    order = {"D": 0, "O": 1, "R": 2}
    top.sort(key=lambda c: order[c["party"]])
    other_pct = round(sum(c["pct"] or 0 for c in others), 1)
    return top, other_pct, total


def main():
    cfg = json.loads((HERE / "races.json").read_text())
    ov = json.loads((HERE / "overrides.json").read_text())
    out = {"updated": datetime.now(timezone.utc).isoformat(timespec="seconds"),
           "source": "wikipedia", "chambers": [], "sections": []}

    for ch in cfg["chambers"]:
        log(f"chamber {ch['id']}")
        text, _ = get_wikitext(ch["page"])
        seats = {"D": None, "R": None, "O": None}
        if text:
            ib = from_infobox(text)
            if ib:
                for c in ib["candidates"]:
                    if c["seats"] is not None:
                        seats[c["party"]] = (seats[c["party"]] or 0) + c["seats"]
        manual = ov.get("chambers", {}).get(ch["id"])
        if manual:
            seats.update(manual)
        out["chambers"].append({**ch, "seats": seats, "manual": bool(manual)})

    for sec in cfg["sections"]:
        races = []
        for r in sec["races"]:
            log(f"{sec['id']}: {r['id']}")
            text, revid = get_wikitext(r["page"])
            data = None
            if text:
                data = from_infobox(text) if sec["kind"] == "infobox" else from_electionbox(text, r["district"])
            entry = {"id": r["id"], "label": r["label"],
                     "url": "https://en.wikipedia.org/wiki/" + urllib.parse.quote(r["page"].replace(" ", "_")),
                     "candidates": [], "other_pct": 0, "total_votes": 0, "called": None, "ok": False}
            if data and data["candidates"]:
                top, other_pct, total = tidy(data["candidates"])
                entry.update(candidates=top, other_pct=other_pct, total_votes=total,
                             called=data["called"], ok=True)
            o = ov.get("races", {}).get(r["id"])
            if o:
                entry.update({k: v for k, v in o.items() if not k.startswith("_")})
                entry["manual"] = True
            if not entry["ok"]:
                log(f"  ! no candidates parsed for {r['id']}")
            races.append(entry)
        out["sections"].append({"id": sec["id"], "label": sec["label"], "races": races})

    ok = sum(r["ok"] for s in out["sections"] for r in s["races"])
    n = sum(len(s["races"]) for s in out["sections"])
    if ok == 0:
        log("no races parsed; leaving data.json untouched")
        sys.exit(2)
    (HERE / "data.json").write_text(json.dumps(out, indent=1))
    log(f"wrote data.json: {ok}/{n} races parsed")


if __name__ == "__main__":
    main()
