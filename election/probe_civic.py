#!/usr/bin/env python3
"""Diagnostic: discover civicAPI v2 endpoints and sample responses."""
import json, re, urllib.request, urllib.parse
UA = "bobcooleyphoto-election-board/1.0 (https://bobcooleyphoto.com/election/)"
def get(u):
    req = urllib.request.Request(u, headers={"User-Agent": UA, "Accept": "*/*"})
    with urllib.request.urlopen(req, timeout=30) as r:
        return r.status, r.read().decode("utf-8", "replace")
st, html = get("https://civicapi.org/api-documentation")
paths = set(re.findall(r"/api/v2/[A-Za-z0-9_/{}:.\-]+", html))
srcs = re.findall(r'src="([^"]+\.js[^"]*)"', html)
for s in srcs[:40]:
    u = urllib.parse.urljoin("https://civicapi.org/", s)
    try:
        _, js = get(u)
        found = set(re.findall(r"/api/v2/[A-Za-z0-9_/{}:.\-]+", js))
        if found:
            print("JS", u, sorted(found)[:60])
            for m in list(re.finditer(r"(?:param|query|name)[\"']?\s*:\s*[\"']([a-z_]+)[\"']", js))[:0]:
                pass
            # print a window of doc text around 'race/search'
            for m in list(re.finditer(r"race/search", js))[:2]:
                print("CTX", js[max(0, m.start()-400):m.start()+1500].replace("\\n", " ")[:1900])
        paths |= found
    except Exception as e:
        print("jsfail", u, e)
print("PATHS", sorted(paths))
def tryj(u):
    try:
        st, body = get(u)
        print(f"##### {u} -> {st} {len(body)}")
        print(body[:2500])
    except Exception as e:
        print(f"##### {u} FAIL {e}")
tryj("https://civicapi.org/api/v2/status")
for q in ["query=Texas%20Senate", "q=Texas%20Senate", "state=TX&office=Senate", "search=Texas%20Senate"]:
    tryj("https://civicapi.org/api/v2/race/search?" + q)
