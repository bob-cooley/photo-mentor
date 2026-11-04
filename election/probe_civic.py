#!/usr/bin/env python3
"""Diagnostic: civicAPI national summary races (House/Senate/Governor)."""
import json, time, urllib.request
UA = "bobcooleyphoto-election-board/1.0 (https://bobcooleyphoto.com/election/)"
def get(u, timeout=60, tries=4):
    for i in range(tries):
        try:
            req = urllib.request.Request(u, headers={"User-Agent": UA, "Accept": "application/json"})
            t0 = time.time()
            with urllib.request.urlopen(req, timeout=timeout) as r:
                b = r.read()
                print(f"  ok {u} {time.time()-t0:.1f}s {len(b)}B")
                return json.loads(b)
        except Exception as e:
            print(f"  try {i+1} {u}: {e}")
            time.sleep(5)
for rid in (87524, 87528, 87535):
    d = get(f"https://civicapi.org/api/v2/race/{rid}")
    if not d:
        continue
    top = {k: v for k, v in d.items() if not isinstance(v, (list, dict))}
    print(rid, json.dumps(top))
    print("  keys:", list(d.keys()))
    for c in d.get("candidates", [])[:4]:
        print("  cand:", {k: c.get(k) for k in ("name", "party", "votes", "percent", "seats", "winner")})
