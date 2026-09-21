<?php
// php tests/names_test.php - what each kind of file is called everywhere (gallery, downloads, zips)
require_once __DIR__ . '/../lib/bootstrap.php';
$fail = 0;
function check(string $label, $got, $want): void { global $fail; if ($got !== $want) { $fail++; echo "FAIL $label\n  got:  " . json_encode($got) . "\n  want: " . json_encode($want) . "\n"; } }

// spaces -> dashes, nothing else touched
$cases = [
    'Reunion Video 1985.mp4' => 'Reunion-Video-1985.mp4',
    '  Homecoming  Slideshow  .MOV' => 'Homecoming-Slideshow.MOV',
    'Video - 1985.mp4' => 'Video-1985.mp4',
    'a--b.pdf' => 'a--b.pdf',
    'Yearbook 1986 (Class of 86).pdf' => 'Yearbook-1986-(Class-of-86).pdf',
    'IMG_1234.MOV' => 'IMG_1234.MOV',
    'v1.2 final cut.mp4' => 'v1.2-final-cut.mp4',
    "tab\there.pdf" => 'tab-here.pdf',
    "nbsp\u{00A0}name.pdf" => 'nbsp-name.pdf',
    "Caf\u{e9} Night.mp4" => "Caf\u{e9}-Night.mp4",
    'no extension here' => 'no-extension-here',
    'already-dashed_name.pdf' => 'already-dashed_name.pdf',
];
foreach ($cases as $in => $want) { check("dash: " . json_encode($in), nb_dash_spaces($in), $want); check("no space and no %20 in the result: " . json_encode($in), preg_match('/\s|%20/u', nb_dash_spaces($in)), 0); }

// names by kind
$img = ['kind' => 'image', 'ext' => 'jpeg', 'seq' => 12, 'slideshow_seq' => null, 'orig_name' => 'My Photo.jpeg'];
check('photo is numbered', nb_download_name($img), 'NBHS_reunions_0012.jpg');
check('HEIC photo downloads as jpg', nb_download_name(['kind' => 'image', 'ext' => 'heic', 'seq' => 7, 'orig_name' => 'x'], true), 'NBHS_reunions_0007.jpg');
$vid = ['kind' => 'video', 'ext' => 'mov', 'seq' => 5, 'slideshow_seq' => null, 'orig_name' => 'Class Trip 1986.MOV'];
check('classmate video keeps its own name even though it has a video number', nb_download_name($vid), 'Class-Trip-1986.MOV');
$slide = ['kind' => 'video', 'ext' => 'mp4', 'seq' => null, 'slideshow_seq' => 3, 'orig_name' => 'Homecoming Slideshow.mp4'];
check('slideshow is numbered', nb_download_name($slide), 'NBHS_slideshow_0003.mp4');
check('a slideshow that also has an old video number is still a slideshow', nb_download_name($slide + ['seq' => 9]), 'NBHS_slideshow_0003.mp4');
check('pdf keeps its own name', nb_download_name(['kind' => 'pdf', 'ext' => 'pdf', 'seq' => null, 'orig_name' => 'Yearbook 1986 Full Scan.pdf']), 'Yearbook-1986-Full-Scan.pdf');
check('unnumbered photo falls back to its own name', nb_download_name(['kind' => 'image', 'ext' => 'png', 'seq' => null, 'orig_name' => 'Old Scan 3.png']), 'Old-Scan-3.png');
check('the stored original name is not modified', $vid['orig_name'], 'Class Trip 1986.MOV');

echo $fail ? "$fail failure(s)\n" : "all naming tests passed\n";
exit($fail ? 1 : 0);
