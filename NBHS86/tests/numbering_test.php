<?php
// php tests/numbering_test.php   (needs GD; uses a throwaway data dir)
$tmp = sys_get_temp_dir() . '/nbhs86-test-' . bin2hex(random_bytes(4));
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
function mkfile(string $ext, string $kind, int $n): string
{
    $p = nb_data_dir() . "/jobs/src-$n.$ext";
    if ($kind === 'jpg') {
        $im = imagecreatetruecolor(64, 64);
        imagefill($im, 0, 0, imagecolorallocate($im, $n * 7 % 255, $n * 31 % 255, $n * 53 % 255));
        imagejpeg($im, $p);
    } elseif ($kind === 'mp4') {
        exec('ffmpeg -v error -y -f lavfi -i color=c=0x' . sprintf('%06x', $n * 99991 % 0xFFFFFF) . ':s=64x64:d=1 -pix_fmt yuv420p ' . escapeshellarg($p));
    } elseif ($kind === 'pdf') {
        file_put_contents($p, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    }
    return $p;
}
function ingest(string $path, string $name): array
{
    return nb_store_file($path, $name, ['uploader' => 'Test', 'anonymous' => 0, 'batch' => 't']);
}
function row(string $id): array
{
    $st = nb_db()->prepare('SELECT * FROM media WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch();
}

// 1. numbering: images and videos count separately, PDFs are not numbered
$a = ingest(mkfile('jpg', 'jpg', 1), 'IMG_0001.jpg');
$b = ingest(mkfile('jpg', 'jpg', 2), 'IMG_0001.jpeg');      // same original name, different picture
$c = ingest(mkfile('jpg', 'jpg', 3), 'photo.png');           // wrong ext for bytes is fine: sniff says image
$v1 = ingest(mkfile('mp4', 'mp4', 4), 'clip.mov');
$v2 = ingest(mkfile('mp4', 'mp4', 5), 'clip.mp4');
$pdf = ingest(mkfile('pdf', 'pdf', 6), 'Yearbook 1986.pdf');
check('image 1', row($a['id'])['seq'], 1);
check('image 2', row($b['id'])['seq'], 2);
check('image 3', row($c['id'])['seq'], 3);
check('video 1 restarts', row($v1['id'])['seq'], 1);
check('video 2', row($v2['id'])['seq'], 2);
check('pdf unnumbered', row($pdf['id'])['seq'], null);

// 2. names
check('name jpg', nb_download_name(row($a['id'])), 'NBHS_reunions_0001.jpg');
check('name jpeg -> jpg', nb_download_name(row($b['id'])), 'NBHS_reunions_0002.jpg'); // ext is the stored one
check('classmate video keeps its own name', nb_download_name(row($v1['id'])), 'clip.mov');
check('classmate video 2 keeps its own name', nb_download_name(row($v2['id'])), 'clip.mp4');
check('pdf keeps its name, spaces as dashes', nb_download_name(row($pdf['id'])), 'Yearbook-1986.pdf');
check('heic as-is', nb_download_name(['kind' => 'image', 'ext' => 'heic', 'seq' => 7, 'orig_name' => 'x']), 'NBHS_reunions_0007.heic');
check('heic converted', nb_download_name(['kind' => 'image', 'ext' => 'heic', 'seq' => 7, 'orig_name' => 'x'], true), 'NBHS_reunions_0007.jpg');
check('5-digit rollover', nb_download_name(['kind' => 'image', 'ext' => 'jpg', 'seq' => 12345, 'orig_name' => 'x']), 'NBHS_reunions_12345.jpg');

// 3. duplicates and rejects do not consume numbers
$dup = ingest(mkfile('jpg', 'jpg', 1), 'again.jpg');
check('duplicate detected', $dup['status'], 'duplicate');
check('duplicate points at original', $dup['id'], $a['id']);
$bad = nb_store_file(($p = nb_data_dir() . '/jobs/fake.bin') && file_put_contents($p, '<?php echo 1;') ? $p : '', 'fake.jpg', []);
check('fake image rejected', $bad['status'], 'rejected');
$d = ingest(mkfile('jpg', 'jpg', 7), 'next.jpg');
check('next image is 4 (nothing burned)', row($d['id'])['seq'], 4);

// 4. deleting never frees a number
nb_db()->prepare('DELETE FROM media WHERE id = ?')->execute([$d['id']]);
$e = ingest(mkfile('jpg', 'jpg', 8), 'after-delete.jpg');
check('deleted number 4 is not reused', row($e['id'])['seq'], 5);

// 5. counters reset only by explicit action; then numbering restarts
nb_db()->exec('DELETE FROM media');
nb_db()->exec('DELETE FROM counters');
$f = ingest(mkfile('jpg', 'jpg', 9), 'fresh.jpg');
check('restart after reset', row($f['id'])['seq'], 1);

// 6. migration: an old-format database (no seq column) is numbered by upload order
$old = sys_get_temp_dir() . '/nbhs86-old-' . bin2hex(random_bytes(4));
mkdir($old);
$pdo = new PDO('sqlite:' . $old . '/nbhs86.sqlite');
$pdo->exec("CREATE TABLE media (id TEXT PRIMARY KEY, folder TEXT NOT NULL, orig_name TEXT NOT NULL, ext TEXT NOT NULL, kind TEXT NOT NULL, size INTEGER NOT NULL, hash TEXT NOT NULL, uploader TEXT NOT NULL DEFAULT '', anonymous INTEGER NOT NULL DEFAULT 0, batch TEXT NOT NULL DEFAULT '', source TEXT NOT NULL DEFAULT '', created_at INTEGER NOT NULL)");
$ins = $pdo->prepare("INSERT INTO media (id,folder,orig_name,ext,kind,size,hash,created_at) VALUES (?,?,?,?,?,?,?,?)");
foreach ([['c3', 'image', 300], ['a1', 'image', 100], ['v1', 'video', 150], ['b2', 'image', 200], ['p1', 'pdf', 50]] as [$id, $kind, $t]) {
    $ins->execute([$id, 'classmate-uploads', "$id.x", $kind === 'video' ? 'mp4' : ($kind === 'pdf' ? 'pdf' : 'jpg'), $kind, 1, $id, $t]);
}
unset($pdo);
$migrated = new PDO('sqlite:' . $old . '/nbhs86.sqlite');
$migrated->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$migrated->exec('ALTER TABLE media ADD COLUMN seq INTEGER');
$migrated->exec('CREATE TABLE IF NOT EXISTS counters (kind TEXT PRIMARY KEY, next INTEGER NOT NULL)');
nb_backfill_seq($migrated);
$got = $migrated->query("SELECT id, seq FROM media ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
check('backfill by upload time', $got, ['a1' => 1, 'b2' => 2, 'c3' => 3, 'p1' => null, 'v1' => 1]);
check('counters continue', nb_next_seq($migrated, 'image'), 4);

echo $fail ? "$fail failure(s)\n" : "all numbering tests passed\n";
exec('rm -rf ' . escapeshellarg($tmp) . ' ' . escapeshellarg($old));
exit($fail ? 1 : 0);
