<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/icons.php';

/**
 * Admin-only quick nav (Upload / Gallery / Admin), positioned in the corner by .topnav in site.css. Regular
 * members use the single big button instead (nb_gallery_cta(), nb_upload_cta()); callers gate this behind
 * nb_is_admin() on the gallery and upload pages. The admin page itself always shows it.
 */
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

/**
 * Contact line ("For questions or problems with the site, contact bob@bobcooleyphoto.com."), on every page except
 * admin. The address itself never appears in the page source: assets/js/contact.js joins data-user + data-domain
 * into the href/text at runtime, so a scraper reading raw HTML never finds a usable "user@domain" string.
 * Callers wrap the returned markup in whatever block fits the page (a <p> in a footer, a <div> in the gallery's
 * floating selection bar).
 */
function nb_contact_line(): string
{
    return 'For questions or problems with the site, contact <a href="#" class="email-link" data-user="bob" data-domain="bobcooleyphoto.com"></a>.';
}

/** The single call to action on the gallery page (its "homepage"): always links to the upload page. */
function nb_gallery_cta(): string
{
    return '<div class="cta-wrap"><a class="btn cta-btn" href="' . nb_h(NB_BASE . '/') . '">Share your Photos and Videos!</a></div>';
}

/** The single call to action on the upload page: always links back to the gallery. */
function nb_upload_cta(): string
{
    return '<div class="cta-wrap"><a class="btn cta-btn" href="' . nb_h(NB_BASE . '/gallery/') . '">Back to Galleries</a></div>';
}

/** slug => the one word to use when that folder holds exactly one kind of file. */
const NB_FOLDER_KIND_WORD = ['photos' => 'photo', 'videos' => 'video', 'documents' => 'document', 'slideshows' => 'slideshow'];

/**
 * Folder-box count label. The four built-in folders each hold one kind of file, so they get a fixed word
 * ("71 photos"). Any other folder (an admin-added one that might mix kinds) gets a real breakdown instead
 * ("5 photos, 2 documents"). $kindCounts is ['image' => n, 'video' => n, 'pdf' => n] for that one folder.
 */
function nb_folder_count_label(string $slug, array $kindCounts): string
{
    $total = array_sum($kindCounts);
    if (isset(NB_FOLDER_KIND_WORD[$slug])) {
        $word = NB_FOLDER_KIND_WORD[$slug];
        return $total . ' ' . $word . ($total === 1 ? '' : 's');
    }
    $words = ['image' => 'photo', 'video' => 'video', 'pdf' => 'document'];
    $parts = [];
    foreach ($words as $kind => $word) {
        $n = $kindCounts[$kind] ?? 0;
        if ($n > 0) {
            $parts[] = $n . ' ' . $word . ($n === 1 ? '' : 's');
        }
    }
    return $parts ? implode(', ', $parts) : '0 items';
}

/**
 * Full-screen fade overlay for the gallery/upload transition (assets/js/page-fade.js, .page-fade in site.css).
 * It starts opaque in CSS alone, so there is never a flash of unstyled content before the script runs, then the
 * script always clears it on load and re-covers it before following a .cta-btn link out. Only used on the gallery
 * page and the upload page's two logged-in states; nothing else on the site is scoped into this transition.
 */
function nb_page_fade(): string
{
    return '<div id="pageFade" class="page-fade" aria-hidden="true"></div>';
}

/** All media counts, grouped by folder (album) then by kind, for folder-box labels. */
function nb_media_counts_by_folder(): array
{
    $byFolder = [];
    foreach (nb_db()->query('SELECT album, kind, COUNT(*) c FROM media GROUP BY album, kind')->fetchAll() as $r) {
        $byFolder[$r['album']][$r['kind']] = (int) $r['c'];
    }
    return $byFolder;
}
