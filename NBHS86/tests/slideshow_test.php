<?php
// php tests/slideshow_test.php   (needs GD and ffmpeg; uses a throwaway data dir)
$tmp = sys_get_temp_dir() . '/nbhs86-slides-' . bin2hex(random_bytes(4));
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
function mp4(string $name, int $n): string
{
    $p = nb_data_dir() . "/jobs/$name";
    exec('ffmpeg -v error -y -f lavfi -i color=c=0x' . sprintf('%06x', $n * 77777 % 0xFFFFFF) . ':s=64x64:d=1 -pix_fmt yuv420p ' . escapeshellarg($p));
    return $p;
}
function row(string $id): array
{
    $st = nb_db()->prepare('SELECT * FROM media WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch();
}
$db = nb_db();
$user = ['uploader' => 'Pat Doe', 'anonymous' => 0, 'batch' => 't'];
$admin = ['uploader' => '', 'anonymous' => 0, 'batch' => 's', 'slideshow' => 1];

// classmate video, two slideshows, another classmate video
$v1 = nb_store_file(mp4('v1.mp4', 1), 'v1.mp4', $user);
$s1 = nb_store_file(mp4('s1.mp4', 2), 'Reunion Slideshow.mp4', $admin);
$s2 = nb_store_file(mp4('s2.mov', 3), 'Yearbook Slideshow.mov', $admin);
$v2 = nb_store_file(mp4('v2.mp4', 4), 'v2.mp4', $user);
$r = fn($x) => row($x['id']);
check('classmate video 1', nb_download_name($r($v1)), 'NBHS_reunions_0001.mp4');
check('slideshow 1', nb_download_name($r($s1)), 'NBHS_slideshow_0001.mp4');
check('slideshow 2 keeps its own extension', nb_download_name($r($s2)), 'NBHS_slideshow_0002.mov');
check('classmate video 2 is not affected by slideshows', nb_download_name($r($v2)), 'NBHS_reunions_0002.mp4');
check('slideshow lands in Slideshows', $r($s1)['album'], 'slideshows');
check('slideshow takes no video number', $r($s1)['seq'], null);
check('slideshow credit', $r($s1)['credit_override'], 'Reunion Committee');
check('slideshow is not credited to an uploader', [$r($s1)['uploader'], $r($s1)['anonymous']], ['', 0]);
check('classmate video stays in Videos', $r($v1)['album'], 'videos');
check('classmate video has no credit override', $r($v1)['credit_override'], null);

// the flag does nothing for non-videos
jpg: {
    $im = imagecreatetruecolor(30, 30); imagefill($im, 0, 0, imagecolorallocate($im, 9, 99, 199)); imagejpeg($im, nb_data_dir() . '/jobs/p.jpg');
    $p = nb_store_file(nb_data_dir() . '/jobs/p.jpg', 'p.jpg', $admin);
    check('slideshow flag ignored for photos', [$r($p)['album'], $r($p)['seq'], $r($p)['slideshow_seq']], ['photos', 1, null]);
}

// duplicates and rejects never burn a slideshow number; deleting never frees one
$dup = nb_store_file(mp4('s1b.mp4', 2), 'again.mp4', $admin);
check('duplicate slideshow detected', $dup['status'], 'duplicate');
file_put_contents(nb_data_dir() . '/jobs/fake.mp4', '<?php echo 1;');
check('fake video rejected', nb_store_file(nb_data_dir() . '/jobs/fake.mp4', 'fake.mp4', $admin)['status'], 'rejected');
$s3 = nb_store_file(mp4('s3.mp4', 5), 's3.mp4', $admin);
check('next slideshow is 3 (nothing burned)', row($s3['id'])['slideshow_seq'], 3);
$db->prepare('DELETE FROM media WHERE id = ?')->execute([$s3['id']]);
$s4 = nb_store_file(mp4('s4.mp4', 6), 's4.mp4', $admin);
check('deleted slideshow number 3 is not reused', row($s4['id'])['slideshow_seq'], 4);

// moving between folders never changes a name
$before = nb_download_name($r($s1));
$db->prepare("UPDATE media SET album = 'videos' WHERE id = ?")->execute([$s1['id']]);
check('moving a slideshow to Videos keeps its name', nb_download_name($r($s1)), $before);
$vb = nb_download_name($r($v1));
$db->prepare("UPDATE media SET album = 'slideshows' WHERE id = ?")->execute([$v1['id']]);
check('moving a classmate video to Slideshows keeps its name', nb_download_name($r($v1)), $vb);

// the backfill must never hand a slideshow a video number
$db->exec("UPDATE media SET seq = NULL WHERE id = '{$s2['id']}'");
$db->exec("UPDATE media SET seq = NULL WHERE id = '{$v2['id']}'");   // a genuinely unnumbered classmate video
nb_backfill_seq($db);
check('backfill numbers the classmate video', row($v2['id'])['seq'] !== null, true);
check('backfill leaves slideshows alone', row($s2['id'])['seq'], null);

// zip names: slideshow and classmate names can never collide
$names = nb_zip_names([$r($v1), $r($s1), $r($s2), $r($v2)]);
check('zip names unique', count(array_unique(array_map('strtolower', $names))), 4);
check('backfilled classmate video got the next free number', row($v2['id'])['seq'], 3);
check('zip names', array_values($names), ['NBHS_reunions_0001.mp4', 'NBHS_slideshow_0001.mp4', 'NBHS_slideshow_0002.mov', 'NBHS_reunions_0003.mp4']);

// a zip uploaded by the admin: videos inside become slideshows, everything else follows the normal rules
$zip = new ZipArchive();
$zp = nb_data_dir() . '/jobs/s.zip';
$zip->open($zp, ZipArchive::CREATE);
$zip->addFile(mp4('z1.mp4', 7), 'sub/z1.mp4');
$zip->addFile(mp4('z2.mp4', 8), 'z2.mp4');
$zj = nb_data_dir() . '/jobs/zp.jpg';
$im2 = imagecreatetruecolor(30, 30); imagefill($im2, 0, 0, imagecolorallocate($im2, 200, 50, 10)); imagejpeg($im2, $zj);
$zip->addFile($zj, 'photo-in-zip.jpg');
$zip->close();
$res = nb_ingest_upload($zp, ['filename' => 'slides.zip', 'uploader' => '', 'anonymous' => 0, 'batch' => 'z', 'slideshow' => 1]);
check('zip accepted', $res['status'], 'zip');
$zs = $db->query("SELECT orig_name, album, slideshow_seq FROM media WHERE source LIKE 'zip:slides.zip' AND kind = 'video' ORDER BY slideshow_seq")->fetchAll(PDO::FETCH_NUM);
check('zip videos numbered as slideshows', $zs, [['z1.mp4', 'slideshows', 5], ['z2.mp4', 'slideshows', 6]]);
check('a photo inside an admin slideshow zip follows the normal rules', $db->query("SELECT album FROM media WHERE orig_name = 'photo-in-zip.jpg'")->fetchColumn(), 'photos');

// reset clears all three counters
$db->exec('DELETE FROM media');
$db->exec('DELETE FROM counters');
$sx = nb_store_file(mp4('r.mp4', 9), 'r.mp4', $admin);
check('after reset slideshows restart at 1', row($sx['id'])['slideshow_seq'], 1);

// upgrade from schema v2: run in a child process (the data dir and DB handle are cached per process)
$old = sys_get_temp_dir() . '/nbhs86-v2-' . bin2hex(random_bytes(4));
mkdir($old);
$pdo = new PDO('sqlite:' . $old . '/nbhs86.sqlite');
$pdo->exec("CREATE TABLE media (id TEXT PRIMARY KEY, folder TEXT NOT NULL, orig_name TEXT NOT NULL, ext TEXT NOT NULL, kind TEXT NOT NULL, size INTEGER NOT NULL, hash TEXT NOT NULL, uploader TEXT NOT NULL DEFAULT '', anonymous INTEGER NOT NULL DEFAULT 0, batch TEXT NOT NULL DEFAULT '', source TEXT NOT NULL DEFAULT '', created_at INTEGER NOT NULL, seq INTEGER, album TEXT, credit_override TEXT, taken_at INTEGER, w INTEGER, h INTEGER)");
$pdo->exec("CREATE TABLE jobs (id TEXT PRIMARY KEY, path TEXT NOT NULL, orig_name TEXT NOT NULL DEFAULT '', uploader TEXT NOT NULL DEFAULT '', anonymous INTEGER NOT NULL DEFAULT 0, batch TEXT NOT NULL DEFAULT '', folder TEXT NOT NULL, next_index INTEGER NOT NULL DEFAULT 0, added INTEGER NOT NULL DEFAULT 0, skipped INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT 'pending', created_at INTEGER NOT NULL)");
$pdo->exec("CREATE TABLE settings (k TEXT PRIMARY KEY, v TEXT NOT NULL)");
$pdo->exec("CREATE TABLE folders (slug TEXT PRIMARY KEY, title TEXT NOT NULL, icon TEXT NOT NULL DEFAULT 'grid', sort INTEGER NOT NULL DEFAULT 0, description TEXT NOT NULL DEFAULT '')");
$pdo->exec("CREATE TABLE counters (kind TEXT PRIMARY KEY, next INTEGER NOT NULL)");
$pdo->exec("INSERT INTO counters VALUES ('image', 3), ('video', 2)");
$pdo->exec("INSERT INTO media (id,folder,album,orig_name,ext,kind,size,hash,created_at,seq) VALUES ('aaaaaaaaaaaa','classmate-uploads','videos','a.mp4','mp4','video',1,'h1',1,1), ('bbbbbbbbbbbb','classmate-uploads','photos','b.jpg','jpg','image',1,'h2',2,2)");
$pdo->exec('PRAGMA user_version = 2');
unset($pdo);
$probe = '$c=fn($t)=>array_column(nb_db()->query("PRAGMA table_info($t)")->fetchAll(),"name");'
    . 'echo json_encode(["media"=>in_array("slideshow_seq",$c("media"),true),"jobs"=>in_array("slideshow",$c("jobs"),true),'
    . '"seq"=>nb_db()->query("SELECT id, seq FROM media ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR),'
    . '"name"=>nb_download_name(nb_db()->query("SELECT * FROM media WHERE id=\'aaaaaaaaaaaa\'")->fetch()),'
    . '"version"=>(int)nb_db()->query("PRAGMA user_version")->fetchColumn(),'
    . '"backup"=>count(glob(nb_data_dir()."/backups/*.sqlite"))]);';
$out = shell_exec('NBHS86_DATA_DIR=' . escapeshellarg($old) . ' php -r ' . escapeshellarg('require ' . var_export(__DIR__ . '/../lib/ingest.php', true) . ';' . $probe));
$u = json_decode((string) $out, true) ?? [];
check('v3 columns added', [$u['media'] ?? null, $u['jobs'] ?? null], [true, true]);
check('existing numbers untouched', $u['seq'] ?? null, ['aaaaaaaaaaaa' => 1, 'bbbbbbbbbbbb' => 2]);
check('existing names untouched', $u['name'] ?? null, 'NBHS_reunions_0001.mp4');
check('schema version', $u['version'] ?? null, NB_SCHEMA_VERSION);
check('database snapshot taken before upgrading', $u['backup'] ?? null, 1);

echo $fail ? "$fail failure(s)\n" : "all slideshow tests passed\n";
exec('rm -rf ' . escapeshellarg($tmp) . ' ' . escapeshellarg($old));
exit($fail ? 1 : 0);
