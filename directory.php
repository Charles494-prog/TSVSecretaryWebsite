<?php
declare(strict_types=1);

// Directory Report: the worship schedule, location and contact details reported to the INC Directory.
// (The same rules as the desktop app's directory.rs.)
//
// The site remembers what the last report said. Values that differ from what the Directory already has
// are reported under "Mga Dapat ituwid". Once a month's report is made, its values count as the
// Directory's from the next month on, so they then appear under "Tama/Updated na". Generating again in
// the same month compares against the same starting point, so changes keep showing.

const DIRECTORY_DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
const DIRECTORY_STATE_KEY = 'directory_report_state';

function directoryLoadState(PDO $pdo): ?array
{
    $statement = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :key');
    $statement->execute(['key' => DIRECTORY_STATE_KEY]);
    $state = json_decode((string) $statement->fetchColumn(), true);
    return is_array($state) && isset($state['month'], $state['before'], $state['after']) ? $state : null;
}

/** The schedule from the Suguan (regular Pagsamba, EWS and PNK) with the template's location and contact. */
function directoryFromSuguan(PDO $pdo, DateTimeImmutable $month): array
{
    $services = [];
    foreach (monthlyScheduleServices($pdo, $month) as $service) {
        $services[] = [
            'day' => (new DateTimeImmutable($service['date']))->format('l') . ($service['type'] === 'pnk' ? ' (PNK)' : ''),
            'time' => $service['time'],
            'lang' => $service['ews'] ? 'English' : 'Tagalog',
        ];
    }
    return [
        'services' => $services,
        'address' => 'FYM Ave. San Isidro Cainta, Rizal 1900, Philippines',
        'latitude' => '14.631209',
        'longitude' => '121.108440',
        'landline' => '(02) 8633 5731',
        'email' => '',
        'note' => '',
    ];
}

/** What the Directory has as of $month: an earlier month's report, or for this month's own report, what it was compared with. */
function directoryBaseline(PDO $pdo, DateTimeImmutable $month): array
{
    $state = directoryLoadState($pdo);
    if ($state === null) {
        return directoryFromSuguan($pdo, $month);
    }
    return $state['month'] < $month->format('Y-m') ? $state['after'] : $state['before'];
}

/** The values the form starts with: the most recent report, or the Suguan schedule before the first one. */
function directoryFormValues(PDO $pdo, DateTimeImmutable $month): array
{
    return directoryLoadState($pdo)['after'] ?? directoryFromSuguan($pdo, $month);
}

function directorySave(PDO $pdo, DateTimeImmutable $month, array $before, array $after): void
{
    $pdo->prepare(
        'INSERT INTO app_settings (setting_key, setting_value) VALUES (:key, :value)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute([
        'key' => DIRECTORY_STATE_KEY,
        'value' => json_encode(['month' => $month->format('Y-m'), 'before' => $before, 'after' => $after], JSON_UNESCAPED_UNICODE),
    ]);
}

function directoryLocationDiffers(array $a, array $b): bool
{
    foreach (['address', 'latitude', 'longitude'] as $key) {
        if (trim((string) $a[$key]) !== trim((string) $b[$key])) {
            return true;
        }
    }
    return false;
}

