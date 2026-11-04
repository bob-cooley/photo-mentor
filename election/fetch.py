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
    rep = num(p.get("reporting") or p.get("votes_counted"))
    if rep is not None and not 0 <= rep <= 100:
        rep = None
    before = party_code(p["before_party"]) if is_set(p.get("before_party")) else None
    return {"candidates": cands, "called": winner, "reporting": rep,
            "last_update": clean(p.get("last_update"))[:40] or None, "incumbent": before}


def chamber_seats(text):
    """{party: seats_before / seats_after} from a chamber-wide infobox."""
    span = find_template(text, r"Infobox[ _]election")
    if not span:
        return None, None
    p = template_params(text[span[0]:span[1]])
    before, after = {}, {}
    for n in range(1, 8):
        if not is_set(p.get(f"party{n}")):
            continue
        k = party_code(p[f"party{n}"])
        b, a = num(p.get(f"seats_before{n}")), num(p.get(f"seats_after{n}"))
        if b is not None:
            before[k] = before.get(k, 0) + b
        if a is not None:
            after[k] = after.get(k, 0) + a
    return before, after


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
    return {"candidates": cands, "called": winner, "reporting": None, "last_update": None}


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


OFFICE_BY_SECTION = {"senate-races": "senate", "house-races": "house", "governors": "governor"}


def apply_news_calls(cfg, ov, out):
    """Fill uncalled races from news RSS headlines (see rss.py)."""
    try:
        import rss
    except Exception as e:
        log(f"  ! rss module: {e}")
        return
    todo, by_id = [], {}
    for sec in out["sections"]:
        office = OFFICE_BY_SECTION.get(sec["id"], "senate")
        for r in sec["races"]:
            by_id[r["id"]] = r
            if r["called"]:
                r.setdefault("called_by", "manual" if "called" in ov.get("races", {}).get(r["id"], {}) else "wikipedia")
            state = re.sub(r"\s*\(.*\)|\s+\d+$", "", r["label"]).strip().lower()
            todo.append((r["id"], state, office, r["candidates"]))
    try:
        found = rss.calls(todo, log)
    except Exception as e:
        log(f"  ! rss failed: {e}")
        return
    for rid, (party, outlets, headline) in found.items():
        r = by_id[rid]
        if "called" in ov.get("races", {}).get(rid, {}):
            continue
        if r["called"] and r["called"] != party:
            log(f"  ! {rid}: wikipedia says {r['called']}, news says {party}; keeping wikipedia")
            continue
        if not r["called"]:
            r["called"] = party
            r["called_by"] = "news: " + ", ".join(outlets)
            log(f"  news call {rid} -> {party} ({', '.join(outlets)}): {headline}")


def tally_senate(out):
    """Senate seats = seats not up this year + called races.

    Seats not up = the chamber's pre-election seats minus the seats being
    contested, by the incumbent party on each race page.
    """
    ch = next((c for c in out["chambers"] if c["id"] == "senate"), None)
    sec = next((s for s in out["sections"] if s["id"] == "senate-races"), None)
    if not ch or not sec or ch["seats"]["D"] is not None:
        return
    before = ch.get("before") or {}
    if sum(before.values()) != ch["total"]:
        log(f"  ! senate tally: seats before {before} do not sum to {ch['total']}")
        return
    up = {}
    for r in sec["races"]:
        if not r.get("incumbent"):
            log(f"  ! senate tally: no incumbent party for {r['id']}")
            return
        up[r["incumbent"]] = up.get(r["incumbent"], 0) + 1
    hold = {k: before.get(k, 0) - up.get(k, 0) for k in ("D", "R", "O")}
    if min(hold.values()) < 0:
        log(f"  ! senate tally: negative holdover {hold}")
        return
    seats = dict(hold)
    for r in sec["races"]:
        if r["called"] in seats:
            seats[r["called"]] += 1
    ch["seats"] = seats
    ch["basis"] = f"{sum(hold.values())} seats not up this year + {sum(1 for r in sec['races'] if r['called'])} called races"
    log(f"  senate tally: holdover {hold} -> {seats}")


def main():
    cfg = json.loads((HERE / "races.json").read_text())
    ov = json.loads((HERE / "overrides.json").read_text())
    out = {"updated": datetime.now(timezone.utc).isoformat(timespec="seconds"),
           "source": "wikipedia", "chambers": [], "sections": []}

    for ch in cfg["chambers"]:
        log(f"chamber {ch['id']}")
        text, _ = get_wikitext(ch["page"])
        seats = {"D": None, "R": None, "O": None}
        before = {}
        if text:
            before, after = chamber_seats(text)
            before = before or {}
            if after and sum(after.values()) == ch["total"]:
                seats.update(after)  # final totals, once editors fill them in
        log(f"  seats before: {before}")
        out["chambers"].append({**ch, "seats": seats, "before": before, "basis": None})

    for sec in cfg["sections"]:
        races = []
        for r in sec["races"]:
            log(f"{sec['id']}: {r['id']}")
            text, revid = get_wikitext(r["page"])
            data = None
            if text:
                data = from_infobox(text) if sec["kind"] == "infobox" else from_electionbox(text, r["district"])
            entry = {"id": r["id"], "label": r["label"], "key": bool(r.get("key")),
                     "url": "https://en.wikipedia.org/wiki/" + urllib.parse.quote(r["page"].replace(" ", "_")),
                     "candidates": [], "other_pct": 0, "total_votes": 0, "called": None, "ok": False,
                     "reporting": None, "last_update": None, "incumbent": None}
            if data and data["candidates"]:
                top, other_pct, total = tidy(data["candidates"])
                entry.update(candidates=top, other_pct=other_pct, total_votes=total,
                             called=data["called"], ok=True,
                             reporting=data["reporting"], last_update=data["last_update"],
                             incumbent=data.get("incumbent"))
            o = ov.get("races", {}).get(r["id"])
            if o:
                entry.update({k: v for k, v in o.items() if not k.startswith("_")})
                entry["manual"] = True
            if not entry["ok"]:
                log(f"  ! no candidates parsed for {r['id']}")
            races.append(entry)
        out["sections"].append({"id": sec["id"], "label": sec["label"],
                                "all_label": sec.get("all_label"), "races": races})

    apply_news_calls(cfg, ov, out)
    tally_senate(out)
    for ch in out["chambers"]:
        manual = ov.get("chambers", {}).get(ch["id"])
        if manual:
            ch["seats"].update(manual)
            ch["basis"] = "manual"

    ok = sum(r["ok"] for s in out["sections"] for r in s["races"])
    n = sum(len(s["races"]) for s in out["sections"])
    if ok == 0:
        log("no races parsed; leaving data.json untouched")
        sys.exit(2)
    (HERE / "data.json").write_text(json.dumps(out, indent=1))
    log(f"wrote data.json: {ok}/{n} races parsed")


if __name__ == "__main__":
    main()
