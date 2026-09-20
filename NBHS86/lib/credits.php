<?php
declare(strict_types=1);

/**
 * Credit labels: first name only. If two different people share a first name
 * (their last names differ), disambiguate with the shortest last-name prefix
 * that tells them apart: "Mike S." / "Mike Sa.". A first name with no last
 * name entered stays as the bare first name.
 */

function nb_name_parts(string $name): array
{
    $name = trim((string) preg_replace('/\s+/u', ' ', $name));
    if ($name === '') {
        return ['', ''];
    }
    $bits = explode(' ', $name);
    $first = array_shift($bits);
    $last = $bits ? end($bits) : '';
    return [$first, $last];
}

function nb_title_case(string $s): string
{
    return mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
}

/**
 * @param string[] $names every distinct uploader name in the system (raw, as typed)
 * @return array<string,string> raw name => credit label
 */
function nb_credit_labels(array $names): array
{
    $byFirst = [];
    foreach (array_unique($names) as $raw) {
        [$first, $last] = nb_name_parts($raw);
        if ($first === '') {
            continue;
        }
        $byFirst[mb_strtolower($first)][] = [$raw, $first, mb_strtolower($last)];
    }

    $labels = [];
    foreach ($byFirst as $group) {
        $lasts = array_values(array_unique(array_filter(array_column($group, 2), fn($l) => $l !== '')));
        $k = 0;
        if (count($lasts) > 1) {
            $k = 1;
            $max = max(array_map('mb_strlen', $lasts));
            while ($k < $max) {
                $prefixes = array_map(fn($l) => mb_substr($l, 0, $k), $lasts);
                if (count(array_unique($prefixes)) === count($lasts)) {
                    break;
                }
                $k++;
            }
        }
        foreach ($group as [$raw, $first, $last]) {
            $label = nb_title_case($first);
            if ($k > 0 && $last !== '') {
                $label .= ' ' . nb_title_case(mb_substr($last, 0, $k)) . '.';
            }
            $labels[$raw] = $label;
        }
    }
    return $labels;
}

/** Label for one row given the precomputed map. */
function nb_credit_for(array $row, array $labels): string
{
    if ((int) ($row['anonymous'] ?? 0) === 1 || trim((string) ($row['uploader'] ?? '')) === '') {
        return 'Anonymous';
    }
    return $labels[$row['uploader']] ?? nb_title_case(nb_name_parts($row['uploader'])[0]);
}
