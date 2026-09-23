<?php
// php tests/mobile_zip_hint_test.php - device-detected unzip instructions under the Download button
$fail = 0;
function check(string $label, $got, $want): void { global $fail; if ($got !== $want) { $fail++; echo "FAIL $label\n  got:  " . json_encode($got) . "\n  want: " . json_encode($want) . "\n"; } }
require __DIR__ . '/../lib/layout.php';

function withUA(string $ua, callable $fn) {
    $prev = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $_SERVER['HTTP_USER_AGENT'] = $ua;
    $r = $fn();
    if ($prev === null) { unset($_SERVER['HTTP_USER_AGENT']); } else { $_SERVER['HTTP_USER_AGENT'] = $prev; }
    return $r;
}

$iphoneUA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
$androidUA = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36';
$desktopUA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

check('iPhone gets the Files-app instruction', withUA($iphoneUA, 'nb_mobile_zip_hint'), 'iPhone: the zip opens automatically in your Files app. Tap it, then Select All, Share, Save Images to add them all to Photos.');
check('Android gets the Extract instruction', withUA($androidUA, 'nb_mobile_zip_hint'), 'Android: open the zip in your Files app, tap Extract, then select the photos and save them to your gallery.');
check('desktop gets nothing', withUA($desktopUA, 'nb_mobile_zip_hint'), '');
check('no user agent at all gets nothing', (function () { unset($_SERVER['HTTP_USER_AGENT']); return nb_mobile_zip_hint(); })(), '');

$gal = file_get_contents(__DIR__ . '/../gallery/index.php');
check('button no longer says (.zip)', str_contains($gal, '(.zip)'), false);
check('button still says Download', str_contains($gal, 'Download <span class="hide-sm">selected</span>'), true);
check('the hint is wired into the selbar, right after the buttons', (bool) preg_match('#</span>\s*<\?php if \(\$zipHint#', $gal), true);

echo $fail ? "$fail failure(s)\n" : "all mobile-zip-hint tests passed\n";
exit($fail ? 1 : 0);
