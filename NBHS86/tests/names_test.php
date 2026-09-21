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


// phone-default video names are recognised; everything else is not
$phone = ['IMG_1234.MOV', 'img_0007.mov', 'IMG_E1234.MOV', 'IMG_1234 (1).MOV', 'RPReplay_Final1605132612.MP4', 'PXL_20200820_141005222.mp4',
    'PXL_20200820_141005222.TS.mp4', 'VID_20091020_120000.3gp', 'VID_20240921_153045.mp4', 'video-2010-05-01-12-30-45.3gp',
    '20240917_203858.mp4', '20240917_203858_1.mp4', 'VID-20181228-WA0001.mp4', 'video_2021-03-05_16-18-57.mp4'];
$custom = ['MTV First Four Hours Remastered-01-Original Broadcast-12am-Saturday-August-1st-1981.mp4', 'Class Picnic Video 1986.mp4', 'IMG_123.mov',
    'IMG_1234 copy.MOV', 'My IMG_1234.mov', 'Reunion Slideshow 2026.mp4', '20240917.mp4', 'VID_final_cut.mp4', 'MVI_1234.MOV', 'DSC_0012.MOV', 'IMG_1234_final.mov'];
foreach ($phone as $n) { check("phone default recognised: $n", nb_is_phone_video_name($n), true); }
foreach ($custom as $n) { check("custom name left alone: $n", nb_is_phone_video_name($n), false); }

// naming of the three kinds of video
$row = ['kind' => 'video', 'ext' => 'MOV', 'seq' => 5, 'slideshow_seq' => null, 'friends_seq' => 12, 'orig_name' => 'IMG_1234.MOV'];
check('phone video is NBHS-friends, 4 digits, lowercase extension', nb_download_name($row), 'NBHS-friends_0012.mov');
check('a slideshow wins over a friends number', nb_download_name(['slideshow_seq' => 2] + $row), 'NBHS_slideshow_0002.MOV');
check('custom video with no friends number keeps its name', nb_download_name(['friends_seq' => null, 'orig_name' => 'MTV First Four Hours.mp4', 'ext' => 'mp4'] + $row), 'MTV-First-Four-Hours.mp4');

echo $fail ? "$fail failure(s)\n" : "all naming tests passed\n";
exit($fail ? 1 : 0);
