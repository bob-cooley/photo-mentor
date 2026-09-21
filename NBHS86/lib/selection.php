<?php
declare(strict_types=1);

// Selection parsing + limits shared by prepare.php and zip.php
require_once __DIR__ . '/derive.php';

function nb_selected_rows(): array
{
    $ids = array_values(array_unique(array_filter(explode(',', (string) ($_POST['ids'] ?? '')), fn($i) => preg_match('/^[a-f0-9]{12}$/', $i) === 1)));
    if (!$ids) {
        nb_json(['error' => 'empty', 'message' => 'Nothing is selected.'], 400);
    }
    if (count($ids) > NB_ZIP_MAX_FILES) {
        nb_json(['error' => 'too_many', 'message' => 'A zip can hold up to ' . NB_ZIP_MAX_FILES . ' files. You selected ' . count($ids) . ' - please download in smaller groups.'], 413);
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = nb_db()->prepare("SELECT * FROM media WHERE id IN ($ph) ORDER BY CASE kind WHEN 'image' THEN 0 WHEN 'video' THEN 1 ELSE 2 END, slideshow_seq IS NOT NULL, COALESCE(seq, slideshow_seq), created_at, rowid");
    $st->execute($ids);
    $rows = $st->fetchAll();
    $bytes = array_sum(array_column($rows, 'size'));
    if ($bytes > NB_ZIP_MAX_BYTES) {
        nb_json(['error' => 'too_big', 'message' => 'That selection is ' . round($bytes / 1073741824, 1) . ' GB. A zip can hold up to 2 GB - please download in smaller groups.'], 413);
    }
    return $rows;
}
