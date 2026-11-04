#!/usr/bin/env python3
"""Diagnostic: civicAPI search parameters and a sample race payload."""
import json, re, time, urllib.request, urllib.parse
UA = "bobcooleyphoto-election-board/1.0 (https://bobcooleyphoto.com/election/)"
def get(u, timeout=60, tries=3):
    for i in range(tries):
        try:
            req = urllib.request.Request(u, headers={"User-Agent": UA, "Accept": "application/json"})
            t0 = time.time()
            with urllib.request.urlopen(req, timeout=timeout) as r:
                b = r.read().decode("utf-8", "replace")
                print(f"  ok {u} in {time.time()-t0:.1f}s")
                return b
        except Exception as e:
            print(f"  try {i+1} {u}: {e}")
            time.sleep(4)
    return None
js = get("https://civicapi.org/assets/index-BLrhAH3t.js")
for var in re.findall(r"(\w+)=`https://civicapi\.org/api/v2/race/search`", js or ""):
    for m in list(re.finditer(rf"\$\{{{var}\}}[^`]{{0,200}}", js))[:6]:
        print("USE", m.group(0)[:220])
    for m in list(re.finditer(rf"\b{var}\b[,)][^;]{{0,200}}", js))[:4]:
        print("USE2", m.group(0)[:220])
for m in list(re.finditer(r"race/search\?[^`'\"]{0,120}", js or ""))[:6]:
    print("QS", m.group(0))
for m in list(re.finditer(r"URLSearchParams\(\{[^}]{0,200}\}", js or ""))[:8]:
    print("USP", m.group(0))
b = get("https://civicapi.org/api/v2/race/87524")
print("RACE 87524:", (b or "")[:3000])
