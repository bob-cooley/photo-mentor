<?php
declare(strict_types=1);

// Folder listing for the gallery grid (JSON). ?f=slug&sort=arrival|date_asc|date_desc&kind=all|image|video|pdf&page=1
// &ids=1 returns just the ids for "select all" (capped).
require_once __DIR__ . '/../lib/derive.php';
require_once __DIR__ . '/../lib/credits.php';

nb_require_gallery();
$folder = nb_folder((string) ($_GET['f'] ?? ''));
if (!$folder) {
    nb_json(['error' => 'no such folder'], 404);
}
nb_fill_meta(30, 4.0); // opportunistically finish dimensions/shot dates for older uploads

$kind = (string) ($_GET['kind'] ?? 'all');
$kind = in_array($kind, ['image', 'video', 'pdf'], true) ? $kind : 'all';
$sort = (string) ($_GET['sort'] ?? 'arrival');
$order = match ($sort) {
    'date_asc' => 'taken_at IS NULL, taken_at, created_at, rowid',
    'date_desc' => 'taken_at IS NULL, taken_at DESC, created_at, rowid',
    default => 'created_at, rowid',
};
$where = 'album = ?' . ($kind === 'all' ? '' : ' AND kind = ?');
$args = $kind === 'all' ? [$folder['slug']] : [$folder['slug'], $kind];
$db = nb_db();

$cnt = $db->prepare('SELECT kind, COUNT(*) FROM media WHERE album = ? GROUP BY kind');
$cnt->execute([$folder['slug']]);
$counts = $cnt->fetchAll(PDO::FETCH_KEY_PAIR);
$total = (int) array_sum($counts);

if (!empty($_GET['ids'])) {
    $st = $db->prepare("SELECT id FROM media WHERE $where ORDER BY $order LIMIT 5000");
    $st->execute($args);
    nb_json(['ids' => $st->fetchAll(PDO::FETCH_COLUMN)]);
}

$per = 120;
$page = max(1, (int) ($_GET['page'] ?? 1));
$st = $db->prepare("SELECT * FROM media WHERE $where ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($args);
$rows = $st->fetchAll();

$labels = nb_credit_labels($db->query("SELECT DISTINCT uploader FROM media WHERE anonymous = 0 AND uploader <> ''")->fetchAll(PDO::FETCH_COLUMN));
$base = NB_BASE . '/api';
$items = [];
foreach ($rows as $r) {
    $items[] = [
        'id' => $r['id'],
        'name' => nb_download_name($r, in_array($r['ext'], ['heic', 'heif'], true)),
        'kind' => $r['kind'],
        'credit' => nb_credit_for($r, $labels),
        'w' => (int) $r['w'],
        'h' => (int) $r['h'],
        'size' => (int) $r['size'],
        'taken' => $r['taken_at'] ? (int) $r['taken_at'] : null,
        'thumb' => "$base/thumb.php?id={$r['id']}",
        'src' => "$base/file.php?id={$r['id']}",
        'dl' => "$base/file.php?id={$r['id']}&dl=1",
    ];
}
$filtered = (int) ($kind === 'all' ? $total : ($counts[$kind] ?? 0));
nb_json([
    'folder' => ['slug' => $folder['slug'], 'title' => $folder['title']],
    'counts' => ['all' => $total, 'image' => (int) ($counts['image'] ?? 0), 'video' => (int) ($counts['video'] ?? 0), 'pdf' => (int) ($counts['pdf'] ?? 0)],
    'page' => $page,
    'hasMore' => $page * $per < $filtered,
    'items' => $items,
]);
