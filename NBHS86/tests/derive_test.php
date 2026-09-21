<?php
// php tests/derive_test.php   (needs GD, exiftool, ffmpeg; uses a throwaway data dir)
$tmp = sys_get_temp_dir() . '/nbhs86-derive-' . bin2hex(random_bytes(4));
mkdir($tmp);
putenv("NBHS86_DATA_DIR=$tmp");
require __DIR__ . '/../lib/ingest.php';

$fail = 0;
function check(string $label, $got, $want): void
{
    global $fail;
    if ($got !== $want) {
        $fail++;
        echo "FAIL $label\n  got:  " . json_encode($got) . "\n  want: " . json_encode($want) . "\n";
    }
}
function row(string $id): array
{
    $st = nb_db()->prepare('SELECT * FROM media WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch();
}
function jpg(string $path, int $w, int $h, int $seed): void
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, $seed * 37 % 255, $seed * 91 % 255, $seed * 13 % 255));
    imagejpeg($im, $path);
}
$jobs = nb_data_dir() . '/jobs';

// GPS in a JPEG is stripped from the public copy; the original keeps it (admin only)
jpg("$jobs/gps.jpg", 200, 100, 1);
exec('exiftool -q -overwrite_original -GPSLatitude=40.7128 -GPSLatitudeRef=N -GPSLongitude=74.0060 -GPSLongitudeRef=W -DateTimeOriginal="2019:07:04 18:30:00" -Orientation#=6 ' . escapeshellarg("$jobs/gps.jpg"));
check('setup: source has GPS', nb_has_gps("$jobs/gps.jpg"), true);
$r = nb_store_file("$jobs/gps.jpg", 'IMG_9.jpg', ['uploader' => 'A', 'anonymous' => 0, 'batch' => 't']);
$row = row($r['id']);
check('meta: taken_at from EXIF', $row['taken_at'], strtotime('2019-07-04 18:30:00'));
check('meta: orientation 6 swaps dims (200x100 -> 100x200)', [$row['w'], $row['h']], [100, 200]);
check('original still has GPS', nb_has_gps(nb_src_path($row)), true);
$v = nb_variant($row, 'download', false);
check('not created when create=false', $v, null);
$v = nb_variant($row, 'download');
check('download variant is a different file', $v['path'] !== nb_src_path($row), true);
check('download variant has no GPS', nb_has_gps($v['path']), false);
check('shot date survives GPS strip', trim(shell_exec('exiftool -s3 -DateTimeOriginal ' . escapeshellarg($v['path']))), '2019:07:04 18:30:00');
check('view variant is the same clean file', nb_variant($row, 'view')['path'], $v['path']);

// A photo without GPS is served straight from the original (no duplicate copy)
jpg("$jobs/nogps.jpg", 80, 60, 2);
$r2 = nb_store_file("$jobs/nogps.jpg", 'IMG_10.jpg', ['uploader' => 'A', 'anonymous' => 0, 'batch' => 't']);
$row2 = row($r2['id']);
check('no-GPS photo served from original', nb_variant($row2, 'download')['path'], nb_src_path($row2));
check('shot date missing -> null', $row2['taken_at'], null);

// Video with GPS (QuickTime keys) is stripped too
exec('ffmpeg -v error -y -f lavfi -i color=c=blue:s=64x64:d=1 -pix_fmt yuv420p ' . escapeshellarg("$jobs/v.mp4"));
exec('exiftool -q -overwrite_original "-Keys:GPSCoordinates=+40.7128-074.0060/" ' . escapeshellarg("$jobs/v.mp4"));
$hadGps = nb_has_gps("$jobs/v.mp4");
$r3 = nb_store_file("$jobs/v.mp4", 'clip.mp4', ['uploader' => 'A', 'anonymous' => 0, 'batch' => 't']);
$row3 = row($r3['id']);
$vv = nb_variant($row3, 'view');
check('video setup had GPS', $hadGps, true);
check('video public copy has no GPS', nb_has_gps($vv['path']), false);
check('video public copy still plays (ffprobe ok)', (int) shell_exec('ffprobe -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($vv['path']) . ' >/dev/null 2>&1; echo $?'), 0);

// Zip entry names: NBHS names, documents de-duplicated
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
file_put_contents("$jobs/a.pdf", $pdf . "%a");
file_put_contents("$jobs/b.pdf", $pdf . "%b");
$p1 = nb_store_file("$jobs/a.pdf", 'Yearbook.pdf', ['uploader' => 'A']);
$p2 = nb_store_file("$jobs/b.pdf", 'Yearbook.pdf', ['uploader' => 'B']);
$names = nb_zip_names([$row, $row2, $row3, row($p1['id']), row($p2['id'])]);
check('zip names', array_values($names), [
    'NBHS_reunions_0001.jpg', 'NBHS_reunions_0002.jpg', 'clip.mp4', 'Yearbook.pdf', 'Yearbook-2.pdf',
]);
check('heic entry is named .jpg', nb_zip_names([['id' => 'x', 'kind' => 'image', 'ext' => 'heic', 'seq' => 9, 'orig_name' => 'a']])['x'], 'NBHS_reunions_0009.jpg');

echo $fail ? "$fail failure(s)\n" : "all derive tests passed\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
