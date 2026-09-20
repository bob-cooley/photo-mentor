<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/thumbs.php';

nb_require_member();
set_time_limit(60);

$id = (string) ($_GET['id'] ?? '');
if (!preg_match('/^[a-f0-9]{12}$/', $id)) {
    http_response_code(404);
    exit;
}
$st = nb_db()->prepare('SELECT * FROM media WHERE id = ?');
$st->execute([$id]);
$row = $st->fetch();
if (!$row) {
    http_response_code(404);
    exit;
}

nb_headers(false);
$path = nb_thumb($row);
if ($path === null) {
    header('Content-Type: image/svg+xml');
    header('Cache-Control: private, max-age=300');
    echo nb_placeholder_svg($row['kind'], $row['ext']);
    exit;
}
header('Content-Type: image/jpeg');
header('Cache-Control: private, max-age=31536000, immutable');
header('Content-Length: ' . filesize($path));
readfile($path);
