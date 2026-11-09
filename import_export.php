<?php
declare(strict_types=1);

const OFFICER_COLUMNS = [
    'id',
    'masterlist_number',
    'name',
    'tungkulin',
    'oath_taking_date',
    'oath_taking_text',
    'ndi_signing_date',
    'ndi_signing_text',
    'is_active',
    'birthday',
    'wedding_anniversary',
    'contact_telegram',
];
const LEGACY_OFFICER_ROLES = [
    'TSV Officers' => 'Locale TSV Officer',
    'Locale TSV Officer ng Officers' => 'Locale TSV Officer',
];
// Backups now carry photos, so the limit is whatever PHP accepts (raised to 512 MB in .htaccess).
function iniBytes(string $key): int
{
    $value = trim((string) ini_get($key));
    $number = (int) $value;
    return match (strtolower(substr($value, -1))) {
        'g' => $number * 1024 ** 3,
        'm' => $number * 1024 ** 2,
        'k' => $number * 1024,
        default => $number,
    };
}

function importMaxBytes(): int
{
    return min(iniBytes('upload_max_filesize'), iniBytes('post_max_size'));
}

function formatBytes(int $bytes): string
{
    return $bytes >= 1024 ** 3 ? round($bytes / 1024 ** 3, 1) . ' GB' : round($bytes / 1024 ** 2) . ' MB';
}

function officersOrderedForExport(PDO $pdo): array
{
    return $pdo->query(
        'SELECT * FROM officers ORDER BY masterlist_number IS NULL, masterlist_number ASC, is_active DESC, name ASC'
    )->fetchAll();
}

function xlsxEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function xlsxColumn(int $index): string
{
    $name = '';
    for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
        $name = chr(65 + ($index - 1) % 26) . $name;
    }

    return $name;
}

function officersXlsx(array $officers): string
{
    $headers = ['Blg.', 'Name', 'Tungkulin', 'Oath-taking', 'NDA signing', 'Birthday', 'Wedding anniversary', 'Contact (Active) Telegram #', 'Status'];
    $widths = [8, 32, 28, 20, 20, 20, 22, 28, 12];
    $rows = [$headers];
    foreach ($officers as $officer) {
        $rows[] = [
            $officer['masterlist_number'] === null ? '' : (int) $officer['masterlist_number'],
            $officer['name'],
            $officer['tungkulin'],
            masterlistDateText($officer, 'oath_taking_text', 'oath_taking_date'),
            masterlistDateText($officer, 'ndi_signing_text', 'ndi_signing_date'),
            $officer['birthday'] ? (new DateTimeImmutable($officer['birthday']))->format('F j, Y') : '',
            !empty($officer['wedding_anniversary']) ? (new DateTimeImmutable($officer['wedding_anniversary']))->format('F j, Y') : '',
            (string) ($officer['contact_telegram'] ?? ''),
            (int) $officer['is_active'] === 1 ? 'Active' : 'Inactive',
        ];
    }

    $sheetRows = '';
    foreach ($rows as $rowIndex => $row) {
        $cells = '';
        foreach ($row as $columnIndex => $value) {
            $reference = xlsxColumn($columnIndex) . ($rowIndex + 1);
            $style = $rowIndex === 0 ? ' s="1"' : '';
            if (is_int($value)) {
                $cells .= "<c r=\"{$reference}\"{$style}><v>{$value}</v></c>";
            } elseif ($value !== '') {
                $cells .= "<c r=\"{$reference}\"{$style} t=\"inlineStr\"><is><t xml:space=\"preserve\">" . xlsxEscape((string) $value) . '</t></is></c>';
            }
        }
        $sheetRows .= '<row r="' . ($rowIndex + 1) . '">' . $cells . '</row>';
    }
    $columns = '';
    foreach ($widths as $index => $width) {
        $columns .= '<col min="' . ($index + 1) . '" max="' . ($index + 1) . '" width="' . $width . '" customWidth="1"/>';
    }
    $lastCell = xlsxColumn(count($headers) - 1) . count($rows);

    return zipFiles([
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Officers" sheetId="1" r:id="rId1"/></sheets>'
            . '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">Officers!$A$1:$' . preg_replace('/\d+$/', '', $lastCell) . '$' . count($rows) . '</definedName></definedNames>'
            . '</workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFEDEDED"/></patternFill></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="2"><xf/><xf fontId="1" fillId="2" applyFont="1" applyFill="1"/></cellXfs>'
            . '</styleSheet>',
        'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>' . $columns . '</cols>'
            . '<sheetData>' . $sheetRows . '</sheetData>'
            . '<autoFilter ref="A1:' . $lastCell . '"/>'
            . '</worksheet>',
    ]);
}

// A backup that phpMyAdmin can also import: the officer records plus the TSV Memories posts and photos.
// It is written piece by piece through $write, since the photos can make it large.
function writeBackupSql(PDO $pdo, callable $write): void
{
    $write("-- TSV officer records and TSV Memories\n-- Exported " . date('Y-m-d H:i') . "\n\n" . officersSqlDump($pdo));
    $write(settingsSqlDump($pdo));
    writeMemoriesSql($pdo, $write);
}

