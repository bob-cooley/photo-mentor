#!/usr/bin/env python3
"""Diagnostic: page layout for California, at-large states, and box titles."""
import re, sys
sys.path.insert(0, "election")
import fetch

def show(title, n=40):
    text, _ = fetch.get_wikitext(title)
    print(f"##### {title}: {'MISSING' if not text else len(text)}")
    if not text:
        return
    heads = re.findall(r"^(={2,5})\s*(.*?)\s*\1\s*$", text, re.M)
    print("headings:", [f"{len(l)}:{fetch.clean(h)}" for l, h in heads][:n])
    for m in list(re.finditer(r"\{\{\s*Election box begin[^\n]*", text, re.I))[:12]:
        print("  box:", m.group(0)[:150])
    print("  infobox:", bool(fetch.find_template(text, r"Infobox[ _]election")))
    tbl = re.findall(r"^\{\|.*$", text, re.M)
    print("  tables:", len(tbl))

show("2026 United States House of Representatives elections in California", 70)
show("2026 United States House of Representatives election in Alaska")
show("2026 United States House of Representatives election in Wyoming")
t, _ = fetch.get_wikitext("2026 United States House of Representatives elections in New York")
sec = fetch.district_section(t, 10)
print("##### NY-10 boxes:")
for m in re.finditer(r"\{\{\s*Election box begin[^\n]*", sec, re.I):
    print("  box:", m.group(0)[:150])
t, _ = fetch.get_wikitext("2026 United States House of Representatives elections in Texas")
for d in (1, 15, 28):
    sec = fetch.district_section(t, d) or ""
    print(f"##### TX-{d} boxes:")
    for m in re.finditer(r"\{\{\s*Election box begin[^\n]*", sec, re.I):
        print("  box:", m.group(0)[:150])
    print("  winning:", len(re.findall(r"Election box winning", sec)))
