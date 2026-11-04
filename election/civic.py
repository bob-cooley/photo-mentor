"""House seat count from civicAPI (civicapi.org), fetched in the background.

civicAPI is free and keyless but slow under election-night load, so the
request runs in a thread alongside the Wikipedia reads, and the last good
answer is cached on disk and reused (up to 30 minutes) when it fails.
"""
import json
import threading
import time
import urllib.request
from pathlib import Path

UA = "bobcooleyphoto-election-board/1.0 (https://bobcooleyphoto.com/election/)"
HOUSE_URL = "https://civicapi.org/api/v2/race/87524"   # US House national summary
CACHE = Path(__file__).resolve().parent / "civic_cache.json"
MAX_AGE = 30 * 60

_result = {}
_thread = None


def _work():
    try:
        req = urllib.request.Request(HOUSE_URL, headers={"User-Agent": UA, "Accept": "application/json"})
        with urllib.request.urlopen(req, timeout=50) as r:
            d = json.load(r)
        seats = {"D": 0, "R": 0, "O": 0}
        for c in d.get("candidates", []):
            n = c.get("seats") or 0
            p = (c.get("party") or "").lower()
            k = "D" if p.startswith("democrat") else "R" if p.startswith("republican") else "O"
            seats[k] += n
        out = {"seats": seats, "reporting": d.get("percent_reporting"),
               "updated": d.get("last_updated"), "fetched": time.time()}
        CACHE.write_text(json.dumps(out))
        _result.update(out)
    except Exception as e:
        _result["error"] = f"{type(e).__name__}: {e}"


def start():
    global _thread
    _thread = threading.Thread(target=_work, daemon=True)
    _thread.start()


def house(wait=55):
    """Seat counts dict, or None. Waits for the background fetch, then the cache."""
    if _thread:
        _thread.join(wait)
    if "seats" in _result:
        return dict(_result, stale=False)
    try:
        c = json.loads(CACHE.read_text())
        if time.time() - c["fetched"] < MAX_AGE:
            return dict(c, stale=True)
    except Exception:
        pass
    return None
