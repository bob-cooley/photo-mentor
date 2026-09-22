<?php
declare(strict_types=1);

// Admin only: the private contact list as a CSV (First name, Last name, Email) for a spreadsheet.
require_once __DIR__ . '/../lib/contacts.php';

nb_headers();
if (!nb_is_admin()) {
    http_response_code(401);
    exit('Sign in on the admin page first.');
}
$csv = nb_contacts_csv(nb_contacts(nb_db()));
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="nbhs86-contacts-' . date('Ymd') . '.csv"');
header('Content-Length: ' . strlen($csv));
echo $csv;
