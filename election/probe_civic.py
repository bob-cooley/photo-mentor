#!/usr/bin/env python3
"""Diagnostic: read civicAPI docs and try its race search."""
import json, re, urllib.request, urllib.parse
UA = "bobcooleyphoto-election-board/1.0 (https://bobcooleyphoto.com/election/)"
def get(u):
    req = urllib.request.Request(u, headers={"User-Agent": UA, "Accept": "*/*"})
    with urllib.request.urlopen(req, timeout=30) as r:
        return r.status, r.headers.get("content-type", ""), r.read().decode("utf-8", "replace")
for u in ["https://civicapi.org/api-documentation", "https://civicapi.org/faq"]:
    try:
        st, ct, body = get(u)
        text = re.sub(r"<script.*?</script>|<style.*?</style>", " ", body, flags=re.S)
        text = re.sub(r"<[^>]+>", " ", text)
        text = re.sub(r"\s+", " ", text)
        print(f"##### {u} {st} {ct} {len(body)}")
        print(text[:9000])
    except Exception as e:
        print("FAIL", u, e)
