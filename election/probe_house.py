#!/usr/bin/env python3
"""Diagnostic: how many House districts per state page, and how many marked won."""
import re, sys
sys.path.insert(0, "election")
import fetch
STATES = ["Alabama","Alaska","Arizona","Arkansas","California","Colorado","Connecticut","Delaware","Florida","Georgia","Hawaii","Idaho","Illinois","Indiana","Iowa","Kansas","Kentucky","Louisiana","Maine","Maryland","Massachusetts","Michigan","Minnesota","Mississippi","Missouri","Montana","Nebraska","Nevada","New Hampshire","New Jersey","New Mexico","New York","North Carolina","North Dakota","Ohio","Oklahoma","Oregon","Pennsylvania","Rhode Island","South Carolina","South Dakota","Tennessee","Texas","Utah","Vermont","Virginia","Washington","West Virginia","Wisconsin","Wyoming"]
tot = won = 0
for st in STATES:
    title = f"2026 United States House of Representatives election in {st}" if st in ("Alaska","Delaware","North Dakota","South Dakota","Vermont","Wyoming") else f"2026 United States House of Representatives elections in {st}"
    text, _ = fetch.get_wikitext(title)
    if not text:
        print(f"{st}: NO PAGE ({title})"); continue
    heads = [fetch.clean(h) for h in re.findall(r"^={2,4}\s*(.*?)\s*={2,4}\s*$", text, re.M)]
    dists = [h for h in heads if re.fullmatch(r"(?:district\s+\d+|\d+(?:st|nd|rd|th) district|at-large(?: district)?)", h, re.I)]
    w = len(re.findall(r"\{\{\s*Election box winning candidate", text, re.I))
    ib = fetch.from_infobox(text)
    tot += len(dists); won += w
    print(f"{st}: districts={len(dists)} winning_boxes={w} infobox={'yes' if ib else 'no'} {('called='+str(ib['called'])) if ib else ''}")
print("TOTAL districts", tot, "winning boxes", won)
