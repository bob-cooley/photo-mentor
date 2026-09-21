<?php
// php tests/bootstrap_test.php - guards against a bad edit silently deleting core functions from lib/*.php
$tmp = sys_get_temp_dir() . '/nbhs86-boot-' . bin2hex(random_bytes(4));
mkdir($tmp);
putenv("NBHS86_DATA_DIR=$tmp");
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/selection.php';
require_once __DIR__ . '/../lib/thumbs.php';
require_once __DIR__ . '/../lib/credits.php';

$required = [
    // bootstrap: config, storage, db, headers, auth, throttling, naming, migrations, settings, folders
    'nb_config', 'nb_configured', 'nb_data_dir', 'nb_media_path', 'nb_db', 'nb_next_seq', 'nb_backfill_seq', 'nb_download_name', 'nb_dash_spaces', 'nb_is_phone_video_name',
    'nb_backup_db', 'nb_migrate', 'nb_setting', 'nb_set_setting', 'nb_gallery_open', 'nb_intake_open', 'nb_can_view_gallery',
    'nb_require_gallery', 'nb_folders', 'nb_folder', 'nb_headers', 'nb_json', 'nb_sign', 'nb_https', 'nb_issue_cookie',
    'nb_clear_cookie', 'nb_cookie_valid', 'nb_is_admin', 'nb_is_member', 'nb_require_member', 'nb_normalize_secret_input',
    'nb_check_passcode', 'nb_check_admin_password', 'nb_client_ip', 'nb_login_allowed', 'nb_login_failed', 'nb_h',
    // ingest, derive, thumbs, credits, layout, selection, icons
    'nb_store_file', 'nb_ingest_upload', 'nb_run_jobs', 'nb_pending_jobs', 'nb_variant', 'nb_clean_path', 'nb_has_gps', 'nb_strip_gps',
    'nb_make_jpeg', 'nb_extract_meta', 'nb_fill_meta', 'nb_zip_names', 'nb_thumb', 'nb_credit_labels', 'nb_credit_for',
    'nb_nav', 'nb_icon', 'nb_selected_rows', 'nb_daily_backup', 'nb_list_backups', 'nb_error_log_path', 'nb_recent_errors', 'nb_thumb_image', 'nb_thumb_video',
];
$missing = array_values(array_filter($required, fn($f) => !function_exists($f)));
$consts = ['NB_BASE', 'NB_SCHEMA_VERSION', 'NB_ALBUM_BY_KIND', 'NB_SLIDESHOW_ALBUM', 'NB_SLIDESHOW_CREDIT', 'NB_ZIP_MAX_FILES', 'NB_ZIP_MAX_BYTES', 'NB_TYPES', 'NB_MIME', 'NB_ICONS', 'NB_ANON_CREDIT'];
$missingC = array_values(array_filter($consts, fn($c) => !defined($c)));
exec('rm -rf ' . escapeshellarg($tmp));
if ($missing || $missingC) {
    echo 'FAIL missing: ' . implode(', ', array_merge($missing, $missingC)) . "\n";
    exit(1);
}
echo "all " . count($required) . " core functions and " . count($consts) . " constants present\n";
