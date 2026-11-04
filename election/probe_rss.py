#!/usr/bin/env python3
"""Diagnostic: which news RSS feeds respond, and what their headlines look like."""
import urllib.request, xml.etree.ElementTree as ET
UA = "bobcooleyphoto-election-board/1.0 (https://bobcooleyphoto.com/election/)"
FEEDS = {
    "nbc": "https://feeds.nbcnews.com/nbcnews/public/politics",
    "fox": "https://moxie.foxnews.com/google-publisher/politics.xml",
    "abc": "https://feeds.abcnews.com/abcnews/politicsheadlines",
    "cbs": "https://www.cbsnews.com/latest/rss/politics",
    "npr": "https://feeds.npr.org/1014/rss.xml",
    "nyt": "https://rss.nytimes.com/services/xml/rss/nyt/Politics.xml",
    "pbs": "https://www.pbs.org/newshour/feeds/rss/politics",
    "hill": "https://thehill.com/homenews/campaign/feed/",
    "cnn": "http://rss.cnn.com/rss/cnn_allpolitics.rss",
    "wapo": "https://feeds.washingtonpost.com/rss/politics",
    "politico": "https://rss.politico.com/politics-news.xml",
    "guardian": "https://www.theguardian.com/us-news/us-elections-2026/rss",
}
for k, u in FEEDS.items():
    try:
        req = urllib.request.Request(u, headers={"User-Agent": UA})
        with urllib.request.urlopen(req, timeout=20) as r:
            body = r.read()
        root = ET.fromstring(body)
        items = root.findall(".//item") or root.findall(".//{http://www.w3.org/2005/Atom}entry")
        print(f"== {k}: OK {len(items)} items")
        for it in items[:25]:
            t = it.findtext("title") or it.findtext("{http://www.w3.org/2005/Atom}title") or ""
            d = it.findtext("pubDate") or it.findtext("{http://www.w3.org/2005/Atom}updated") or ""
            print(f"   {d[:25]:25} {t.strip()[:140]}")
    except Exception as e:
        print(f"== {k}: FAIL {type(e).__name__}: {str(e)[:100]}")
