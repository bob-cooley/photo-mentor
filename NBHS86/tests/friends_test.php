<?php
// php tests/friends_test.php - NBHS-friends numbering for phone-named videos (needs ffmpeg; uses a throwaway data dir)
$tmp = sys_get_temp_dir() . '/nbhs86-friends-' . bin2hex(random_bytes(4)); mkdir($tmp, 0700);
putenv("NBHS86_DATA_DIR=$tmp");
require_once __DIR__ . '/../lib/ingest.php';
$fail = 0;
function check(string $label, $got, $want): void { global $fail; if ($got !== $want) { $fail++; echo "FAIL $label\n  got:  " . json_encode($got) . "\n  want: " . json_encode($want) . "\n"; } }
$j = nb_data_dir() . '/jobs'; @mkdir($j, 0700, true); $db = nb_db();
$user = ['uploader' => 'Pat Doe', 'anonymous' => 0, 'batch' => 't']; $admin = ['uploader' => '', 'anonymous' => 0, 'batch' => 's', 'slideshow' => 1];
function vid(string $n, int $c): string { $p = nb_data_dir() . "/jobs/src-$n.mp4"; exec('ffmpeg -v error -y -f lavfi -i color=c=0x' . sprintf('%06x', $c * 91117 % 0xFFFFFF) . ':s=64x64:d=1 -pix_fmt yuv420p ' . escapeshellarg($p)); return $p; }
function row(string $id): array { $st = nb_db()->prepare('SELECT * FROM media WHERE id = ?'); $st->execute([$id]); return $st->fetch(); }
$name = fn($r) => nb_download_name(row($r['id']));

$a = nb_store_file(vid('a', 1), 'IMG_1234.MOV', $user);
$b = nb_store_file(vid('b', 2), 'PXL_20200820_141005222.mp4', $user);
$c = nb_store_file(vid('c', 3), 'MTV First Four Hours Remastered-01-Original Broadcast-12am-Saturday-August-1st-1981.mp4', $user);
$d = nb_store_file(vid('d', 4), 'VID-20181228-WA0001.mp4', $user);
check('iPhone video -> NBHS-friends_0001.mov', $name($a), 'NBHS-friends_0001.mov');
check('Pixel video -> NBHS-friends_0002.mp4', $name($b), 'NBHS-friends_0002.mp4');
check('custom-named video keeps its name, spaces as dashes', $name($c), 'MTV-First-Four-Hours-Remastered-01-Original-Broadcast-12am-Saturday-August-1st-1981.mp4');
check('custom-named video takes no friends number', row($c['id'])['friends_seq'], null);
check('WhatsApp video is the next friends number', $name($d), 'NBHS-friends_0003.mp4');
check('videos are still in the Videos folder', row($a['id'])['album'], 'videos');
check('original name is stored untouched', row($a['id'])['orig_name'], 'IMG_1234.MOV');

// photos and slideshows are unaffected by the friends series
$im = imagecreatetruecolor(20, 20); imagefill($im, 0, 0, imagecolorallocate($im, 5, 90, 190)); imagejpeg($im, "$j/p.jpg");
$p = nb_store_file("$j/p.jpg", 'IMG_1234.JPG', $user);
check('a photo called IMG_1234.JPG is a numbered photo', $name($p), 'NBHS_reunions_0001.jpg');
$s = nb_store_file(vid('s', 5), 'IMG_5555.MOV', $admin);
check('an admin slideshow keeps its own name (phone-name pattern is ignored for slideshows)', $name($s), 'IMG_5555.MOV');
check('...and takes no friends number', row($s['id'])['friends_seq'], null);

// duplicates and rejects never burn a number; deleting never frees one
$dup = nb_store_file(vid('a2', 1), 'IMG_9999.MOV', $user);
check('same bytes again is a duplicate', $dup['status'], 'duplicate');
file_put_contents("$j/fake.mp4", '<?php echo 1;');
check('fake video is rejected', nb_store_file("$j/fake.mp4", 'VID_20200101_000000.mp4', $user)['status'], 'rejected');
$e = nb_store_file(vid('e', 6), 'IMG_0007.MOV', $user);
check('next friends number is 4 (nothing burned)', $name($e), 'NBHS-friends_0004.mov');
$db->prepare('DELETE FROM media WHERE id = ?')->execute([$e['id']]);
$f = nb_store_file(vid('f', 7), 'IMG_0008.MOV', $user);
check('a deleted number is never reused', $name($f), 'NBHS-friends_0005.mov');

// zip contents follow the same rules
$zip = new ZipArchive(); $zp = "$j/z.zip"; $zip->open($zp, ZipArchive::CREATE);
$zip->addFile(vid('z1', 8), 'IMG_7777.MOV'); $zip->addFile(vid('z2', 9), 'sub dir/Reunion Toast 1986.mp4'); $zip->close();
nb_ingest_upload($zp, ['filename' => 'pack.zip'] + $user);
$zn = $db->query("select orig_name, friends_seq from media where source like 'zip:pack.zip' order by orig_name")->fetchAll(PDO::FETCH_NUM);
check('videos unpacked from a zip are numbered by the same rule', $zn, [['IMG_7777.MOV', 6], ['Reunion Toast 1986.mp4', null]]);

