#!/usr/bin/env python3
"""Fail if any workflow uploads over plain (unencrypted) FTP.

Checks every .github/workflows/*.yml:
  - each SamKirkland/FTP-Deploy-Action step must set `protocol: ftps`
    (or ftps-legacy);
  - each curl command that talks to ftp:// must pass --ssl-reqd.
Stdlib only, so it runs on any runner. Exit 1 with a list on failure.
Usage: python3 ops/check-ftps.py [workflow files...]
"""
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
files = [Path(p) for p in sys.argv[1:]] or sorted((ROOT / ".github" / "workflows").glob("*.y*ml"))
problems = []

for f in files:
    lines = f.read_text().splitlines()
    # 1) FTP-Deploy-Action steps: look at the step's lines until the next step
    for i, line in enumerate(lines):
        if "SamKirkland/FTP-Deploy-Action" not in line:
            continue
        indent = len(line) - len(line.lstrip())
        block = []
        for nxt in lines[i + 1:]:
            stripped = nxt.lstrip()
            ind = len(nxt) - len(stripped)
            if stripped.startswith("- ") and ind < indent:
                break
            if stripped and ind < indent - 2:
                break
            block.append(stripped)
        if not any(re.match(r"protocol:\s*['\"]?ftps(-legacy)?['\"]?\s*$", b) for b in block):
            problems.append(f"{f.name}:{i + 1}: FTP-Deploy-Action without `protocol: ftps`")
    # 2) curl uploads: join backslash-continued lines into whole commands
    cmd, start = "", 0
    for i, line in enumerate(lines):
        if not cmd:
            start = i
        cmd += line.rstrip("\\").strip() + " "
        if line.rstrip().endswith("\\"):
            continue
        if re.search(r"\bcurl\b", cmd) and "ftp://" in cmd and "--ssl-reqd" not in cmd:
            problems.append(f"{f.name}:{start + 1}: curl to ftp:// without --ssl-reqd")
        cmd = ""

if problems:
    print("Plain-FTP uploads found (passwords would travel unencrypted):")
    print("\n".join("  " + p for p in problems))
    print("Fix: add `protocol: ftps` to FTP-Deploy-Action steps, `--ssl-reqd` to curl.")
    sys.exit(1)
print(f"FTPS check passed: {len(files)} workflow file(s), no plain-FTP uploads.")
