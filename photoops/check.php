<?php
// Temporary capability check for the photoops inbox pipeline. Booleans only; removed after one read.
header('Content-Type: text/plain');
header('X-Robots-Tag: noindex');
$r = [];
$r['php'] = PHP_VERSION;
$r['sapi'] = PHP_SAPI;
foreach (['imap', 'openssl', 'curl', 'mbstring', 'iconv', 'pdo_sqlite', 'sqlite3', 'json', 'mailparse'] as $e) $r["ext_$e"] = extension_loaded($e) ? 'yes' : 'no';
$r['fn_stream_socket_client'] = function_exists('stream_socket_client') ? 'yes' : 'no';
$r['fn_shell_exec'] = function_exists('shell_exec') ? 'yes' : 'no';
$r['php_cli'] = trim((string) @shell_exec('ls /usr/local/bin/php 2>/dev/null'));
$r['crontab_bin'] = trim((string) @shell_exec('command -v crontab 2>/dev/null'));
foreach (['bobcooley.mail.pairserver.com', 'mail2.g23.pair.com'] as $h) {
  $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $h]]);
  $t = microtime(true);
  $s = @stream_socket_client("ssl://$h:993", $en, $es, 10, STREAM_CLIENT_CONNECT, $ctx);
  if ($s) { $g = fgets($s, 200); fclose($s); $r["imap_$h"] = 'ok, cert valid, greeting=' . (str_starts_with((string)$g, '* OK') ? 'OK' : 'unexpected') . sprintf(' (%.2fs)', microtime(true) - $t); }
  else $r["imap_$h"] = "fail: $es";
}
$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [CURLOPT_NOBODY => false, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{}', CURLOPT_HTTPHEADER => ['content-type: application/json']]);
curl_exec($ch);
$r['anthropic_api_http'] = curl_errno($ch) ? 'fail: ' . curl_error($ch) : (string) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$home = getenv('HOME') ?: dirname($_SERVER['DOCUMENT_ROOT'] ?? '', 2);
$r['home_writable'] = is_writable($home) ? 'yes' : 'no';
$r['max_execution_time'] = ini_get('max_execution_time');
foreach ($r as $k => $v) echo "$k: $v\n";