/** Reads the Directory Report fields from the generator; null when "Update data in the INC Directory" is off. */
function directoryFromForm(): ?array
{
    if (($_POST['dir_update'] ?? '') !== '1') {
        return null;
    }
    $list = static fn(string $key): array => is_array($_POST[$key] ?? null) ? array_values($_POST[$key]) : [];
    $days = $list('dir_day');
    if (!$days) {
        return null;
    }
    $times = $list('dir_time');
    $langs = $list('dir_lang');
    $services = [];
    foreach ($days as $i => $day) {
        $day = trim((string) $day);
        $time = trim((string) ($times[$i] ?? ''));
        if ($day === '' && $time === '') {
            continue;
        }
        if (!in_array(preg_replace('/ \(PNK\)$/', '', $day), DIRECTORY_DAYS, true)) {
            throw new InvalidArgumentException('Choose the day of every worship service in the Directory Report.');
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new InvalidArgumentException("Enter the time of the {$day} worship service in the Directory Report.");
        }
        $services[] = ['day' => $day, 'time' => $time, 'lang' => ($langs[$i] ?? '') === 'English' ? 'English' : 'Tagalog'];
    }
    if (!$services) {
        throw new InvalidArgumentException('The Directory Report needs at least one worship service.');
    }
    $field = static function (string $key, string $label, int $max): string {
        $value = is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException("{$label} must be {$max} characters or fewer.");
        }
        return $value;
    };
    return [
        'services' => $services,
        'address' => $field('dir_address', 'The address', 250),
        'latitude' => $field('dir_latitude', 'The latitude', 30),
        'longitude' => $field('dir_longitude', 'The longitude', 30),
        'landline' => $field('dir_landline', 'The landline', 60),
        'email' => $field('dir_email', 'The email', 150),
        'note' => $field('dir_note', 'Pansin', 500),
    ];
}

/** Each reported service, marked changed when the Directory does not list the same day, time and language. */
function directoryCompareSchedule(array $baseline, array $reported): array
{
    $rows = [];
    foreach ($reported['services'] as $service) {
        $rows[] = ['service' => $service, 'changed' => !in_array($service, $baseline['services'], false)];
    }
    return $rows;
}

/** True when this month's report already lists changes (so the form opens switched on). */
function directoryChangesThisMonth(PDO $pdo, DateTimeImmutable $month): bool
{
    $state = directoryLoadState($pdo);
    if ($state === null || $state['month'] !== $month->format('Y-m')) {
        return false;
    }
    foreach (directoryCompareSchedule($state['before'], $state['after']) as $row) {
        if ($row['changed']) {
            return true;
        }
    }
    return directoryLocationDiffers($state['after'], $state['before']);
}

// ---- The official schedule (from the latest Directory Report), used by Normalize ----

/** The worship schedule as last reported to the Directory; null before the first report. */
function directoryOfficialSlots(PDO $pdo): ?array
{
    $state = directoryLoadState($pdo);
    if ($state === null) {
        return null;
    }
    $slots = [];
    foreach ($state['after']['services'] as $service) {
        $pnk = str_ends_with($service['day'], '(PNK)');
        $weekday = array_search(trim(preg_replace('/ \(PNK\)$/', '', $service['day'])), DIRECTORY_DAYS, true);
        if ($weekday !== false) {
            $slots[] = ['weekday' => $weekday, 'pnk' => $pnk, 'time' => $service['time'], 'ews' => $service['lang'] === 'English'];
        }
    }
    return $slots;
}

/** The regular schedule a new week starts with, used by Normalize before the first Directory Report. */
function suguanDefaultSlots(): array
{
    $slots = [];
    foreach ([[2, '19:30', false], [3, '05:30', false], [3, '19:45', false], [5, '18:00', false], [6, '06:00', false], [6, '08:00', true], [6, '10:00', false]] as [$weekday, $time, $pnk]) {
        $slots[] = ['weekday' => $weekday, 'pnk' => $pnk, 'time' => $time, 'ews' => false];
    }
    return $slots;
}

function suguanSortServices(array &$services): void
{
    usort($services, static fn(array $a, array $b): int => [$a['date'], $a['time']] <=> [$b['date'], $b['time']]);
}

function suguanMinutes(string $time): int
{
    [$h, $m] = array_map('intval', explode(':', $time) + [0, 0]);
    return $h * 60 + $m;
}

/**
 * Pairs each official service with one of the week's worship services: first the one on the same day and of
 * the same kind (Pagsamba or PNK) closest in time, then any worship service left over, in order.
 */