// The signatories, locale and form-generator settings plus the planned Suguan weeks, so the TSV Secretary
// desktop app can restore them. This site's own Import still reads only the officers and memories.
function settingsSqlDump(PDO $pdo): string
{
    $pairs = static fn(array $rows): string => implode(",\n", array_map(
        static fn(array $row): string => '(' . $pdo->quote((string) $row[0]) . ', ' . $pdo->quote((string) $row[1]) . ')',
        $rows
    ));
    $sql = "\nCREATE TABLE IF NOT EXISTS `app_settings` (\n  `setting_key` varchar(64) NOT NULL,\n  `setting_value` varchar(5000) NOT NULL DEFAULT '',\n  PRIMARY KEY (`setting_key`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n";
    $settings = $pdo->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key <> 'schema_version' ORDER BY setting_key")->fetchAll(PDO::FETCH_NUM);
    if ($settings) {
        $sql .= "\nINSERT INTO `app_settings` (`setting_key`, `setting_value`) VALUES\n" . $pairs($settings)
            . "\nON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);\n";
    }
    $sql .= "\nCREATE TABLE IF NOT EXISTS `suguan_weeks` (\n  `week_start` date NOT NULL,\n  `services` longtext NOT NULL,\n  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),\n  PRIMARY KEY (`week_start`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n";
    $weeks = $pdo->query('SELECT week_start, services FROM suguan_weeks ORDER BY week_start')->fetchAll(PDO::FETCH_NUM);
    if ($weeks) {
        $sql .= "\nINSERT INTO `suguan_weeks` (`week_start`, `services`) VALUES\n" . $pairs($weeks)
            . "\nON DUPLICATE KEY UPDATE `services` = VALUES(`services`);\n";
    }

    return $sql;
}

function officersSqlDump(PDO $pdo): string
{
    $create = $pdo->query('SHOW CREATE TABLE officers')->fetch(PDO::FETCH_NUM)[1];
    $create = preg_replace('/^CREATE TABLE/', 'CREATE TABLE IF NOT EXISTS', $create);
    $sql = $create . ";\n";

    $officers = $pdo->query('SELECT * FROM officers ORDER BY id')->fetchAll();
    if ($officers) {
        $tuples = [];
        foreach ($officers as $officer) {
            $values = [];
            foreach (OFFICER_COLUMNS as $column) {
                $value = $officer[$column] ?? null;
                $values[] = $value === null ? 'NULL' : (is_int($value) ? (string) $value : $pdo->quote((string) $value));
            }
            $tuples[] = '(' . implode(', ', $values) . ')';
        }
        $sql .= "\nINSERT INTO `officers` (`" . implode('`, `', OFFICER_COLUMNS) . "`) VALUES\n"
            . implode(",\n", $tuples) . ";\n";
    }

    return $sql;
}

function readSqlString(string $sql, int &$position): string
{
    $quote = $sql[$position++];
    $value = '';
    $length = strlen($sql);
    $escapes = ['0' => "\0", 'b' => "\x08", 'n' => "\n", 'r' => "\r", 't' => "\t", 'Z' => "\x1A"];
    while ($position < $length) {
        // Copy the plain run up to the next quote or backslash in one step (photos are long base64 runs).
        $run = strcspn($sql, $quote . '\\', $position);
        $value .= substr($sql, $position, $run);
        $position += $run;
        if ($position >= $length) {
            break;
        }
        $character = $sql[$position++];
        if ($character === '\\' && $position < $length) {
            $next = $sql[$position++];
            $value .= $escapes[$next] ?? $next;
        } elseif ($character === $quote) {
            if (($sql[$position] ?? '') === $quote) {
                $value .= $quote;
                $position++;
            } else {
                return $value;
            }
        }
    }
    throw new InvalidArgumentException('The SQL file has an unterminated text value.');
}

// Reads the VALUES tuples that start at $position, up to the statement's closing semicolon.
function readSqlTuples(string $sql, int $position): array
{
    $tuples = [];
    $length = strlen($sql);
    while ($position < $length) {
        $position += strspn($sql, " \t\r\n,", $position);
        if (($sql[$position] ?? ';') !== '(') {
            break;
        }
        $position++;
        $tuple = [];
        while (true) {
            $position += strspn($sql, " \t\r\n", $position);
            $character = $sql[$position] ?? '';
            if ($character === "'" || $character === '"') {
                $tuple[] = readSqlString($sql, $position);
            } else {
                $end = $position + strcspn($sql, ',)', $position);
                $token = trim(substr($sql, $position, $end - $position));
                $tuple[] = strtoupper($token) === 'NULL' ? null : $token;
                $position = $end;
            }
            $position += strspn($sql, " \t\r\n", $position);
            $separator = $sql[$position++] ?? '';
            if ($separator === ')') {
                break;
            }
            if ($separator !== ',') {
                throw new InvalidArgumentException('The SQL file has a malformed INSERT statement.');
            }
        }
        $tuples[] = $tuple;
    }

    return $tuples;
}

