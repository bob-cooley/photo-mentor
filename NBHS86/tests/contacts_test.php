<?php
// php tests/contacts_test.php - private contact list: validation, upsert rules, CSV, migration, tus wiring
$fail = 0;
function check(string $label, $got, $want): void { global $fail; if ($got !== $want) { $fail++; echo "FAIL $label\n  got:  " . json_encode($got) . "\n  want: " . json_encode($want) . "\n"; } }
$dir = sys_get_temp_dir() . '/nbhs86-contacts-' . bin2hex(random_bytes(4)); mkdir($dir, 0700);
putenv('NBHS86_DATA_DIR=' . $dir);
require __DIR__ . '/../lib/contacts.php';

// email validation
check('plain address ok', nb_clean_email('Jane.Doe@Example.com '), 'jane.doe@example.com');
check('empty rejected', nb_clean_email(''), null);
check('no @ rejected', nb_clean_email('jane.example.com'), null);
check('no domain rejected', nb_clean_email('jane@'), null);
check('spaces inside rejected', nb_clean_email('ja ne@example.com'), null);
check('formula-looking start rejected', nb_clean_email('=1+1@example.com'), null);
check('leading plus rejected', nb_clean_email('+x@example.com'), null);
check('too long rejected', nb_clean_email(str_repeat('a', 250) . '@e.com'), null);
check('plus tag inside ok', nb_clean_email('jane+nbhs@example.com'), 'jane+nbhs@example.com');

$db = nb_db();
check('schema is v5', (int) $db->query('PRAGMA user_version')->fetchColumn(), 5);
check('contacts table exists', (int) $db->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'contacts'")->fetchColumn(), 1);

// upsert rules
check('first save ok', nb_save_contact($db, 'Jane', 'Doe', 'jane@example.com'), true);
check('bad email not saved', nb_save_contact($db, 'X', 'Y', 'nope'), false);
check('one row so far', count(nb_contacts($db)), 1);
nb_save_contact($db, 'Jane', 'Doe', 'JANE@example.com');
check('same address in another case is the same person', count(nb_contacts($db)), 1);
nb_save_contact($db, 'Janet', 'Doe-Smith', 'jane@example.com');
$c = nb_contacts($db)[0];
check('a new name replaces the old one', [$c['first'], $c['last']], ['Janet', 'Doe-Smith']);
nb_save_contact($db, '', '', 'jane@example.com');
$c = nb_contacts($db)[0];
check('blank names (anonymous upload) never wipe names on file', [$c['first'], $c['last']], ['Janet', 'Doe-Smith']);
nb_save_contact($db, '', '', 'anon@example.com');
check('an anonymous first-timer is stored with the email only', array_column(nb_contacts($db), 'email'), ['anon@example.com', 'jane@example.com']);
nb_save_contact($db, "  Bob\x01 ", '<b>Cooley</b>', 'bob@example.com');
$bob = array_values(array_filter(nb_contacts($db), fn($r) => $r['email'] === 'bob@example.com'))[0];
check('names are cleaned', [$bob['first'], $bob['last']], ['Bob', 'bCooley/b']);

// sorting and removal
check('sorted by last name then first', array_column(nb_contacts($db), 'email'), ['anon@example.com', 'bob@example.com', 'jane@example.com']);
check('remove one', nb_delete_contact($db, 'ANON@example.com'), true);
check('remove a missing one', nb_delete_contact($db, 'nobody@example.com'), false);
check('two left', count(nb_contacts($db)), 2);

// CSV
check('formula text is defused', nb_csv_cell('=HYPERLINK("x")'), '"\'=HYPERLINK(""x"")"');
check('quotes and commas escaped', nb_csv_cell('O"Neil, Jr'), '"O""Neil, Jr"');
check('plain text untouched', nb_csv_cell('Jane'), '"Jane"');
nb_save_contact($db, '=cmd', '@x', 'formula@example.com');
$csv = nb_contacts_csv(nb_contacts($db));
check('csv starts with BOM and header', substr($csv, 0, 3 + 26), "\xEF\xBB\xBF" . "First name,Last name,Email");
check('csv uses CRLF and one line per contact', substr_count($csv, "\r\n"), 1 + 3);
check('csv holds the address', str_contains($csv, '"jane@example.com"'), true);
check('csv defuses a formula name', str_contains($csv, "\"'=cmd\",\"'@x\""), true);

// the list cap
$db->exec('DELETE FROM contacts');
$ins = $db->prepare('INSERT INTO contacts (email, created_at, updated_at) VALUES (?,1,1)');
$db->beginTransaction();
for ($i = 0; $i < NB_CONTACT_LIMIT; $i++) { $ins->execute(["u$i@example.com"]); }
$db->commit();
check('a new person is refused when the list is full', nb_save_contact($db, 'A', 'B', 'new@example.com'), false);
check('an existing person can still be updated when full', nb_save_contact($db, 'A', 'B', 'u1@example.com'), true);
$db->exec('DELETE FROM contacts');

// upgrade from v4 keeps everything and adds the table
$old = sys_get_temp_dir() . '/nbhs86-v4-' . bin2hex(random_bytes(4)); mkdir($old, 0700);
$src = new PDO('sqlite:' . $dir . '/nbhs86.sqlite');
$dst = new PDO('sqlite:' . $old . '/nbhs86.sqlite');
foreach ($src->query("SELECT sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' AND name <> 'contacts'")->fetchAll(PDO::FETCH_COLUMN) as $sql) { $dst->exec($sql); }
$dst->exec("INSERT INTO media (id,folder,album,orig_name,ext,kind,size,hash,created_at,seq) VALUES ('aaaaaaaaaaaa','classmate-uploads','photos','a.jpg','jpg','image',1,'h1',1,1)");
$dst->exec('PRAGMA user_version = 4'); unset($dst, $src);
$php = trim((string) shell_exec('command -v php')) ?: 'php';
$code = 'require ' . var_export(__DIR__ . '/../lib/bootstrap.php', true) . ';$d=nb_db();echo json_encode(["v"=>(int)$d->query("PRAGMA user_version")->fetchColumn(),"tbl"=>(int)$d->query("SELECT COUNT(*) FROM sqlite_master WHERE name=\'contacts\'")->fetchColumn(),"media"=>(int)$d->query("SELECT COUNT(*) FROM media")->fetchColumn(),"snap"=>count(glob(nb_data_dir()."/backups/*pre-v5*"))]);';
$u = json_decode((string) shell_exec('NBHS86_DATA_DIR=' . escapeshellarg($old) . ' ' . escapeshellarg($php) . ' -r ' . escapeshellarg($code)), true) ?? [];
check('v4 -> v5: table added, files untouched, snapshot taken first', $u, ['v' => 5, 'tbl' => 1, 'media' => 1, 'snap' => 1]);

// wiring: the intake form and the tus endpoint carry the fields; admin has the list
$intake = file_get_contents(__DIR__ . '/../assets/js/intake.js');
check("intake.js sends first/last/email", (bool) preg_match("/allowedMetaFields: \\[[^\\]]*'first'[^\\]]*'last'[^\\]]*'email'/", $intake), true);
check('tus.php saves the contact', str_contains(file_get_contents(__DIR__ . '/../api/tus.php'), 'nb_save_contact('), true);
check('admin csv route requires admin', str_contains(file_get_contents(__DIR__ . '/../admin/contacts.php'), 'nb_is_admin()'), true);

echo $fail ? "$fail failure(s)\n" : "all contacts tests passed\n";
exec('rm -rf ' . escapeshellarg($dir) . ' ' . escapeshellarg($old));
exit($fail ? 1 : 0);