function suguanMatchSlots(array $services, DateTimeImmutable $monday, array $slots): array
{
    $pool = array_values(array_filter($services, static fn(array $s): bool => in_array($s['type'], ['katandaan', 'pnk'], true)));
    suguanSortServices($pool);
    usort($slots, static fn(array $a, array $b): int => [$a['weekday'], suguanMinutes($a['time'])] <=> [$b['weekday'], suguanMinutes($b['time'])]);
    $paired = array_fill(0, count($slots), null);
    foreach ($slots as $k => $slot) {
        $date = $monday->modify("+{$slot['weekday']} days")->format('Y-m-d');
        $best = null;
        foreach ($pool as $i => $service) {
            if ($service['date'] === $date && ($service['type'] === 'pnk') === $slot['pnk']) {
                $gap = abs(suguanMinutes($service['time']) - suguanMinutes($slot['time']));
                if ($best === null || $gap < $best[1]) {
                    $best = [$i, $gap];
                }
            }
        }
        if ($best !== null) {
            $paired[$k] = $pool[$best[0]];
            unset($pool[$best[0]]);
            $pool = array_values($pool);
        }
    }
    foreach ($paired as $k => $item) {
        if ($item === null && $pool) {
            $paired[$k] = array_shift($pool);
        }
    }
    return [array_map(null, $slots, $paired), $pool];
}

function suguanPlaceOnSlot(array $slot, DateTimeImmutable $monday, ?array $existing): array
{
    $service = $existing ?? ['id' => suguanServiceId(), 'computer' => '', 'video' => '', 'audio' => ''];
    return array_merge($service, [
        'date' => $monday->modify("+{$slot['weekday']} days")->format('Y-m-d'),
        'time' => $slot['time'],
        'type' => $slot['pnk'] ? 'pnk' : 'katandaan',
        'ews' => $slot['ews'],
        'regular' => true,
    ]);
}

/**
 * Normalize: the week becomes exactly the regular schedule from the INC Directory report. Each official service
 * keeps the operators of the matching service, missing ones are added, and everything else is removed.
 */
function suguanNormalizeToSchedule(array $services, DateTimeImmutable $monday, array $slots): array
{
    [$pairs] = suguanMatchSlots($services, $monday, $slots);
    $out = array_map(static fn(array $pair): array => suguanPlaceOnSlot($pair[0], $monday, $pair[1]), $pairs);
    suguanSortServices($out);
    return $out;
}

/** After a Directory Report changes the schedule: worship services move to it; nothing the user added is removed. */
function suguanFollowSchedule(array $services, DateTimeImmutable $monday, array $slots): array
{
    [$pairs, $leftovers] = suguanMatchSlots($services, $monday, $slots);
    $out = array_values(array_filter($services, static fn(array $s): bool => !in_array($s['type'], ['katandaan', 'pnk'], true)));
    foreach ($pairs as [$slot, $existing]) {
        $out[] = suguanPlaceOnSlot($slot, $monday, $existing);
    }
    $out = array_merge($out, $leftovers);
    suguanSortServices($out);
    return $out;
}

/** This week and the later weeks already planned follow the new schedule. */
function suguanApplyScheduleFrom(PDO $pdo, DateTimeImmutable $fromMonday, array $slots): void
{
    saveSuguanWeek($pdo, $fromMonday, suguanFollowSchedule(loadSuguanWeek($pdo, $fromMonday)['services'], $fromMonday, $slots));
    $statement = $pdo->prepare('SELECT week_start FROM suguan_weeks WHERE week_start > :week ORDER BY week_start');
    $statement->execute(['week' => $fromMonday->format('Y-m-d')]);
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $week) {
        $monday = new DateTimeImmutable($week, $fromMonday->getTimezone());
        saveSuguanWeek($pdo, $monday, suguanFollowSchedule(loadSuguanWeek($pdo, $monday)['services'], $monday, $slots));
    }
}

// ---- The Directory Report document ----

/** Moves the ✓ from "Tama/Updated na" to "Mga Dapat ituwid" in a question paragraph. */
function directoryMoveCheck(DOMXPath $xpath, DOMElement $paragraph): void
{
    $texts = iterator_to_array($xpath->query('.//w:t', $paragraph));
    foreach ($texts as $i => $text) {
        if (!str_contains($text->textContent, '✓')) {
            continue;
        }
        $text->nodeValue = str_replace('✓', ' ', $text->textContent);
        $text->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        foreach (array_slice($texts, $i + 1) as $next) {
            if (str_ends_with(rtrim($next->textContent), '(')) {
                $next->nodeValue = rtrim($next->textContent) . '✓';
                $next->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
                break;
            }
        }
        return;
    }
}

