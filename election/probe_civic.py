#!/usr/bin/env python3
"""Diagnostic: civicAPI Senate summary region_results, to separate holdovers from called races."""
import json, time, urllib.request
UA = "bobcooleyphoto-election-board/1.0 (https://bobcooleyphoto.com/election/)"
def get(u, timeout=60, tries=4):
    for i in range(tries):
        try:
            req = urllib.request.Request(u, headers={"User-Agent": UA, "Accept": "application/json"})
            with urllib.request.urlopen(req, timeout=timeout) as r:
                return json.loads(r.read())
        except Exception as e:
            print(f"  try {i+1}: {e}"); time.sleep(5)
d = get("https://civicapi.org/api/v2/race/87528")
print({c["party"]: c.get("seats") for c in d["candidates"] if c.get("seats")})
rr = d.get("region_results")
print("region_results type:", type(rr).__name__, "len", len(rr) if hasattr(rr, "__len__") else "")
items = list(rr.items())[:3] if isinstance(rr, dict) else rr[:3]
print(json.dumps(items, indent=1)[:3500])
