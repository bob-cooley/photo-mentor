<?php
// php tests/photo_hint_test.php - the "click to open in a lightbox" line shows only in the Photos folder
$fail = 0;
function check(string $label, $got, $want): void { global $fail; if ($got !== $want) { $fail++; echo "FAIL $label\n  got:  " . json_encode($got) . "\n  want: " . json_encode($want) . "\n"; } }

$gal = file_get_contents(__DIR__ . '/../gallery/index.php');
check('the hint text exists', str_contains($gal, 'Click on any photo to open it in a scrollable lightbox.'), true);
check('gated to the Photos folder only', (bool) preg_match("/if \(\\\$folder\['slug'\] === 'photos'\).*?Click on any photo/", $gal), true);

echo $fail ? "$fail failure(s)\n" : "all photo-hint tests passed\n";
exit($fail ? 1 : 0);
