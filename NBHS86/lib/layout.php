<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/icons.php';

/** Top navigation between the two sides. Only shown when there is somewhere to go (admin sees everything). */
function nb_nav(string $current): string
{
    $links = [];
    if (nb_intake_open() || nb_is_admin()) {
        $links[] = ['upload', NB_BASE . '/', 'Upload'];
    }
    if (nb_can_view_gallery()) {
        $links[] = ['gallery', NB_BASE . '/gallery/', 'Gallery'];
    }
    if (nb_is_admin()) {
        $links[] = ['admin', NB_BASE . '/admin/', 'Admin'];
    }
    if (count($links) < 2) {
        return '';
    }
    $html = '<nav class="topnav" aria-label="Site sections">';
    foreach ($links as [$key, $href, $label]) {
        $html .= '<a href="' . nb_h($href) . '"' . ($key === $current ? ' aria-current="page"' : '') . '>' . nb_h($label) . '</a>';
    }
    return $html . '</nav>';
}
