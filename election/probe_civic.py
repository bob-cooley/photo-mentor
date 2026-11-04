#!/usr/bin/env python3
"""Diagnostic: civicAPI House summary region_results layout."""
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
d = get("https://civicapi.org/api/v2/race/87524")
rr = d.get("region_results") or {}
keys = list(rr.keys())
print("regions:", len(keys), keys[:40])
for k in [x for x in keys if x.lower().startswith("co")][:8] + keys[:2]:
    v = rr[k]
    print(k, json.dumps({kk: vv for kk, vv in v.items() if kk != "candidates"}), [(c.get("name"), c.get("party"), c.get("votes"), c.get("percent"), c.get("winner")) for c in v.get("candidates", [])][:4])
