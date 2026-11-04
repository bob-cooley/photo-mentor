#!/usr/bin/env python3
"""Diagnostic: show what each race page says about how much of the vote is in."""
import json, re, sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parent))
import fetch

cfg = json.loads((Path(__file__).resolve().parent / "races.json").read_text())
PHRASE = re.compile(r"[^.\n]{0,80}\b\d{1,3}(?:\.\d+)?\s*%[^.\n]{0,15}\b(?:reporting|counted|expected vote|precincts|of the vote in|of votes in|in\b)[^.\n]{0,60}", re.I)
for sec in cfg["sections"]:
    for r in sec["races"]:
        text, _ = fetch.get_wikitext(r["page"])
        print(f"== {r['id']}")
        if not text:
            print("   (no page)"); continue
        if sec["kind"] == "electionbox":
            text = fetch.district_section(text, r["district"]) or ""
        span = fetch.find_template(text, r"Infobox[ _]election")
        if span:
            p = fetch.template_params(text[span[0]:span[1]])
            for k, v in p.items():
                if re.search(r"report|turnout|count|precinct|expected|registered|votes_cast|as_of|update", k):
                    print(f"   infobox {k} = {fetch.clean(v)[:100]}")
        for m in list(PHRASE.finditer(text))[:4]:
            print("   text: " + fetch.clean(m.group(0))[:200])
