<?php
// php tests/credits_test.php
require __DIR__ . '/../lib/credits.php';

$fail = 0;
function check(string $label, $got, $want): void
{
    global $fail;
    if ($got !== $want) {
        $fail++;
        echo "FAIL $label\n  got:  " . json_encode($got) . "\n  want: " . json_encode($want) . "\n";
    }
}

$l = nb_credit_labels(['bob cooley', 'Mary Jones', 'bob Smith', 'MIKE  sanders', 'Mike Smith', 'Cher', 'Ann Lee', 'ann lee']);
check('unique first name', $l['Mary Jones'], 'Mary');
check('dupe first -> initial', $l['bob cooley'], 'Bob C.');
check('dupe first -> initial 2', $l['bob Smith'], 'Bob S.');
check('same initial needs 2 letters', $l['MIKE  sanders'], 'Mike Sa.');
check('same initial needs 2 letters (b)', $l['Mike Smith'], 'Mike Sm.');
check('single name', $l['Cher'], 'Cher');
check('same person typed twice, case differs', $l['Ann Lee'], 'Ann');

$l2 = nb_credit_labels(['Bob', 'Bob Cooley', 'Bob Adams']);
check('bare first name stays bare when others need initials', $l2['Bob'], 'Bob');
check('with initial', $l2['Bob Cooley'], 'Bob C.');

check('anonymous row', nb_credit_for(['anonymous' => 1, 'uploader' => 'Bob'], []), 'Anonymous');
check('empty name row', nb_credit_for(['anonymous' => 0, 'uploader' => ''], []), 'Anonymous');
check('normal row', nb_credit_for(['anonymous' => 0, 'uploader' => 'Mary Jones'], $l), 'Mary');

echo $fail ? "$fail failure(s)\n" : "all credit tests passed\n";
exit($fail ? 1 : 0);
