<?php
declare(strict_types=1);

// Private contact list: first name, last name and email of the people who upload, kept only so Bob can tell them
// when the gallery opens. Never shown on any public page; read only by admin (list, CSV, remove).

require_once __DIR__ . '/ingest.php';

const NB_CONTACT_LIMIT = 5000; // stops a runaway script from filling the table

/** Lower-cased address, or null when it is not a usable email. The first character must be a letter or digit so the
 *  CSV can never carry something a spreadsheet would run as a formula (=, +, -, @). */
function nb_clean_email(string $email): ?string
{
    $email = strtolower(trim($email));
    if ($email === '' || strlen($email) > 254 || !preg_match('/^[a-z0-9]/', $email)) {
        return null;
    }
    return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email;
}

/**
 * Add or update one contact (matched on email). A blank name never overwrites a name already on file, so an
 * anonymous upload that leaves the name boxes empty keeps whatever the person gave before.
 * Returns false when the email is unusable or the list is full.
 */
function nb_save_contact(PDO $db, string $first, string $last, string $email): bool
{
    $email = nb_clean_email($email);
    if ($email === null) {
        return false;
    }
    $first = nb_clean_person_name($first);
    $last = nb_clean_person_name($last);
    $now = time();
    $exists = $db->prepare('SELECT 1 FROM contacts WHERE email = ?');
    $exists->execute([$email]);
    if (!$exists->fetchColumn() && (int) $db->query('SELECT COUNT(*) FROM contacts')->fetchColumn() >= NB_CONTACT_LIMIT) {
        return false;
    }
    $db->prepare(
        'INSERT INTO contacts (email, first, last, created_at, updated_at) VALUES (?,?,?,?,?)
         ON CONFLICT(email) DO UPDATE SET
           first = CASE WHEN excluded.first <> \'\' THEN excluded.first ELSE first END,
           last = CASE WHEN excluded.last <> \'\' THEN excluded.last ELSE last END,
           updated_at = excluded.updated_at
         WHERE (excluded.first <> \'\' AND excluded.first <> first) OR (excluded.last <> \'\' AND excluded.last <> last)'
    )->execute([$email, $first, $last, $now, $now]);
    return true;
}

/** @return array<int, array{first:string,last:string,email:string,created_at:int}> */
function nb_contacts(PDO $db): array
{
    return $db->query('SELECT first, last, email, created_at FROM contacts ORDER BY lower(last), lower(first), email')->fetchAll();
}

function nb_delete_contact(PDO $db, string $email): bool
{
    $st = $db->prepare('DELETE FROM contacts WHERE email = ?');
    $st->execute([strtolower(trim($email))]);
    return $st->rowCount() > 0;
}

/** One CSV field. Text a spreadsheet could run as a formula gets a leading apostrophe. */
function nb_csv_cell(string $v): string
{
    if (preg_match('/^[=+\-@\t\r]/', $v)) {
        $v = "'" . $v;
    }
    return '"' . str_replace('"', '""', $v) . '"';
}

/** Excel-friendly CSV (UTF-8 with BOM, CRLF): First name, Last name, Email. */
function nb_contacts_csv(array $contacts): string
{
    $out = "\xEF\xBB\xBF" . "First name,Last name,Email\r\n";
    foreach ($contacts as $c) {
        $out .= nb_csv_cell((string) $c['first']) . ',' . nb_csv_cell((string) $c['last']) . ',' . nb_csv_cell((string) $c['email']) . "\r\n";
    }
    return $out;
}
