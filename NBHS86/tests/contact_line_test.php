<?php
// php tests/contact_line_test.php - the non-scrapable email line: helper output, no raw address in source, wiring
$fail = 0;
function check(string $label, $got, $want): void { global $fail; if ($got !== $want) { $fail++; echo "FAIL $label\n  got:  " . json_encode($got) . "\n  want: " . json_encode($want) . "\n"; } }
require __DIR__ . '/../lib/layout.php';

$line = nb_contact_line();
check('names the reason', str_contains($line, 'For questions or problems with the site, contact'), true);
check('split into two data attributes, not one string', $line, 'For questions or problems with the site, contact <a href="#" class="email-link" data-user="bob" data-domain="bobcooleyphoto.com"></a>.');
check('the raw address never appears in the helper output', str_contains($line, 'bob@bobcooleyphoto.com'), false);

$js = file_get_contents(__DIR__ . '/../assets/js/contact.js');
check('script assembles user + @ + domain at runtime', (bool) preg_match('/dataset\.user.*@.*dataset\.domain/', $js), true);
check('script sets href to mailto:', str_contains($js, "mailto:"), true);

foreach (['index.php' => ['gate' => true], 'gallery/index.php' => []] as $file => $unused) {
    $src = file_get_contents(__DIR__ . '/../' . $file);
    check("$file: raw address never appears", str_contains($src, 'bob@bobcooleyphoto.com'), false);
    check("$file: contact.js is loaded", str_contains($src, 'assets/js/contact.js'), true);
    check("$file: contact line appears (every branch reachable)", substr_count($src, 'nb_contact_line()') >= 2, true);
}
check('admin page is excluded', str_contains(file_get_contents(__DIR__ . '/../admin/index.php'), 'contact_line'), false);
check('admin page does not load contact.js', str_contains(file_get_contents(__DIR__ . '/../admin/index.php'), 'contact.js'), false);

$gal = file_get_contents(__DIR__ . '/../gallery/index.php');
check('folder view puts the contact line inside the floating selbar, not a separate footer', (bool) preg_match('/selbar-contact.*?nb_contact_line\(\).*?<\/div>\s*<\?php endif/s', $gal), true);

echo $fail ? "$fail failure(s)\n" : "all contact-line tests passed\n";
exit($fail ? 1 : 0);
