#!/usr/bin/env bash
# Read-only security check of the live election page and its hosting folder.
# Needs FTP_HOST / FTP_USER / FTP_PASS in the environment (GitHub secrets).
set -u
SITE="https://bobcooleyphoto.com/election"
UA="Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15"
REMOTE="public_html/bobcooleyphoto/election"

echo "== response headers for the page, data and a script"
for p in "" "index.html" "data.json" "fx.js"; do
  echo "-- /election/$p"
  curl -s -A "$UA" -D - -o /dev/null "$SITE/$p" | grep -iE "^(HTTP|content-type|content-security-policy|x-content-type-options|x-frame-options|referrer-policy|strict-transport-security|permissions-policy|x-robots-tag|cache-control|server)" || true
done

echo "== plain http should redirect to https"
curl -s -A "$UA" -o /dev/null -w "%{http_code} -> %{redirect_url}\n" "http://bobcooleyphoto.com/election/"

echo "== files that must NOT be downloadable (expect 403/404)"
for p in fetch.py rss.py civic.py races.json overrides.json .htaccess .gitignore civic_house_cache.json \
         civic_senate_cache.json probe_civic.py security_probe.sh ../.git/config ../.github/workflows/election.yml; do
  printf "%-40s %s\n" "$p" "$(curl -s -A "$UA" -o /dev/null -w '%{http_code}' "$SITE/$p")"
done

echo "== directory listing (expect no index page served from a bare folder)"
printf "%-40s %s\n" "/election/?C=M;O=D" "$(curl -s -A "$UA" "$SITE/?C=M;O=D" | grep -ci 'index of' || true) 'index of' matches"

echo "== root robots.txt"
curl -s -A "$UA" -w "\n[http %{http_code}]\n" "https://bobcooleyphoto.com/robots.txt" | head -40

echo "== hosting folder listing with permissions (plain FTP, as the deploy uses today)"
curl -s --user "$FTP_USER:$FTP_PASS" "ftp://$FTP_HOST/$REMOTE/" || echo "listing failed"
echo "-- parent folder entry"
curl -s --user "$FTP_USER:$FTP_PASS" "ftp://$FTP_HOST/public_html/bobcooleyphoto/" | grep -E " election$| photo-mentor$| bobboTrade$" || true

echo "== does the host accept encrypted FTP (FTPS)?"
if curl -s --ssl-reqd --user "$FTP_USER:$FTP_PASS" "ftp://$FTP_HOST/$REMOTE/" -o /dev/null; then
  echo "FTPS: yes (certificate verified)"
elif curl -s --ssl-reqd -k --user "$FTP_USER:$FTP_PASS" "ftp://$FTP_HOST/$REMOTE/" -o /dev/null; then
  echo "FTPS: yes, but the certificate does not match $FTP_HOST (works only with -k)"
else
  echo "FTPS: no"
fi
