#!/usr/bin/env bash
# Read-only: full root robots.txt, and whether the origin has its own robots.txt.
set -u
UA="Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15"
echo "== full public robots.txt"
curl -s -A "$UA" -w "\n[http %{http_code}]\n" "https://bobcooleyphoto.com/robots.txt" | sed -n '40,200p'
echo "== robots / htaccess files at the web root (FTPS)"
curl -s --ssl-reqd --user "$FTP_USER:$FTP_PASS" "ftp://$FTP_HOST/public_html/bobcooleyphoto/" | grep -iE "robots|\.htaccess|wp-config|index\.php" || echo "(none matched)"
echo "== origin robots.txt contents, if present"
curl -s --ssl-reqd --user "$FTP_USER:$FTP_PASS" "ftp://$FTP_HOST/public_html/bobcooleyphoto/robots.txt" || echo "(no robots.txt file at origin root)"