// zip download names: unique, no spaces
$rows = $db->query("select * from media where kind='video' order by created_at, rowid")->fetchAll();
$names = array_values(nb_zip_names($rows));
check('all zip entry names are unique and space-free', [count($names) === count(array_unique(array_map('strtolower', $names))), preg_grep('/\s|%20/', $names)], [true, []]);

// reset clears the friends counter along with the others
$db->exec('DELETE FROM media'); $db->exec('DELETE FROM counters');
$g = nb_store_file(vid('g', 10), 'IMG_4321.MOV', $user);
check('after a reset the friends series restarts at 0001', $name($g), 'NBHS-friends_0001.mov');

// upgrade from schema v3: nothing already stored is renamed
$old = sys_get_temp_dir() . '/nbhs86-v3-' . bin2hex(random_bytes(4)); mkdir($old, 0700);
$pdo = new PDO('sqlite:' . $old . '/nbhs86.sqlite');
$pdo->exec("CREATE TABLE media (id TEXT PRIMARY KEY, folder TEXT NOT NULL, orig_name TEXT NOT NULL, ext TEXT NOT NULL, kind TEXT NOT NULL, size INTEGER NOT NULL, hash TEXT NOT NULL, uploader TEXT NOT NULL DEFAULT '', anonymous INTEGER NOT NULL DEFAULT 0, batch TEXT NOT NULL DEFAULT '', source TEXT NOT NULL DEFAULT '', created_at INTEGER NOT NULL, seq INTEGER, album TEXT, credit_override TEXT, taken_at INTEGER, w INTEGER, h INTEGER, slideshow_seq INTEGER)");
$pdo->exec("CREATE TABLE jobs (id TEXT PRIMARY KEY, path TEXT NOT NULL, orig_name TEXT NOT NULL DEFAULT '', uploader TEXT NOT NULL DEFAULT '', anonymous INTEGER NOT NULL DEFAULT 0, batch TEXT NOT NULL DEFAULT '', folder TEXT NOT NULL, next_index INTEGER NOT NULL DEFAULT 0, added INTEGER NOT NULL DEFAULT 0, skipped INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT 'pending', created_at INTEGER NOT NULL, slideshow INTEGER NOT NULL DEFAULT 0)");
$pdo->exec("CREATE TABLE settings (k TEXT PRIMARY KEY, v TEXT NOT NULL)"); $pdo->exec("CREATE TABLE folders (slug TEXT PRIMARY KEY, title TEXT NOT NULL, icon TEXT NOT NULL DEFAULT 'grid', sort INTEGER NOT NULL DEFAULT 0, description TEXT NOT NULL DEFAULT '')");
$pdo->exec("CREATE TABLE counters (kind TEXT PRIMARY KEY, next INTEGER NOT NULL)");
$pdo->exec("INSERT INTO media (id,folder,album,orig_name,ext,kind,size,hash,created_at,seq) VALUES ('aaaaaaaaaaaa','classmate-uploads','videos','IMG_1001.MOV','mov','video',1,'h1',1,1)");
$pdo->exec('PRAGMA user_version = 3'); unset($pdo);
$probe = 'echo json_encode(["v"=>(int)nb_db()->query("PRAGMA user_version")->fetchColumn(),"col"=>in_array("friends_seq",array_column(nb_db()->query("PRAGMA table_info(media)")->fetchAll(),"name"),true),"name"=>nb_download_name(nb_db()->query("SELECT * FROM media")->fetch()),"snap"=>count(glob(nb_data_dir()."/backups/nbhs86-pre-*.sqlite"))]);';
$php = trim((string) shell_exec('command -v php')) ?: 'php';
$out = shell_exec('NBHS86_DATA_DIR=' . escapeshellarg($old) . ' ' . escapeshellarg($php) . ' -r ' . escapeshellarg('require ' . var_export(__DIR__ . '/../lib/ingest.php', true) . ';' . $probe));
$u = json_decode((string) $out, true) ?? [];
check('v3 -> v4: column added, version bumped, snapshot taken', [$u['v'] ?? null, $u['col'] ?? null, $u['snap'] ?? null], [NB_SCHEMA_VERSION, true, 1]);
check('an existing video is NOT renamed by the upgrade', $u['name'] ?? null, 'IMG_1001.MOV');

echo $fail ? "$fail failure(s)\n" : "all friends-naming tests passed\n";
exec('rm -rf ' . escapeshellarg($tmp) . ' ' . escapeshellarg($old));
exit($fail ? 1 : 0);
