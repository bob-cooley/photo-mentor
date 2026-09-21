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

/**
 * Header art (upload and gallery pages). The picture is 1455x600 (ratio 2.425); its height is set in
 * assets/css/site.css (--header-h, with tablet and phone values). `sizes` must equal the rendered width
 * (height x 2.425 = 242px at 100px tall): if you change the height, change it here too.
 */
function nb_header_image(): string
{
    $b = NB_BASE . '/assets/img/';
    $v = '?v=1'; // bump when the picture is replaced (images are cached for a year)
    return '<header class="site-header"><img class="site-header-img" src="' . $b . 'nbhs86-header-727.jpg' . $v . '"'
        . ' srcset="' . $b . 'nbhs86-header-485.jpg' . $v . ' 485w, ' . $b . 'nbhs86-header-727.jpg' . $v . ' 727w, ' . $b . 'nbhs86-header.jpg' . $v . ' 1455w"'
        . ' sizes="(max-width: 900px) 242px, 485px" width="1455" height="600" alt="NB Class of \'86 and friends" decoding="async" fetchpriority="high"></header>';
}
