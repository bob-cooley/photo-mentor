#!/usr/bin/env python3
"""Diagnostic: is civicAPI answering, and what does a race look like?"""
import json, time, urllib.request, urllib.parse
UA = "bobcooleyphoto-election-board/1.0 (https://bobcooleyphoto.com/election/)"
def get(u, timeout=60, tries=3):
    for i in range(tries):
        try:
            req = urllib.request.Request(u, headers={"User-Agent": UA, "Accept": "application/json"})
            t0 = time.time()
            with urllib.request.urlopen(req, timeout=timeout) as r:
                b = r.read().decode("utf-8", "replace")
                print(f"  ok {r.status} {u} in {time.time()-t0:.1f}s, {len(b)} bytes")
                return b
        except Exception as e:
            print(f"  try {i+1} {u}: {e}")
            time.sleep(5)
    return None
get("https://civicapi.org/api/v2/status", 30, 2)
b = get("https://civicapi.org/api/v2/race/87524")
print("RACE:", (b or "")[:2500])
qs = urllib.parse.urlencode({"country": "US", "province": "TX", "startDate": "2026-11-03", "endDate": "2026-11-03", "limit": "50"})
b = get("https://civicapi.org/api/v2/race/search?" + qs)
print("SEARCH:", (b or "")[:4000])
