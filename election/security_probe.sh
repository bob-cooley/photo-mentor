#!/usr/bin/env bash
# Read-only: confirm robots.txt rule, response headers and folder contents after hardening.
set -u
UA="Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15"
SITE="https://bobcooleyphoto.com/election"
echo "== robots.txt tail"
curl -s -A "$UA" "https://bobcooleyphoto.com/robots.txt" | tail -6
echo "== headers on a file Cloudflare passes through (fx.js)"
curl -s -A "$UA" -D - -o /dev/null "$SITE/fx.js" | grep -iE "^(HTTP|x-robots-tag|x-frame-options|x-content-type-options|referrer-policy|content-security-policy|permissions-policy)" || true
echo "== a denied type (expect 403 from the origin rule)"
curl -s -A "$UA" -o /dev/null -w "us-atlas.LICENSE.txt %{http_code}\n" "$SITE/us-atlas.LICENSE.txt"
echo "== folder contents and permissions (FTPS)"
curl -s --ssl-reqd --user "$FTP_USER:$FTP_PASS" "ftp://$FTP_HOST/public_html/bobcooleyphoto/election/"
