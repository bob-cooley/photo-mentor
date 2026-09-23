<?php
// php tests/lightbox_arrows_test.php - red text-symbol prev/next arrows, visible on touch too
$fail = 0;
function check(string $label, $got, $want): void { global $fail; if ($got !== $want) { $fail++; echo "FAIL $label\n  got:  " . json_encode($got) . "\n  want: " . json_encode($want) . "\n"; } }

$css = file_get_contents(__DIR__ . '/../assets/css/gallery.css');
check('touch devices no longer hide the arrows (PhotoSwipe default is mouse-only)', str_contains($css, ".pswp--touch .pswp__button.pswp__button--arrow { visibility: visible; }"), true);
check('the default SVG icon is hidden', str_contains($css, '.pswp__button.pswp__button--arrow .pswp__icn { display: none; }'), true);
check('left arrow is the plain triangle character (U+25C0)', str_contains($css, "content: '\\25C0'"), true);
check('right arrow is the plain triangle character (U+25B6)', str_contains($css, ".pswp__button--arrow--next::after { content: '\\25B6'; }"), true);
check('arrows are red', str_contains($css, 'color: #e5484d'), true);
check('every override has the extra .pswp__button qualifier (needed to beat PhotoSwipe\'s own stylesheet, loaded after)', substr_count($css, '.pswp__button.pswp__button--arrow'), 5);

echo $fail ? "$fail failure(s)\n" : "all lightbox-arrow tests passed\n";
exit($fail ? 1 : 0);
