<?php
// php tests/folders_test.php   (needs GD and ffmpeg; uses a throwaway data dir)
$tmp = sys_get_temp_dir() . '/nbhs86-folders-' . bin2hex(random_bytes(4));
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
$j = nb_data_dir() . '/jobs';
$m = ['uploader' => 'A', 'anonymous' => 0, 'batch' => 't'];
$db = nb_db();

// fresh database: the four folders, in order
check('folder slugs', array_column(nb_folders(), 'slug'), ['photos', 'videos', 'documents', 'slideshows']);
check('folder titles', array_column(nb_folders(), 'title'), ['Photos', 'Videos', 'Documents', 'Slideshows']);
check('folder icons', array_column(nb_folders(), 'icon'), ['camera', 'film', 'file-text', 'grid']);
check('user_version', (int) $db->query('PRAGMA user_version')->fetchColumn(), NB_SCHEMA_VERSION);

// uploads land in the folder for their type; physical storage dir is unchanged
$im = imagecreatetruecolor(40, 40); imagejpeg($im, "$j/a.jpg");
exec('ffmpeg -v error -y -f lavfi -i color=c=red:s=64x64:d=1 -pix_fmt yuv420p ' . escapeshellarg("$j/b.mp4"));
file_put_contents("$j/c.pdf", "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
$ids = [];
foreach (['a.jpg', 'b.mp4', 'c.pdf'] as $f) {
    $ids[$f] = nb_store_file("$j/$f", $f, $m)['id'];
}
$albums = $db->query('SELECT orig_name, album, folder FROM media ORDER BY created_at, rowid')->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
check('jpg -> photos', $albums['a.jpg']['album'], 'photos');
check('mp4 -> videos', $albums['b.mp4']['album'], 'videos');
check('pdf -> documents', $albums['c.pdf']['album'], 'documents');
check('physical dir stays classmate-uploads', $albums['a.jpg']['folder'], 'classmate-uploads');

// upgrade from schema v1 (old folder set, uploads sitting in "classmate-uploads")
$db->exec("UPDATE media SET album = 'classmate-uploads'");
$db->exec("UPDATE folders SET title = 'Videos/Slideshows' WHERE slug = 'videos'");
$db->exec("DELETE FROM folders WHERE slug = 'slideshows'");
$db->exec("INSERT INTO folders (slug, title, icon, sort) VALUES ('classmate-uploads', 'Classmate uploads', 'grid', 40)");
$db->exec("INSERT INTO media (id, folder, album, orig_name, ext, kind, size, hash, created_at) VALUES ('aaaaaaaaaaaa','classmate-uploads','slideshows','kept.mp4','mp4','video',1,'h1',1)");
$db->exec('PRAGMA user_version = 1');
nb_migrate($db);
check('after upgrade: folders', array_column(nb_folders(), 'slug'), ['photos', 'videos', 'documents', 'slideshows']);
check('after upgrade: Videos renamed', nb_folder('videos')['title'], 'Videos');
$moved = $db->query('SELECT orig_name, album FROM media ORDER BY orig_name')->fetchAll(PDO::FETCH_KEY_PAIR);
check('upgrade moves by type', [$moved['a.jpg'], $moved['b.mp4'], $moved['c.pdf']], ['photos', 'videos', 'documents']);
check('upgrade leaves other folders alone', $moved['kept.mp4'], 'slideshows');
check('backup snapshot written before migrating', count(glob(nb_data_dir() . '/backups/nbhs86-pre-v*.sqlite')) >= 1, true);

// a folder the admin renamed is not overwritten, and re-running is harmless
$db->exec("UPDATE folders SET title = 'Family Videos' WHERE slug = 'videos'");
$db->exec('PRAGMA user_version = 1');
nb_migrate($db);
check('renamed folder kept', nb_folder('videos')['title'], 'Family Videos');
nb_migrate($db);
check('idempotent', count(nb_folders()), 4);

echo $fail ? "$fail failure(s)\n" : "all folder tests passed\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($fail ? 1 : 0);