function generateDirectoryReportDocx(array $rows, bool $locationChanged, array $details, array $settings, string $templatePath): string
{
    return generateDocxFromTemplate(
        $templatePath,
        static function (DOMXPath $xpath) use ($rows, $locationChanged, $details, $settings): void {
            $paragraphs = iterator_to_array($xpath->query('/w:document/w:body/w:p'));
            replaceWordText($xpath, $paragraphs[2], 'Marikina', placeNameText($settings['district_name']));
            replaceWordText($xpath, $paragraphs[3], 'FYM Avenue', placeNameText($settings['locale_name']));
            // The letter is dated the day it is generated.
            setWordText($xpath, $paragraphs[5], 'Petsa: ' . (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('F d, Y'));
            if (trim((string) ($settings['district_supervisor'] ?? '')) !== '') {
                replaceWordText($xpath, $paragraphs[7], 'CYRUS JOSE E. DIZON', mb_strtoupper($settings['district_supervisor']));
            }
            $find = static function (string $needle) use ($paragraphs): ?DOMElement {
                foreach ($paragraphs as $paragraph) {
                    if (str_contains($paragraph->textContent, $needle)) {
                        return $paragraph;
                    }
                }
                return null;
            };
            $signatureLine = $find('Benjamin Arceo Jr.');
            foreach (['Charles Andrei Galvan' => 'tsv_lead', 'Benjamin Arceo Jr.' => 'pastor'] as $needle => $settingKey) {
                if ($signatureLine && trim((string) ($settings[$settingKey] ?? '')) !== '') {
                    replaceWordText($xpath, $signatureLine, $needle, $settings[$settingKey]);
                }
            }

            // 1. Araw, oras at wika ng pagsamba.
            if (array_filter($rows, static fn(array $row): bool => $row['changed']) && ($p = $find('Araw, oras at wika ng pagsamba'))) {
                directoryMoveCheck($xpath, $p);
            }
            $table = docxTableAt($xpath, 0);
            $trs = array_slice(docxRows($xpath, $table), 2);
            while (count($trs) < count($rows)) {
                $copy = end($trs)->cloneNode(true);
                $table->appendChild($copy);
                $trs[] = $copy;
            }
            foreach ($trs as $index => $tr) {
                $cells = array_fill(0, 6, '');
                if (isset($rows[$index])) {
                    $service = $rows[$index]['service'];
                    $time = DateTimeImmutable::createFromFormat('!H:i', $service['time']);
                    $values = [$service['day'], $time ? $time->format('g:iA') : $service['time'], $service['lang']];
                    $offset = $rows[$index]['changed'] ? 0 : 3;
                    foreach ($values as $k => $value) {
                        $cells[$offset + $k] = $value;
                    }
                }
                foreach ($cells as $column => $value) {
                    setWordText($xpath, docxCell($xpath, $tr, $column), $value);
                }
            }

            // 2. Lokasyon.
            if ($locationChanged && ($p = $find('Lokasyon?'))) {
                directoryMoveCheck($xpath, $p);
            }
            $location = docxRows($xpath, docxTableAt($xpath, 1));
            setWordText($xpath, docxCell($xpath, $location[0], 1), trim($details['address']));
            setWordText($xpath, docxCell($xpath, $location[1], 1), 'Latitude: ' . trim($details['latitude']));
            setWordText($xpath, docxCell($xpath, $location[1], 2), 'Longitude: ' . trim($details['longitude']));

            // 3. Contact information (always "Tama").
            $orWala = static fn(string $v): string => trim($v) === '' ? 'Wala po' : trim($v);
            $contact = docxRows($xpath, docxTableAt($xpath, 2));
            setWordText($xpath, docxCell($xpath, $contact[1], 0), 'Landline: ' . $orWala($details['landline']));
            setWordText($xpath, docxCell($xpath, $contact[1], 2), $orWala($details['email']));
            setWordText($xpath, docxCell($xpath, $contact[1], 3), trim($details['note']));
        }
    );
}
