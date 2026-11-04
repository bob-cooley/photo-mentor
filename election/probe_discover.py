#!/usr/bin/env python3
"""Diagnostic: list every 2026 Senate and governor race page Wikipedia links to."""
import json, re, sys, urllib.parse, urllib.request
sys.path.insert(0, "election")
import fetch

def links(title):
    out, cont = [], {}
    while True:
        q = {"action": "query", "titles": title, "prop": "links", "pllimit": "max",
             "plnamespace": "0", "format": "json", "formatversion": "2", "redirects": "1", **cont}
        req = urllib.request.Request(fetch.API + "?" + urllib.parse.urlencode(q), headers={"User-Agent": fetch.UA})
        with urllib.request.urlopen(req, timeout=20) as r:
            body = json.load(r)
        for p in body["query"]["pages"]:
            out += [l["title"] for l in p.get("links", [])]
        if "continue" not in body:
            return out
        cont = body["continue"]

sen = sorted(t for t in links("2026 United States Senate elections")
             if re.fullmatch(r"2026 United States Senate (?:special )?election in [A-Z][A-Za-z ]+", t))
gov = sorted(t for t in links("2026 United States gubernatorial elections")
             if re.fullmatch(r"2026 [A-Z][A-Za-z ]+ gubernatorial election", t))
print(f"SENATE {len(sen)}"); [print("  " + t) for t in sen]
print(f"GOVERNOR {len(gov)}"); [print("  " + t) for t in gov]
