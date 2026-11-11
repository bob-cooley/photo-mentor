"""civicAPI (civicapi.org): House seats, plus Senate and governor results by state.

civicAPI is free and keyless but slow under election-night load, so each
request runs in its own thread alongside the Wikipedia reads, and the last
good answer is cached on disk and reused (up to 30 minutes) when one fails.
"""
import json
import threading
import time
import urllib.request
from pathlib import Path

UA = "bobcooleyphoto-election-board/1.0 (https://bobcooleyphoto.com/election/)"
HERE = Path(__file__).resolve().parent
MAX_AGE = 30 * 60
SOURCES = {
    "house": "https://civicapi.org/api/v2/race/87524",     # US House national summary
    "senate": "https://civicapi.org/api/v2/race/87528",    # US Senate, by state
    "governor": "https://civicapi.org/api/v2/race/87535",  # Governors, by state
}

_results = {k: {} for k in SOURCES}
_threads = {}


def _party(p):
    p = (p or "").lower()
    return "D" if p.startswith("democrat") else "R" if p.startswith("republican") else "O"


def _get(url):
    req = urllib.request.Request(url, headers={"User-Agent": UA, "Accept": "application/json"})
    with urllib.request.urlopen(req, timeout=50) as r:
        return json.load(r)


def _num(v):
    """Numbers only: anything else from the API becomes None."""
    if isinstance(v, bool):
        return None
    if isinstance(v, (int, float)):
        return v
    try:
        return float(str(v).replace(",", ""))
    except (TypeError, ValueError):
        return None


def _cache(kind):
    return HERE / f"civic_{kind}_cache.json"


def _parse(kind, d):
    out = {"reporting": _num(d.get("percent_reporting")), "updated": str(d.get("last_updated") or "")[:40],
           "fetched": time.time()}
    if kind == "house":
        seats = {"D": 0, "R": 0, "O": 0}
        for c in d.get("candidates", []):
            seats[_party(c.get("party"))] += int(_num(c.get("seats")) or 0)
        out["seats"] = seats
        # region_results here are districts keyed like "co-04" / "ak-al"
    regions, won = {}, {"D": 0, "R": 0, "O": 0}
    for code, reg in (d.get("region_results") or {}).items():
        cands = [{"name": str(c.get("name") or "")[:80], "party": _party(c.get("party")),
                  "votes": _num(c.get("votes")), "pct": _num(c.get("percent"))}
                 for c in reg.get("candidates", [])]
        winner = next((_party(c.get("party")) for c in reg.get("candidates", []) if c.get("winner")), None)
        if winner:
            won[winner] += 1
        regions[str(code).lower()] = {"winner": winner, "reporting": _num(reg.get("percent_reporting")),
                                 "candidates": cands}
    out.update(regions=regions, won=won, races=len(regions))
    return out


def _work(kind):
    try:
        out = _parse(kind, _get(SOURCES[kind]))
        _cache(kind).write_text(json.dumps(out))
        _results[kind].update(out)
    except Exception as e:
        _results[kind]["error"] = f"{type(e).__name__}: {e}"


def start():
    for kind in SOURCES:
        t = threading.Thread(target=_work, args=(kind,), daemon=True)
        t.start()
        _threads[kind] = t


def get(kind, wait=55):
    """Parsed result for kind, or None. Waits for the fetch, then falls back to the cache."""
    t = _threads.get(kind)
    if t:
        t.join(wait)
    r = _results[kind]
    if "fetched" in r:
        return dict(r, stale=False)
    try:
        c = json.loads(_cache(kind).read_text())
        if time.time() - c["fetched"] < MAX_AGE:
            return dict(c, stale=True)
    except Exception:
        pass
    return None


def error(kind):
    return _results[kind].get("error")


# backwards-compatible helpers used by fetch.py
def house(wait=55):
    return get("house", wait)


def senate(wait=55):
    return get("senate", wait)