// Extracts officer rows from INSERT statements without executing anything in the file.
function parseOfficerSqlDump(string $sql): array
{
    $tableColumns = OFFICER_COLUMNS;
    if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?officers`?\s*\((.*?)\)\s*(?:ENGINE|;)/is', $sql, $create)) {
        preg_match_all('/^\s*`(\w+)`/m', $create[1], $definedColumns);
        if ($definedColumns[1]) {
            $tableColumns = $definedColumns[1];
        }
    }

    $rows = [];
    preg_match_all(
        '/INSERT\s+(?:IGNORE\s+)?INTO\s+`?officers`?\s*(?:\(([^)]*)\))?\s*VALUES/i',
        $sql,
        $inserts,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    );
    foreach ($inserts as $insert) {
        $columns = isset($insert[1]) && $insert[1][0] !== ''
            ? array_map(static fn(string $column): string => trim($column, " `\t\r\n"), explode(',', $insert[1][0]))
            : $tableColumns;
        foreach (readSqlTuples($sql, $insert[0][1] + strlen($insert[0][0])) as $tuple) {
            if (count($tuple) !== count($columns)) {
                throw new InvalidArgumentException('An officer row in the SQL file does not match its column list.');
            }
            $rows[] = array_combine($columns, $tuple);
        }
    }

    return $rows;
}

function importDate(?string $value): ?string
{
    $value = trim((string) $value);
    $date = DateTime::createFromFormat('!Y-m-d', $value);

    return $date && $date->format('Y-m-d') === $value ? $value : null;
}

function importText(?string $value, int $maxLength): ?string
{
    $value = trim((string) $value);

    return $value === '' ? null : mb_substr($value, 0, $maxLength);
}

// Applies the same rules as the officer form; returns [records, skipped-row messages].
function normalizeImportedOfficers(array $rows, array $allowedRoles): array
{
    $records = [];
    $skipped = [];
    foreach ($rows as $index => $row) {
        $name = trim((string) ($row['name'] ?? ''));
        $role = trim((string) ($row['tungkulin'] ?? ''));
        $role = LEGACY_OFFICER_ROLES[$role] ?? $role;
        $label = 'Row ' . ($index + 1) . ($name !== '' ? " ({$name})" : '');
        if ($name === '' || mb_strlen($name) > 150) {
            $skipped[] = "{$label}: missing or too-long name.";
            continue;
        }
        if (!in_array($role, $allowedRoles, true)) {
            $skipped[] = "{$label}: unknown tungkulin \"{$role}\".";
            continue;
        }
        $number = filter_var($row['masterlist_number'] ?? null, FILTER_VALIDATE_INT);
        $id = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT);
        $records[] = [
            'id' => is_int($id) && $id > 0 ? $id : null,
            'masterlist_number' => is_int($number) && $number > 0 ? $number : null,
            'name' => $name,
            'tungkulin' => $role,
            'oath_taking_date' => importDate($row['oath_taking_date'] ?? null),
            'oath_taking_text' => importText($row['oath_taking_text'] ?? null, 50),
            'ndi_signing_date' => importDate($row['ndi_signing_date'] ?? null),
            'ndi_signing_text' => importText($row['ndi_signing_text'] ?? null, 50),
            'is_active' => (string) ($row['is_active'] ?? '1') === '0' ? 0 : 1,
            'birthday' => importDate($row['birthday'] ?? null),
            'wedding_anniversary' => importDate($row['wedding_anniversary'] ?? null),
            'contact_telegram' => importText($row['contact_telegram'] ?? null, 50),
        ];
    }

    return [$records, $skipped];
}

// Replace keeps the file's IDs; add appends new rows. A Blg. number already in use is cleared rather than failing the import.
function importOfficers(PDO $pdo, array $records, bool $replace): int
{
    $pdo->beginTransaction();
    try {
        if ($replace) {
            $pdo->exec('DELETE FROM officers');
            $usedNumbers = [];
        } else {
            $usedNumbers = array_map('intval', $pdo->query(
                'SELECT masterlist_number FROM officers WHERE masterlist_number IS NOT NULL'
            )->fetchAll(PDO::FETCH_COLUMN));
        }
        $columns = $replace ? OFFICER_COLUMNS : array_slice(OFFICER_COLUMNS, 1);
        $statement = $pdo->prepare(
            'INSERT INTO officers (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')'
        );
        foreach ($records as $record) {
            if ($record['masterlist_number'] !== null && in_array($record['masterlist_number'], $usedNumbers, true)) {
                $record['masterlist_number'] = null;
            }
            if ($record['masterlist_number'] !== null) {
                $usedNumbers[] = $record['masterlist_number'];
            }
            $statement->execute(array_intersect_key($record, array_flip($columns)));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return count($records);
}
