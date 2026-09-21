<?php
// php tests/thumbs_test.php - thumbnails are created at upload time, atomically (needs GD and ffmpeg)
$tmp = sys_get_temp_dir() . '/nbhs86-thumbs-' . bin2hex(random_bytes(4)); mkdir($tmp);
putenv("NBHS86_DATA_DIR=$tmp");
require __DIR__ . '/../lib/ingest.php';
$fail = 0;
function check(string $label, $got, $want): void { global $fail; if ($got !== $want) { $fail++; echo "FAIL $label\n  got:  " . json_encode($got) . "\n  want: " . json_encode($want) . "\n"; } }
$j = nb_data_dir() . '/jobs'; $m = ['uploader' => 'A', 'anonymous' => 0, 'batch' => 't'];
$th = fn($id) => nb_data_dir() . "/thumbs/$id.jpg";

$im = imagecreatetruecolor(2400, 1600); imagefill($im, 0, 0, imagecolorallocate($im, 20, 120, 220)); imagejpeg($im, "$j/a.jpg");
$a = nb_store_file("$j/a.jpg", 'a.jpg', $m);
check('photo thumbnail exists straight after upload', is_file($th($a['id'])), true);
$info = getimagesize($th($a['id']));
check('thumbnail is a JPEG no larger than 480 px', [$info[2], max($info[0], $info[1]) <= 480], [IMAGETYPE_JPEG, true]);

exec('ffmpeg -v error -y -f lavfi -i testsrc2=size=640x360:rate=24 -t 2 -pix_fmt yuv420p ' . escapeshellarg("$j/v.mp4"));
$v = nb_store_file("$j/v.mp4", 'v.mp4', $m);
check('video poster exists straight after upload', is_file($th($v['id'])) && filesize($th($v['id'])) > 0, true);

// a corrupt image must not fail the upload
file_put_contents("$j/bad.jpg", substr(file_get_contents(nb_data_dir() . '/media/classmate-uploads/' . $a['id'] . '.jpg'), 0, 300) . str_repeat("\0", 50));
$bad = nb_store_file("$j/bad.jpg", 'bad.jpg', $m);
check('corrupt image is still accepted (thumbnail is best effort)', $bad['status'], 'added');

// no half-written temp files anywhere
check('no .part files left behind', count(glob(nb_data_dir() . '/thumbs/*.part.jpg') ?: []) + count(glob(nb_data_dir() . '/derived/*part*') ?: []), 0);

// eight requests building the same missing thumbnail at once: every one gets a complete file, no debris
unlink($th($a['id']));
$boot = var_export(realpath(__DIR__ . '/../lib/ingest.php'), true);
$id = $a['id'];
$code = "require $boot; \$r = nb_db()->query(\"select * from media where id='$id'\")->fetch(); \$p = nb_thumb(\$r); echo \$p && getimagesize(\$p) ? 'ok' : 'BAD';";
$procs = [];
for ($i = 0; $i < 8; $i++) { $procs[$i] = proc_open(['php', '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp[$i], null, ['NBHS86_DATA_DIR' => $tmp]); }
$res = []; foreach ($procs as $i => $pr) { $res[] = trim(stream_get_contents($pp[$i][1])); proc_close($pr); }
check('all 8 simultaneous requests got a valid thumbnail', array_unique($res), ['ok']);
check('final thumbnail is complete', (bool) getimagesize($th($a['id'])), true);
check('no temp files after the race', count(glob(nb_data_dir() . '/thumbs/*.part.jpg') ?: []), 0);

// videos made by zip extraction get thumbnails too
$zip = new ZipArchive(); $zp = "$j/z.zip"; $zip->open($zp, ZipArchive::CREATE);
$im = imagecreatetruecolor(300, 200); imagefill($im, 0, 0, imagecolorallocate($im, 220, 60, 20)); imagejpeg($im, "$j/zin.jpg"); $zip->addFile("$j/zin.jpg", 'zin.jpg'); $zip->close();
nb_ingest_upload($zp, ['filename' => 'z.zip', 'uploader' => 'A', 'anonymous' => 0, 'batch' => 'z']);
$zid = nb_db()->query("select id from media where orig_name='zin.jpg'")->fetchColumn();
check('a photo unpacked from a zip has its thumbnail', $zid && is_file($th($zid)), true);

echo $fail ? "$fail failure(s)\n" : "all thumbnail tests passed\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
