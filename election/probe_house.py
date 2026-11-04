#!/usr/bin/env python3
"""Diagnostic: how general-election boxes are titled on pages that did not parse."""
import re, sys
sys.path.insert(0, "election")
import fetch
for st in ["Illinois", "Massachusetts", "Tennessee", "Wisconsin", "Connecticut", "New Hampshire", "Missouri", "Minnesota", "Hawaii", "Rhode Island"]:
    t, _ = fetch.get_wikitext(f"2026 United States House of Representatives elections in {st}")
    print(f"##### {st}: {'MISSING' if not t else len(t)}")
    if not t:
        continue
    sec = fetch.district_section(t, 1) or ""
    print("  district 1 section chars:", len(sec))
    for m in re.finditer(r"\{\{\s*Election box begin[^}]{0,200}", sec, re.I):
        print("  box:", m.group(0).replace("\n", " ")[:200])
    for m in list(re.finditer(r"congressional district election", t))[:2]:
        print("  title-ish:", t[max(0, m.start()-120):m.end()].replace("\n", " "))
    print("  tables in d1:", len(re.findall(r"^\{\|", sec, re.M)), " cand tmpl:", len(re.findall(r"Election box (?:winning )?candidate", sec)))
