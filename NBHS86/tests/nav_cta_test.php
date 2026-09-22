<?php
// php tests/nav_cta_test.php - single-button nav/CTA, folder count labels, admin-only corner nav wiring
$fail = 0;
function check(string $label, $got, $want): void { global $fail; if ($got !== $want) { $fail++; echo "FAIL $label\n  got:  " . json_encode($got) . "\n  want: " . json_encode($want) . "\n"; } }
require __DIR__ . '/../lib/layout.php';

check('gallery CTA text', str_contains(nb_gallery_cta(), 'Share your Photos and Videos!'), true);
check('gallery CTA links to the upload page', str_contains(nb_gallery_cta(), 'href="' . NB_BASE . '/"'), true);
check('upload CTA text', str_contains(nb_upload_cta(), 'Back to Galleries'), true);
check('upload CTA links to the gallery', str_contains(nb_upload_cta(), 'href="' . NB_BASE . '/gallery/"'), true);

check('photos folder: single word, plural', nb_folder_count_label('photos', ['image' => 71]), '71 photos');
check('videos folder: single word, plural', nb_folder_count_label('videos', ['video' => 10]), '10 videos');
check('documents folder: single word, singular', nb_folder_count_label('documents', ['pdf' => 1]), '1 document');
check('slideshows folder: its own word even though the kind is video', nb_folder_count_label('slideshows', ['video' => 2]), '2 slideshows');
check('known folder with nothing in it yet', nb_folder_count_label('photos', []), '0 photos');
check('custom folder: real breakdown, kind order image/video/pdf', nb_folder_count_label('old-yearbook-scans', ['pdf' => 2, 'image' => 5]), '5 photos, 2 documents');
check('custom folder with one kind only, singular', nb_folder_count_label('old-yearbook-scans', ['video' => 1]), '1 video');
check('custom folder with nothing in it', nb_folder_count_label('old-yearbook-scans', []), '0 items');

$src = ['index.php' => file_get_contents(__DIR__ . '/../index.php'), 'gallery/index.php' => file_get_contents(__DIR__ . '/../gallery/index.php')];
foreach ($src as $file => $s) {
    check("$file: nb_nav() only rendered for admin", str_contains($s, "if (nb_is_admin()): ?><?= nb_nav("), true);
}
check('index.php: header image kept only on the gate (one call, not the two logged-in states)', substr_count($src['index.php'], 'nb_header_image()'), 1);
check('index.php: body carries upload-page only when a member', str_contains($src['index.php'], "\$member ? ' class=\"upload-page\"' : ''"), true);
check('gallery/index.php: header image still shown (this is the homepage)', substr_count($src['gallery/index.php'], 'nb_header_image()'), 1);
check('gallery/index.php: gallery CTA present', str_contains($src['gallery/index.php'], 'nb_gallery_cta()'), true);
check('index.php: upload CTA present twice (closed and open states)', substr_count($src['index.php'], 'nb_upload_cta()'), 2);

echo $fail ? "$fail failure(s)\n" : "all nav/CTA tests passed\n";
exit($fail ? 1 : 0);
