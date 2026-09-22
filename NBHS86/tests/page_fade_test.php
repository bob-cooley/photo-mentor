<?php
// php tests/page_fade_test.php - fade overlay scoped to the two CTA buttons only
$fail = 0;
function check(string $label, $got, $want): void { global $fail; if ($got !== $want) { $fail++; echo "FAIL $label\n  got:  " . json_encode($got) . "\n  want: " . json_encode($want) . "\n"; } }
require __DIR__ . '/../lib/layout.php';

check('overlay markup', nb_page_fade(), '<div id="pageFade" class="page-fade" aria-hidden="true"></div>');

$css = file_get_contents(__DIR__ . '/../assets/css/site.css');
check('overlay starts opaque (no flash on load)', (bool) preg_match('/\.page-fade\s*\{[^}]*opacity:\s*1/', $css), true);
check('300ms transition', str_contains($css, 'transition: opacity 300ms ease;'), true);
check('reduced-motion respected', str_contains($css, 'prefers-reduced-motion: reduce'), true);

$js = file_get_contents(__DIR__ . '/../assets/js/page-fade.js');
check('only intercepts .cta-btn links', str_contains($js, "querySelectorAll('a.cta-btn')"), true);
check('ignores modifier-key/non-primary clicks (new-tab still works)', str_contains($js, 'e.metaKey || e.ctrlKey'), true);
check('300ms delay before navigating', str_contains($js, 'DURATION = 300'), true);
check('does not touch nb_nav, form submissions, or download/mailto links', (bool) preg_match('/topnav|addEventListener\(.submit.|mailto|selDownload/', $js), false);

$idx = file_get_contents(__DIR__ . '/../index.php');
$gal = file_get_contents(__DIR__ . '/../gallery/index.php');
check('index.php: overlay only in logged-in states, not the gate', str_contains($idx, "if (\$member): ?><?= nb_page_fade()"), true);
check('gallery/index.php: overlay present', str_contains($gal, 'nb_page_fade()'), true);
check('index.php: script loaded', str_contains($idx, 'page-fade.js'), true);
check('gallery/index.php: script loaded', str_contains($gal, 'page-fade.js'), true);
check('admin page: no fade overlay (out of scope)', str_contains(file_get_contents(__DIR__ . '/../admin/index.php'), 'page-fade'), false);

echo $fail ? "$fail failure(s)\n" : "all page-fade tests passed\n";
exit($fail ? 1 : 0);
