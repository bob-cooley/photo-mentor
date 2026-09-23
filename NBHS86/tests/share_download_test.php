<?php
// php tests/share_download_test.php - static checks for the single-file "share to Photos, else download" wiring
$fail = 0;
function check(string $label, $got, $want): void { global $fail; if ($got !== $want) { $fail++; echo "FAIL $label\n  got:  " . json_encode($got) . "\n  want: " . json_encode($want) . "\n"; } }

$js = file_get_contents(__DIR__ . '/../assets/js/gallery.js');
check('feature-detects navigator.share/canShare before ever trying it', str_contains($js, 'navigator.canShare && navigator.share'), true);
check('a size threshold exists so a large video is never fetched into memory first', (bool) preg_match('/SHARE_MAX_BYTES\s*=\s*25 \* 1024 \* 1024/', $js), true);
check('oversized files skip straight past the share attempt (plain link, unchanged)', str_contains($js, 'd.size <= SHARE_MAX_BYTES'), true);
check('any share failure falls back to the plain download link', (bool) preg_match('/if \(!shared\) window\.location\.href = d\.dl/', $js), true);
check('a thrown error (cancel, unsupported, network) is treated as "not shared", not a crash', (bool) preg_match('/catch \(e\) \{\s*return false;/', $js), true);
check('the lightbox data source carries size (needed for the threshold check)', str_contains($js, 'msrc: i.thumb, size: i.size'), true);
check('list.php already sends size per item (no extra request needed for the check)', str_contains(file_get_contents(__DIR__ . '/../api/list.php'), "'size' => (int) \$r['size']"), true);

$css = file_get_contents(__DIR__ . '/../assets/css/gallery.css');
check('a busy state exists so a slow fetch does not look broken', str_contains($css, 'pswp-dl-busy'), true);

echo $fail ? "$fail failure(s)\n" : "all share/download tests passed\n";
exit($fail ? 1 : 0);
