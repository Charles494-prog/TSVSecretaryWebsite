<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');
require __DIR__ . '/db.php';
if (($_GET['action'] ?? '') === 'ping') {
    $connected = false;
    try {
        $connected = $pdo !== null && $pdo->query('SELECT 1')->fetchColumn() == 1;
    } catch (PDOException) {
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['db' => $connected]);
    exit;
}
if ($pdo === null) {
    if (str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'No database connection. Start MySQL in the XAMPP Control Panel and try again.']);
        exit;
    }
    require __DIR__ . '/offline.php';
    exit;
}
require __DIR__ . '/auth.php';
sendSecurityHeaders();
require __DIR__ . '/docx.php';
require __DIR__ . '/docx_templates.php';
require __DIR__ . '/suguan.php';
require __DIR__ . '/monthly_reports.php';
require __DIR__ . '/directory.php';
require __DIR__ . '/import_export.php';

// Brings an older database up to date so no migration file has to be run by hand. The checks run once;
// afterwards a stored schema version skips them (one quick query instead of several on every request).
// Raise SCHEMA_VERSION whenever the upgrade steps below change, so every database runs them once more.
const SCHEMA_VERSION = '6';
try {
    $schemaVersion = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = 'schema_version'")->fetchColumn();
} catch (PDOException) {
    $schemaVersion = false;
}
if ($schemaVersion !== SCHEMA_VERSION && $usingTurso) try {
    // Turso (SQLite): the tables are made in their final form, so there is nothing to upgrade.
    $pdo->ensureSchema();
    dropMemoryTables($pdo);
    authEnsureTables($pdo, true);
    ensureSuperadmin($pdo);
    $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('schema_version', :version)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute(['version' => SCHEMA_VERSION]);
} catch (PDOException $e) {
    error_log('Schema setup failed: ' . $e->getMessage());
} elseif ($schemaVersion !== SCHEMA_VERSION) try {
    if (!$pdo->query("SHOW COLUMNS FROM officers LIKE 'wedding_anniversary'")->fetch()) {
        $pdo->exec('ALTER TABLE officers ADD COLUMN wedding_anniversary DATE DEFAULT NULL AFTER birthday');
    }
    $settingColumn = $pdo->query("SHOW COLUMNS FROM app_settings LIKE 'setting_value'")->fetch();
    if ($settingColumn && strtolower((string) $settingColumn['Type']) === 'varchar(150)') {
        $pdo->exec("ALTER TABLE app_settings MODIFY setting_value VARCHAR(5000) NOT NULL DEFAULT ''");
    }
    dropMemoryTables($pdo);
    authEnsureTables($pdo, false);
    ensureSuperadmin($pdo);
    $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('schema_version', :version)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute(['version' => SCHEMA_VERSION]);
} catch (PDOException $e) {
    error_log('Schema upgrade failed: ' . $e->getMessage());
}

startSecureSession($pdo);
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
// Everything below needs a signed-in account (email + password + emailed code).
$currentUser = requireSignIn($pdo);
$isSuperadmin = $currentUser['role'] === 'superadmin';
// Accounts (superadmin only): the actions run here, the page is drawn inside the normal layout.
$accountMessage = null;
if (($_GET['action'] ?? '') === 'accounts') {
    if (!$isSuperadmin) {
        header('Location: index.php');
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $accountMessage = handleAccountAction($pdo, $currentUser);
    }
}

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function displayDate(?string $date): string
{
    if (!$date) {
        return '—';
    }

    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    return $parsed ? $parsed->format('F j, Y') : '—';
}

function normalizeFullDate(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    foreach (['!Y-m-d', '!F j, Y', '!F d,Y', '!F d, Y', '!F j Y', '!M j, Y', '!M d,Y', '!M d, Y', '!M j Y'] as $format) {
        $date = DateTime::createFromFormat($format, $value);
        if ($date) {
            $errors = DateTime::getLastErrors();
            if ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) {
                return $date->format('Y-m-d');
            }
        }
    }

    return null;
}

function displayRecordDate(?string $date, ?string $sourceText): string
{
    if ($sourceText !== null && trim($sourceText) !== '') {
        return $sourceText;
    }

    return displayDate($date);
}

function validDate(string $value, string $label, array &$errors, bool $notInFuture = false): ?string
{
    if ($value === '') {
        return null;
    }

    $date = DateTime::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        $errors[] = "{$label} must be a valid date.";
        return null;
    }
    if ($notInFuture && $value > date('Y-m-d')) {
        $errors[] = "{$label} cannot be later than today.";
        return null;
    }

    return $value;
}

if (($_GET['action'] ?? '') === 'suguan') {
    header('Location: index.php#generator');
    exit;
}
$allowedActions = ['list', 'form', 'settings', 'generator', 'accounts', 'suguan_data', 'export_xlsx', 'export_sql', 'import_review'];
$allowedRoles = [
    'Locale TSV Lead',
    'Locale TSV Assistant Lead',
    'Locale TSV Secretary',
    'Locale TSV Officer',
];
$printForms = [
    'weekly' => [
        'pagpapalaganp' => 'Pagpapalaganap Form',
        'weekly_attendance' => 'Weekly Meeting Attendance Form',
        'meeting_agenda' => 'Meeting Agenda Form',
        'suguan' => 'Suguan',
        'tsv_checklist' => 'TSV Checklist sa Pagtupad',
        'resumen_form6' => 'Resumen of TSV Form 6',
        'hymnfiles_deleted' => 'Patunay na nadelete ang hymnfiles',
        'forms_resumen' => 'Forms Resumen (Checklist ng mga Ipapasa sa Distrito)',
    ],
    'monthly' => [
        'monthly_attendance' => 'TSV Officers Monthly Pulong Attendance',
        'tsv_form3' => 'TSV Form 3',
        'directory_report' => 'Directory Report',
    ],
];
$availablePrintFormKeys = [
    'pagpapalaganp',
    'weekly_attendance',
    'meeting_agenda',
    'suguan',
    'tsv_checklist',
    'resumen_form6',
    'hymnfiles_deleted',
    'forms_resumen',
    'monthly_attendance',
    'tsv_form3',
    'directory_report',
];
$templateFiles = [
    'suguan' => __DIR__ . '/templates/Suguan ng mga Local TSV (Revised April 2026).docx',
    'tsv_checklist' => __DIR__ . '/templates/CHECKLIST NG TSV OFFICERS SA PAGSAMBA (Revised April 2026).docx',
    'monthly_attendance' => __DIR__ . '/templates/TSV OFFICERS MONTHLY MEETING ATTENDANCE (Template).docx',
    'resumen_form6' => __DIR__ . '/templates/TSV Form 6 Resumen (Revised).docx',
    'hymnfiles_deleted' => __DIR__ . '/templates/PATOTOO NA NADELETE NA ANG FILE.docx',
    'forms_resumen' => __DIR__ . '/templates/CHECKLIST NG MGA IPAPASA SA DISTRITO.docx',
    'salaysay' => __DIR__ . '/templates/salaysay ng hindi tatlo ang nakasugo.docx',
    'tsv_form3' => __DIR__ . '/templates/TSV Form 3_November 2025_FYM AVENUE.xlsx',
    'directory_report' => __DIR__ . '/templates/MKN_FYM AVENUE Directory Report  November.docx',
];
const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

function loadSettings(PDO $pdo): array
{
    $settings = [];
    foreach ($pdo->query('SELECT setting_key, setting_value FROM app_settings')->fetchAll() as $setting) {
        $settings[$setting['setting_key']] = $setting['setting_value'];
    }

    return $settings + [
        'locale_name' => '',
        'district_name' => '',
        'district_supervisor' => '',
        'district_tsv_overseer' => '',
        'webex_display_name' => '',
        'pastor' => '',
        'pd_tagasubaybay' => '',
        'tsv_lead' => '',
        'tsv_secretary' => '',
    ];
}

// A success message shown as a toast after the next page load.
function flash(string $title, string $message = '', string $type = 'success'): void
{
    $_SESSION['toast'] = ['title' => $title, 'message' => $message, 'type' => $type];
}

// Blg. numbers are automatic: 1, 2, 3... in the current order, with no gaps.
function renumberOfficers(PDO $pdo): void
{
    // Works on MySQL and SQLite alike: clear the numbers, then hand them out again in order.
    $ids = $pdo->query('SELECT id FROM officers ORDER BY masterlist_number IS NULL, masterlist_number, id')->fetchAll(PDO::FETCH_COLUMN);
    $pdo->exec('UPDATE officers SET masterlist_number = NULL');
    $update = $pdo->prepare('UPDATE officers SET masterlist_number = :n WHERE id = :id');
    foreach ($ids as $index => $id) {
        $update->execute(['n' => $index + 1, 'id' => (int) $id]);
    }
}

function sendDownload(string $filename, string $contents, string $contentType): never
{
    // The page waits for this cookie to know the file arrived, then shows its "Form Generated!" style toast.
    $token = $_POST['download_token'] ?? $_GET['download_token'] ?? '';
    if (is_string($token) && preg_match('/^[a-z0-9]{6,32}$/', $token)) {
        setcookie('tsv_download', $token, ['path' => '/', 'samesite' => 'Lax']);
    }
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($contents));
    echo $contents;
    exit;
}

function sendJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
$action = $_GET['action'] ?? 'list';
if (!is_string($action) || !in_array($action, $allowedActions, true)) {
    $action = 'list';
}
if ($action === 'import_review' && !is_array($_SESSION['import_preview'] ?? null)) {
    $action = 'list';
}
if ($action === 'suguan_data') {
    sendJson(suguanWeekPayload($pdo, suguanWeekStart(is_string($_GET['week'] ?? null) ? $_GET['week'] : null)));
}
if ($action === 'export_xlsx') {
    sendDownload('tsv-officer-masterlist.xlsx', officersXlsx(officersOrderedForExport($pdo)), XLSX_MIME);
}
if ($action === 'export_sql') {
    // Streamed straight to the browser: with photos the backup can be far larger than the officer records alone.
    $token = $_GET['download_token'] ?? '';
    if (is_string($token) && preg_match('/^[a-z0-9]{6,32}$/', $token)) {
        setcookie('tsv_download', $token, ['path' => '/', 'samesite' => 'Lax']);
    }
    set_time_limit(300);
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="tsv-backup-' . date('Y-m-d') . '.sql"');
    writeBackupSql($pdo, static function (string $chunk): void {
        echo $chunk;
    });
    exit;
}
$errors = [];
$expectsJson = str_contains(
    strtolower($_SERVER['HTTP_ACCEPT'] ?? ''),
    'application/json'
) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
$toast = isset($_SESSION['toast']) && is_array($_SESSION['toast']) ? $_SESSION['toast'] : null;
unset($_SESSION['toast']);
$search = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > iniBytes('post_max_size')) {
    $tooLarge = 'That upload is larger than the server accepts (' . formatBytes(iniBytes('post_max_size')) . ').';
    if ($expectsJson) {
        sendJson(['success' => false, 'message' => $tooLarge], 413);
    }
    flash('Upload Too Large', $tooLarge, 'error');
    header('Location: index.php');
    exit;
}
// A sign-in form posted again after the account is already signed in (double submit, back button, resubmit):
// just open the app instead of failing the token check.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth_step'])) {
    header('Location: index.php', true, 303);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (!is_string($token) || !is_string($sessionToken) || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
        http_response_code(400);
        if ($expectsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Invalid request token. Please reload the page and try again.']);
            exit;
        }
        exit('Invalid request token. Please reload the page and try again.');
    }

    $postAction = $_POST['action'] ?? '';
    if (!is_string($postAction)) {
        $postAction = '';
    }
    if ($postAction === 'import_preview') {
        $upload = $_FILES['import_file'] ?? null;
        $mode = ($_POST['import_mode'] ?? '') === 'replace' ? 'replace' : 'add';
        try {
            if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('Choose a .sql backup file to import.');
            }
            set_time_limit(300);
            $contents = (string) file_get_contents($upload['tmp_name']);
            [$records, $skipped] = normalizeImportedOfficers(parseOfficerSqlDump($contents), $allowedRoles);
            if (!$records) {
                throw new InvalidArgumentException(
                    'No officer records were found in that file.' . ($skipped ? ' ' . implode(' ', array_slice($skipped, 0, 3)) : '')
                );
            }
            $_SESSION['import_preview'] = [
                'file' => mb_substr(basename((string) $upload['name']), 0, 120),
                'mode' => $mode,
                'records' => $records,
                'skipped' => $skipped,
            ];
            header('Location: index.php?action=import_review');
            exit;
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }
        $action = 'list';
    } elseif ($postAction === 'import_commit') {
        $preview = $_SESSION['import_preview'] ?? null;
        unset($_SESSION['import_preview']);
        if (!is_array($preview)) {
            $errors[] = 'There is no import waiting for confirmation. Please upload the file again.';
            $action = 'list';
        } else {
            $replace = $preview['mode'] === 'replace';
            $messages = [];
            try {
                set_time_limit(300);
                if ($preview['records']) {
                    $count = importOfficers($pdo, $preview['records'], $replace);
                    renumberOfficers($pdo);
                    $messages[] = $replace ? "{$count} officer records were restored." : "{$count} officer records were added.";
                }
                flash('Import Complete', implode(' ', $messages));
                header('Location: index.php');
                exit;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Import failed: ' . $e->getMessage());
                $errors[] = 'The import failed and no records were changed. The file may contain duplicate record IDs.';
                $action = 'list';
            }
        }
    } elseif ($postAction === 'import_cancel') {
        unset($_SESSION['import_preview']);
        flash('Import Cancelled', 'No records were changed.', 'info');
        header('Location: index.php');
        exit;
    } elseif ($postAction === 'save_suguan') {
        try {
            $monday = suguanWeekStart(is_string($_POST['week'] ?? null) ? $_POST['week'] : null);
            $services = sanitizeSuguanServices(json_decode((string) ($_POST['services'] ?? ''), true));
            saveSuguanWeek($pdo, $monday, $services);
            sendJson(['success' => true]);
        } catch (InvalidArgumentException $e) {
            sendJson(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            error_log('Save Suguan failed: ' . $e->getMessage());
            sendJson(['success' => false, 'message' => 'Could not save the Suguan. Please try again.'], 500);
        }
    } elseif ($postAction === 'align_suguan') {
        // Normalize: the week becomes the schedule last reported to the INC Directory (or the standard one).
        try {
            $monday = suguanWeekStart(is_string($_POST['week'] ?? null) ? $_POST['week'] : null);
            $services = sanitizeSuguanServices(json_decode((string) ($_POST['services'] ?? ''), true));
            $official = directoryOfficialSlots($pdo);
            sendJson(['success' => true, 'services' => suguanNormalizeToSchedule($services, $monday, $official ?? suguanDefaultSlots()), 'official' => $official !== null]);
        } catch (InvalidArgumentException $e) {
            sendJson(['success' => false, 'message' => $e->getMessage()], 422);
        }
    } elseif ($postAction === 'save_print_selection') {
        $rawSelectedForms = $_POST['print_forms'] ?? [];
        $selectedForms = [];
        if (is_array($rawSelectedForms)) {
            foreach ($rawSelectedForms as $formKey) {
                if (is_string($formKey) && in_array($formKey, $availablePrintFormKeys, true)) {
                    $selectedForms[] = $formKey;
                }
            }
        }
        $selectedForms = array_values(array_unique($selectedForms));

        try {
            savePrintSelections($pdo, $availablePrintFormKeys, $selectedForms);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true]);
            exit;
        } catch (Throwable $e) {
            error_log('Save print selections failed: ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Could not save print selections. Please try again.']);
            exit;
        }
    } elseif ($postAction === 'generate_masterlist') {
        try {
            $masterlist = $pdo->query(
                'SELECT * FROM officers ORDER BY masterlist_number IS NULL, masterlist_number ASC, is_active DESC, name ASC'
            )->fetchAll();
            $docx = generateOfficerMasterlistDocx($masterlist, __DIR__ . '/templates/masterlist.docx');
            header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
            header('Content-Disposition: attachment; filename="tsv-officer-masterlist.docx"');
            header('Content-Length: ' . strlen($docx));
            echo $docx;
            exit;
        } catch (Throwable $e) {
            error_log('Generate officer masterlist failed: ' . $e->getMessage());
            http_response_code(500);
            exit('Could not generate the officer masterlist DOCX. Please check the PHP error log and try again.');
        }
    } elseif ($postAction === 'generate_print_forms') {
        // A failed generate shows the problems where the form was: the pop-up, or the phone's generator page.
        $generatorReturn = ($_GET['action'] ?? '') === 'generator' ? 'generator' : 'list';
        $rawSelectedForms = $_POST['print_forms'] ?? [];
        $selectedForms = [];
        if (is_array($rawSelectedForms)) {
            foreach ($rawSelectedForms as $formKey) {
                if (is_string($formKey) && in_array($formKey, $availablePrintFormKeys, true)) {
                    $selectedForms[] = $formKey;
                }
            }
        }
        $selectedForms = array_values(array_unique($selectedForms));
        $rawMeetingDateTime = $_POST['meeting_datetime'] ?? '';
        $rawMeetingVenue = $_POST['meeting_venue'] ?? '';
        $meetingDateTime = is_string($rawMeetingDateTime) ? trim($rawMeetingDateTime) : '';
        $meetingVenue = is_string($rawMeetingVenue) ? trim($rawMeetingVenue) : '';
        $rawFirstPrayer = $_POST['first_prayer'] ?? '';
        $rawLastPrayer = $_POST['last_prayer'] ?? '';
        $rawLecture = $_POST['lecture'] ?? '';
        $firstPrayer = is_string($rawFirstPrayer) ? trim($rawFirstPrayer) : '';
        $lastPrayer = is_string($rawLastPrayer) ? trim($rawLastPrayer) : '';
        $lecture = is_string($rawLecture) ? trim($rawLecture) : '';
        // Karagdagang forms for the Forms Resumen: one per line.
        $rawExtraForms = $_POST['extra_forms'] ?? '';
        $extraForms = array_values(array_filter(
            array_map('trim', preg_split('/\R/', is_string($rawExtraForms) ? $rawExtraForms : '')),
            static fn(string $line): bool => $line !== ''
        ));
        $rawAgendaTopics = $_POST['agenda_topics'] ?? '';
        $agendaTopics = parseAgendaTopics(is_string($rawAgendaTopics) ? $rawAgendaTopics : '');
        // Recruit rows: a name plus an optional birthday and wedding date (neither later than today).
        $recruits = [];
        $recruitBirthdays = is_array($_POST['recruit_birthday'] ?? null) ? array_values($_POST['recruit_birthday']) : [];
        $recruitWeddings = is_array($_POST['recruit_wedding'] ?? null) ? array_values($_POST['recruit_wedding']) : [];
        $pastDate = static function (mixed $value): string {
            $value = is_string($value) ? trim($value) : '';
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            return $date && $date->format('Y-m-d') === $value && $value <= date('Y-m-d') ? $value : '';
        };
        foreach (is_array($_POST['recruit_name'] ?? null) ? array_values($_POST['recruit_name']) : [] as $index => $rawName) {
            $name = is_string($rawName) ? trim($rawName) : '';
            if ($name !== '') {
                $recruits[] = [
                    mb_substr($name, 0, 150),
                    $pastDate($recruitBirthdays[$index] ?? ''),
                    $pastDate($recruitWeddings[$index] ?? ''),
                ];
            }
        }
        $rawMonthlyDate = $_POST['monthly_date'] ?? '';
        $monthlyDate = is_string($rawMonthlyDate) ? trim($rawMonthlyDate) : '';
        $suguanMonday = suguanWeekStart(is_string($_POST['suguan_week'] ?? null) ? $_POST['suguan_week'] : null);
        $needsMeetingDetails = array_intersect(['weekly_attendance', 'meeting_agenda'], $selectedForms) !== [];
        $reportMonth = reportMonthStart(is_string($_POST['report_month'] ?? null) ? $_POST['report_month'] : null, new DateTimeZone('Asia/Manila'));
        $postedText = static fn(string $key): string => is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
        // Left blank, the Webex name falls back to the one saved under Signatories.
        $webexName = $postedText('webex_display_name') ?: (string) (loadSettings($pdo)['webex_display_name'] ?? '');
        $webexCount = $postedText('webex_count') === '' ? '0' : $postedText('webex_count');
        // The reason only applies when Webex was not used at all; left blank, it reads "Wala pong aktibidad".
        $webexReason = $webexCount === '0' ? ($postedText('webex_reason') ?: 'Wala pong aktibidad') : '';
        // Activity rows: name, purpose and date; a date from the picker prints as "November 15, 2026".
        $activities = [];
        $incompleteActivities = false;
        $postedList = static fn(string $key): array => is_array($_POST[$key] ?? null) ? array_values($_POST[$key]) : [];
        $activityPurposes = $postedList('activity_purpose');
        $activityDates = $postedList('activity_date');
        foreach ($postedList('activity_name') as $index => $rawName) {
            $row = array_map(
                static fn(mixed $value): string => is_string($value) ? trim($value) : '',
                [$rawName, $activityPurposes[$index] ?? '', $activityDates[$index] ?? '']
            );
            if ($webexCount === '0' || implode('', $row) === '') {
                continue;
            }
            if (in_array('', $row, true)) {
                $incompleteActivities = true;
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $row[2]);
            $row[2] = $date && $date->format('Y-m-d') === $row[2] ? $date->format('F j, Y') : $row[2];
            $activities[] = $row;
        }

        if (!$selectedForms) {
            $errors[] = 'Select at least one available form to generate.';
        }
        if ($needsMeetingDetails && ($meetingDateTime === '' || mb_strlen($meetingDateTime) > 150)) {
            $errors[] = 'Enter the weekly meeting day and time (150 characters or fewer).';
        }
        if ($needsMeetingDetails && ($meetingVenue === '' || mb_strlen($meetingVenue) > 150)) {
            $errors[] = 'Enter the weekly meeting place (150 characters or fewer).';
        }
        if (in_array('monthly_attendance', $selectedForms, true) && ($monthlyDate === '' || mb_strlen($monthlyDate) > 150)) {
            $errors[] = 'Enter the monthly pulong date (150 characters or fewer).';
        }
        $recruitCount = filter_var($_POST['recruit_count'] ?? 0, FILTER_VALIDATE_INT);
        if (in_array('monthly_attendance', $selectedForms, true) && is_int($recruitCount) && count($recruits) < min($recruitCount, 20)) {
            $errors[] = min($recruitCount, 20) === 1 ? "Enter the recruit's name." : 'Enter the names of all ' . min($recruitCount, 20) . ' recruits.';
        }
        if (in_array('tsv_form3', $selectedForms, true)) {
            if (mb_strlen($webexName) > 150 || mb_strlen($webexCount) > 50 || mb_strlen($webexReason) > 250) {
                $errors[] = 'The Webex display name must be 150 characters or fewer, with a short usage count and reason.';
            }
            if (!ctype_digit($webexCount)) {
                $errors[] = 'Bilang kung ilang beses ginamit must be a whole number.';
            } elseif ($incompleteActivities || count($activities) < min((int) $webexCount, 12)) {
                $errors[] = 'Fill in the Aktibidad, Layunin and Petsa for all ' . min((int) $webexCount, 12) . ' TSV Form 3 activities.';
            }
            if (count($activities) > FORM3_ACTIVITY_ROWS[1] - FORM3_ACTIVITY_ROWS[0] + 1) {
                $errors[] = 'TSV Form 3 has room for 12 activities.';
            }
            foreach ($activities as $activity) {
                if (max(array_map('mb_strlen', $activity)) > 250) {
                    $errors[] = 'Each activity, purpose and date must be 250 characters or fewer.';
                    break;
                }
            }
        }
        if (in_array('meeting_agenda', $selectedForms, true)) {
            // Both prayers are optional; left blank, the line prints as just "Unang Panalangin:".
            if (mb_strlen($firstPrayer) > 150) {
                $errors[] = 'Unang Panalangin must be 150 characters or fewer.';
            }
            if (mb_strlen($lastPrayer) > 150) {
                $errors[] = 'Huling Panalangin must be 150 characters or fewer.';
            }
            if (mb_strlen($lecture) > 150) {
                $errors[] = 'Pagpapayo/Lektura must be 150 characters or fewer.';
            }
            if (!$agendaTopics) {
                $errors[] = 'Enter at least one agenda topic. Leave a blank line between topics.';
            }
            foreach ($agendaTopics as $topic) {
                if (max(array_map('mb_strlen', array_merge([$topic['title']], $topic['points']))) > 500) {
                    $errors[] = 'Each agenda title or point must be 500 characters or fewer.';
                    break;
                }
            }
        }

        if (in_array('forms_resumen', $selectedForms, true)) {
            foreach ($extraForms as $extraForm) {
                if (mb_strlen($extraForm) > 200) {
                    $errors[] = 'Each karagdagang form must be 200 characters or fewer.';
                    break;
                }
            }
        }

        $directoryForm = null;
        if (in_array('directory_report', $selectedForms, true)) {
            try {
                $directoryForm = directoryFromForm();
            } catch (InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if (!$errors) {
            try {
                savePrintSelections($pdo, $availablePrintFormKeys, $selectedForms);
                $pdo->beginTransaction();
                $saveMeetingSetting = $pdo->prepare(
                    'INSERT INTO app_settings (setting_key, setting_value)
                     VALUES (:setting_key, :setting_value)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
                );
                $meetingSettings = array_filter([
                    'weekly_meeting_datetime' => $meetingDateTime,
                    'weekly_meeting_venue' => $meetingVenue,
                ], static fn(string $value): bool => $value !== '');
                // The recruit list is remembered for the next monthly attendance until it is changed.
                if (in_array('forms_resumen', $selectedForms, true)) {
                    $meetingSettings['forms_resumen_extra'] = implode("\n", $extraForms);
                }
                if (in_array('monthly_attendance', $selectedForms, true)) {
                    $meetingSettings['monthly_recruits'] = json_encode($recruits, JSON_UNESCAPED_UNICODE);
                }
                foreach ($meetingSettings as $settingKey => $settingValue) {
                    $saveMeetingSetting->execute([
                        'setting_key' => $settingKey,
                        'setting_value' => $settingValue,
                    ]);
                }
                $pdo->commit();

                $officers = $pdo->query(
                    'SELECT * FROM officers WHERE is_active = 1
                     ORDER BY masterlist_number IS NULL, masterlist_number ASC, name ASC'
                )->fetchAll();
                $signatories = loadSettings($pdo);
                $period = weeklyReportPeriod(new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')));
                $generatedDocuments = [];
                if (in_array('pagpapalaganp', $selectedForms, true)) {
                    $generatedDocuments['weekly-monitoring-pagpapalaganap.docx'] = generateWeeklyMonitoringDocx(
                        $officers,
                        (int) $pdo->query('SELECT COUNT(*) FROM officers')->fetchColumn(),
                        $signatories,
                        $period['period'],
                        $period['week_number'],
                        __DIR__ . '/templates/weekly-monitoring.docx'
                    );
                }
                if (in_array('weekly_attendance', $selectedForms, true)) {
                    $generatedDocuments['weekly-meeting-attendance.docx'] = generateWeeklyAttendanceDocx(
                        $officers,
                        $signatories,
                        $meetingDateTime,
                        __DIR__ . '/templates/weekly-attendance.docx'
                    );
                }
                if (in_array('meeting_agenda', $selectedForms, true)) {
                    $generatedDocuments['weekly-meeting-agenda.docx'] = generateWeeklyAgendaDocx(
                        $meetingDateTime,
                        $meetingVenue,
                        $firstPrayer,
                        $lastPrayer,
                        $lecture,
                        $agendaTopics,
                        $signatories,
                        __DIR__ . '/templates/weekly-agenda.docx'
                    );
                }
                if (array_intersect(['suguan', 'tsv_checklist'], $selectedForms)) {
                    $monday = $suguanMonday;
                    $weekServices = loadSuguanWeek($pdo, $monday)['services'];
                    if (in_array('suguan', $selectedForms, true)) {
                        $generatedDocuments['suguan-' . $monday->format('Y-m-d') . '.docx'] = generateSuguanDocx(
                            $weekServices,
                            $signatories,
                            $templateFiles['suguan']
                        );
                    }
                    if (in_array('tsv_checklist', $selectedForms, true)) {
                        $generatedDocuments['tsv-checklist-' . $monday->format('Y-m-d') . '.docx'] = generateChecklistDocx(
                            $weekServices,
                            $signatories,
                            $templateFiles['tsv_checklist']
                        );
                    }
                }
                if (in_array('resumen_form6', $selectedForms, true)) {
                    $resumenServices = loadSuguanWeek($pdo, $suguanMonday)['services'];
                    $generatedDocuments['tsv-form6-resumen-' . $suguanMonday->format('Y-m-d') . '.docx'] = generateResumenDocx(
                        $suguanMonday,
                        $resumenServices,
                        $signatories,
                        $templateFiles['resumen_form6']
                    );
                    // One salaysay file goes with the Resumen, with a page for every worship service marked "Hindi".
                    $missingOperators = incompleteWorshipServices($resumenServices);
                    if ($missingOperators) {
                        $generatedDocuments['salaysay-' . $suguanMonday->format('Y-m-d') . '.docx'] = generateSalaysayDocx(
                            $missingOperators,
                            $signatories,
                            $templateFiles['salaysay']
                        );
                    }
                }
                if (in_array('hymnfiles_deleted', $selectedForms, true)) {
                    $generatedDocuments['patotoo-nadelete-hymnfiles.docx'] = generateHymnfilesDeletedDocx(
                        $signatories,
                        new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')),
                        $templateFiles['hymnfiles_deleted']
                    );
                }
                $monthSlug = strtolower($reportMonth->format('Y-m'));
                if (in_array('tsv_form3', $selectedForms, true)) {
                    $schedule = monthlyScheduleServices($pdo, $reportMonth);
                    $generatedDocuments["tsv-form3-{$monthSlug}.xlsx"] = generateForm3Xlsx([
                        'month' => $reportMonth,
                        'counts' => form3OfficerCounts($pdo->query('SELECT * FROM officers')->fetchAll(), $reportMonth),
                        'weekend_services' => count(array_filter(
                            $schedule,
                            static fn(array $service): bool => isSuguanWorship($service)
                                && (int) (new DateTimeImmutable($service['date']))->format('N') >= 6
                        )),
                        'webex_name' => $webexName,
                        'webex_count' => $webexCount,
                        'webex_reason' => $webexReason,
                        'activities' => $activities ?: [['Wala po', '', '']],
                    ], $signatories, $templateFiles['tsv_form3']);
                }
                if (in_array('directory_report', $selectedForms, true)) {
                    // With "Update data in the INC Directory" off, the report confirms what the Directory has (all Tama).
                    $directoryBase = directoryBaseline($pdo, $reportMonth);
                    $directoryReported = $directoryForm ?? $directoryBase;
                    $generatedDocuments["directory-report-{$monthSlug}.docx"] = generateDirectoryReportDocx(
                        directoryCompareSchedule($directoryBase, $directoryReported),
                        directoryLocationDiffers($directoryReported, $directoryBase),
                        $directoryReported,
                        $signatories,
                        $templateFiles['directory_report']
                    );
                }
                if (in_array('monthly_attendance', $selectedForms, true)) {
                    $generatedDocuments['monthly-pulong-attendance.docx'] = generateMonthlyAttendanceDocx(
                        $officers,
                        $recruits,
                        $signatories,
                        $monthlyDate,
                        $templateFiles['monthly_attendance']
                    );
                }
                if (in_array('forms_resumen', $selectedForms, true)) {
                    // Everything passed to the Distrito, except the Suguan and the TSV Checklist sa Pagtupad.
                    $resumenItems = [];
                    foreach ([
                        'resumen_form6' => 'Resumen ng TSV Form 6',
                        'meeting_agenda' => 'Agenda sa Weekly Pulong ng mga TSV Officers',
                        'weekly_attendance' => 'Attendance sa Weekly Pulong ng mga TSV Officers',
                        'hymnfiles_deleted' => 'Patotoo na deleted na ang mga hymn files',
                        'pagpapalaganp' => 'Weekly Monitoring sa Pagpapalaganap',
                        'monthly_attendance' => 'Attendance sa Monthly Pulong ng mga TSV Officers',
                        'tsv_form3' => 'TSV Form 3',
                        'directory_report' => 'Directory Report',
                    ] as $formKey => $formLabel) {
                        if (in_array($formKey, $selectedForms, true)) {
                            $resumenItems[] = $formLabel;
                        }
                    }
                    foreach (array_keys($generatedDocuments) as $filename) {
                        if (str_starts_with($filename, 'salaysay-')) {
                            array_splice($resumenItems, 1, 0, ['Salaysay ng hindi tatlo ang nakasugo']);
                            break;
                        }
                    }
                    $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
                    $generatedDocuments['forms-resumen-' . $today->format('Y-m-d') . '.docx'] = generateFormsResumenDocx(
                        $resumenItems,
                        $extraForms,
                        $signatories,
                        $today,
                        $templateFiles['forms_resumen']
                    );
                }
                // The Directory Report is remembered only once every form has been made.
                if (in_array('directory_report', $selectedForms, true) && $directoryForm !== null) {
                    try {
                        directorySave($pdo, $reportMonth, $directoryBase, $directoryForm);
                        $changed = array_filter(directoryCompareSchedule($directoryBase, $directoryForm), static fn(array $row): bool => $row['changed']);
                        if ($changed && ($slots = directoryOfficialSlots($pdo))) {
                            suguanApplyScheduleFrom($pdo, suguanWeekStart(null), $slots);
                        }
                    } catch (Throwable $e) {
                        error_log('Saving the Directory Report failed: ' . $e->getMessage());
                    }
                }
                if (count($generatedDocuments) === 1) {
                    $filename = array_key_first($generatedDocuments);
                    sendDownload($filename, $generatedDocuments[$filename], str_ends_with($filename, '.xlsx') ? XLSX_MIME : DOCX_MIME);
                }
                sendDownload('tsv-forms.zip', zipFiles($generatedDocuments), 'application/zip');
            } catch (InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
                $action = $generatorReturn;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Generate weekly forms failed: ' . $e->getMessage());
                $errors[] = 'Could not generate the selected weekly DOCX forms. Please check the PHP error log and try again.';
                $action = $generatorReturn;
            }
        } else {
            $action = $generatorReturn;
        }
    } elseif ($postAction === 'delete') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($id) || $id < 1) {
            $errors[] = 'A valid officer ID is required.';
        } else {
            try {
                $nameStatement = $pdo->prepare('SELECT name FROM officers WHERE id = :id');
                $nameStatement->execute(['id' => $id]);
                $deletedName = $nameStatement->fetchColumn();
                $statement = $pdo->prepare('DELETE FROM officers WHERE id = :id');
                $statement->execute(['id' => $id]);
                if ($statement->rowCount() > 0) {
                    renumberOfficers($pdo);
                    flash('Officer Deleted', $deletedName . ' was removed from the masterlist.');
                } else {
                    flash('Not Found', 'That officer record no longer exists.', 'error');
                }
            } catch (PDOException $e) {
                error_log('Delete officer failed: ' . $e->getMessage());
                $errors[] = 'Could not delete the officer record. Please try again.';
                $action = 'list';
            }
            if (!$errors) {
                header('Location: index.php');
                exit;
            }
        }
        $action = 'list';
    } elseif ($postAction === 'toggle_status') {
        $isAjaxRequest = $expectsJson;
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($id) || $id < 1) {
            if ($isAjaxRequest) {
                http_response_code(400);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'A valid officer ID is required.']);
                exit;
            }
            $errors[] = 'A valid officer ID is required.';
        } else {
            try {
                $statement = $pdo->prepare(
                    'UPDATE officers SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = :id'
                );
                $statement->execute(['id' => $id]);
                $statusStatement = $pdo->prepare('SELECT is_active FROM officers WHERE id = :id');
                $statusStatement->execute(['id' => $id]);
                $newStatus = $statusStatement->fetchColumn();
                if ($newStatus === false) {
                    if ($isAjaxRequest) {
                        http_response_code(404);
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode(['success' => false, 'message' => 'That officer record was not found.']);
                        exit;
                    }
                    flash('Not Found', 'That officer record no longer exists.', 'error');
                } else {
                    $newStatus = (int) $newStatus;
                    if ($isAjaxRequest) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode(['success' => true, 'is_active' => $newStatus]);
                        exit;
                    }
                    flash('Status Updated');
                }
            } catch (PDOException $e) {
                error_log('Toggle officer status failed: ' . $e->getMessage());
                if ($isAjaxRequest) {
                    http_response_code(500);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'message' => 'Could not update officer status. Please try again.']);
                    exit;
                }
                $errors[] = 'Could not update officer status. Please try again.';
            }
            if (!$errors && !$isAjaxRequest) {
                header('Location: index.php');
                exit;
            }
        }
        $action = 'list';
    } elseif ($postAction === 'save_signatories') {
        $signatoryFields = [
            'pastor' => 'Pastor',
            'pd_tagasubaybay' => 'PD Tagasubaybay',
            'tsv_lead' => 'TSV Lead',
            'assistant_tsv_lead' => 'Assistant TSV Lead',
            'tsv_secretary' => 'TSV Secretary',
            'locale_name' => 'Locale',
            'district_name' => 'District',
            'district_supervisor' => 'Tagapangasiwa ng Distrito',
            'district_tsv_overseer' => 'District TSV Overseer',
            'webex_display_name' => 'Webex display name',
            'pd_tagasubaybay_birthday' => 'PD Tagasubaybay birthday',
            'pd_tagasubaybay_wedding' => 'PD Tagasubaybay wedding anniversary',
        ];
        $signatoryValues = [];
        foreach ($signatoryFields as $key => $label) {
            $rawValue = $_POST[$key] ?? '';
            $value = is_string($rawValue) ? trim($rawValue) : '';
            if (mb_strlen($value) > 150) {
                $errors[] = "{$label} name must be 150 characters or fewer.";
            }
            if (str_starts_with($key, 'pd_tagasubaybay_')) {
                $value = validDate($value, $label, $errors, true) ?? '';
            }
            $signatoryValues[$key] = $value;
        }
        if (!$errors) {
            try {
                $pdo->beginTransaction();
                $statement = $pdo->prepare(
                    'INSERT INTO app_settings (setting_key, setting_value)
                     VALUES (:setting_key, :setting_value)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
                );
                foreach ($signatoryValues as $key => $value) {
                    $statement->execute(['setting_key' => $key, 'setting_value' => $value]);
                }
                $pdo->commit();
                flash('Signatory Saved', 'The names on generated forms were updated.');
                header('Location: index.php?action=settings');
                exit;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Save signatories failed: ' . $e->getMessage());
                $errors[] = 'Could not save the signatory names. Please try again.';
            }
        }
        $action = 'settings';
    } elseif ($postAction === 'save') {
        $rawId = $_POST['id'] ?? '';
        $idValue = is_string($rawId) || is_int($rawId) ? (string) $rawId : '';
        $id = $idValue === '' ? null : filter_var($idValue, FILTER_VALIDATE_INT);
        $rawName = $_POST['name'] ?? '';
        $rawTungkulin = $_POST['tungkulin'] ?? '';
        $rawContact = $_POST['contact_telegram'] ?? '';
        $name = is_string($rawName) ? trim($rawName) : '';
        $tungkulin = is_string($rawTungkulin) ? trim($rawTungkulin) : '';
        $rawContactValue = is_string($rawContact) ? trim($rawContact) : '';
        $contactTelegram = $rawContactValue === '' ? null : $rawContactValue;
        $isActive = ($_POST['is_active'] ?? '') === '1' ? 1 : 0;
        $rawOath = is_string($_POST['oath_taking_date'] ?? '') ? (string) $_POST['oath_taking_date'] : '';
        $rawNdi = is_string($_POST['ndi_signing_date'] ?? '') ? (string) $_POST['ndi_signing_date'] : '';
        $rawBirthday = is_string($_POST['birthday'] ?? '') ? (string) $_POST['birthday'] : '';
        $rawWedding = is_string($_POST['wedding_anniversary'] ?? '') ? (string) $_POST['wedding_anniversary'] : '';
        $oathText = trim($rawOath) === '' ? null : trim($rawOath);
        $ndiText = trim($rawNdi) === '' ? null : trim($rawNdi);
        $oathDate = $oathText === null ? null : normalizeFullDate($oathText);
        $ndiDate = $ndiText === null ? null : normalizeFullDate($ndiText);
        $birthday = validDate($rawBirthday, 'Birthday', $errors, true);
        $weddingAnniversary = validDate($rawWedding, 'Wedding anniversary', $errors, true);

        if ($idValue !== '' && (!is_int($id) || $id < 1)) {
            $errors[] = 'A valid officer ID is required.';
        }
        if ($name === '' || mb_strlen($name) > 150) {
            $errors[] = 'Name is required and must be 150 characters or fewer.';
        }
        if (mb_strlen($rawContactValue) > 50) {
            $errors[] = 'Telegram contact must be 50 characters or fewer.';
        }
        if (($oathText !== null && mb_strlen($oathText) > 50) || ($ndiText !== null && mb_strlen($ndiText) > 50)) {
            $errors[] = 'Date values must be 50 characters or fewer.';
        }
        if (!in_array($tungkulin, $allowedRoles, true)) {
            $errors[] = 'Choose a valid tungkulin from the available roles.';
        }

        if (!$errors) {
            try {
                if ($id === null) {
                    $masterlistNumber = (int) $pdo->query('SELECT COALESCE(MAX(masterlist_number), 0) + 1 FROM officers')->fetchColumn();
                    $statement = $pdo->prepare(
                        'INSERT INTO officers (masterlist_number, name, tungkulin, oath_taking_date, oath_taking_text,
                             ndi_signing_date, ndi_signing_text, birthday, wedding_anniversary, contact_telegram, is_active)
                         VALUES (:masterlist_number, :name, :tungkulin, :oath_taking_date, :oath_taking_text,
                             :ndi_signing_date, :ndi_signing_text, :birthday, :wedding_anniversary, :contact_telegram, :is_active)'
                    );
                    $statement->execute([
                        'masterlist_number' => $masterlistNumber,
                        'name' => $name,
                        'tungkulin' => $tungkulin,
                        'oath_taking_date' => $oathDate,
                        'oath_taking_text' => $oathText,
                        'ndi_signing_date' => $ndiDate,
                        'ndi_signing_text' => $ndiText,
                        'is_active' => $isActive,
                        'birthday' => $birthday,
                        'wedding_anniversary' => $weddingAnniversary,
                        'contact_telegram' => $contactTelegram,
                    ]);
                    flash('Officer Added', $name . ' was added as Blg. ' . $masterlistNumber . '.');
                } else {
                    $statement = $pdo->prepare(
                        'UPDATE officers
                         SET name = :name, tungkulin = :tungkulin,
                             oath_taking_date = :oath_taking_date, oath_taking_text = :oath_taking_text,
                             ndi_signing_date = :ndi_signing_date, ndi_signing_text = :ndi_signing_text,
                             is_active = :is_active, birthday = :birthday,
                             wedding_anniversary = :wedding_anniversary, contact_telegram = :contact_telegram
                         WHERE id = :id'
                    );
                    $statement->execute([
                        'name' => $name,
                        'tungkulin' => $tungkulin,
                        'oath_taking_date' => $oathDate,
                        'oath_taking_text' => $oathText,
                        'ndi_signing_date' => $ndiDate,
                        'ndi_signing_text' => $ndiText,
                        'is_active' => $isActive,
                        'birthday' => $birthday,
                        'wedding_anniversary' => $weddingAnniversary,
                        'contact_telegram' => $contactTelegram,
                        'id' => $id,
                    ]);
                    flash('Officer Updated', $name . '\'s record was saved.');
                }

                header('Location: index.php');
                exit;
            } catch (PDOException $e) {
                error_log('Save officer failed: ' . $e->getMessage());
                $errors[] = 'Could not save the officer record. Please try again.';
            }
        }

        $action = 'form';
    } else {
        $errors[] = 'Unknown form action.';
        $action = 'list';
    }
}

$editingOfficer = null;

if ($action === 'form' && $_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['id'])) {
    $rawEditId = $_GET['id'];
    $id = is_string($rawEditId) || is_int($rawEditId) ? filter_var($rawEditId, FILTER_VALIDATE_INT) : false;
    if (!is_int($id) || $id < 1) {
        $errors[] = 'A valid officer ID is required.';
    } else {
        $statement = $pdo->prepare('SELECT * FROM officers WHERE id = :id');
        $statement->execute(['id' => $id]);
        $editingOfficer = $statement->fetch();
        if (!$editingOfficer) {
            $errors[] = 'Officer record not found.';
        }
    }
}

$officers = [];
$statusFilter = 'all';
if ($action === 'list') {
    // Records added before numbering was automatic (or restored without a number) get one here.
    if ((int) $pdo->query('SELECT COUNT(*) FROM officers WHERE masterlist_number IS NULL')->fetchColumn() > 0) {
        renumberOfficers($pdo);
    }
    $rawSearch = $_GET['search'] ?? '';
    $search = is_string($rawSearch) ? trim($rawSearch) : '';
    if (mb_strlen($search) > 150) {
        $search = mb_substr($search, 0, 150);
    }
    $rawStatusFilter = $_GET['status'] ?? 'all';
    $statusFilter = is_string($rawStatusFilter) && in_array($rawStatusFilter, ['all', 'active', 'inactive'], true)
        ? $rawStatusFilter
        : 'all';
    $conditions = [];
    $parameters = [];
    if ($search !== '') {
        $conditions[] = '(name LIKE :name_search OR tungkulin LIKE :role_search)';
        $searchTerm = '%' . $search . '%';
        $parameters['name_search'] = $searchTerm;
        $parameters['role_search'] = $searchTerm;
    }
    if ($statusFilter !== 'all') {
        $conditions[] = 'is_active = :is_active';
        $parameters['is_active'] = $statusFilter === 'active' ? 1 : 0;
    }
    $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
    if ($where !== '') {
        $statement = $pdo->prepare(
            'SELECT * FROM officers' . $where
                . ' ORDER BY masterlist_number IS NULL, masterlist_number ASC, is_active DESC, name ASC'
        );
        $statement->execute($parameters);
        $officers = $statement->fetchAll();
    } else {
        $officers = $pdo->query(
            'SELECT * FROM officers ORDER BY masterlist_number IS NULL, masterlist_number ASC, is_active DESC, name ASC'
        )->fetchAll();
    }
}
$officerTotals = $pdo->query(
    'SELECT COUNT(*) AS total, COALESCE(SUM(is_active = 1), 0) AS active FROM officers'
)->fetch();
$officerTotals = [
    'total' => (int) $officerTotals['total'],
    'active' => (int) $officerTotals['active'],
];
$officerTotals['inactive'] = $officerTotals['total'] - $officerTotals['active'];

$today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
$birthdays = [];
if ($action === 'list') {
    $statement = $pdo->prepare(
        'SELECT name, birthday FROM officers
         WHERE is_active = 1 AND birthday IS NOT NULL AND MONTH(birthday) = :month
         ORDER BY DAY(birthday), name'
    );
    $statement->execute(['month' => (int) $today->format('n')]);
    foreach ($statement->fetchAll() as $row) {
        $birthday = new DateTimeImmutable($row['birthday']);
        $thisYear = $today->setDate((int) $today->format('Y'), (int) $birthday->format('n'), (int) $birthday->format('j'));
        $daysAway = (int) $today->diff($thisYear)->format('%r%a');
        $birthdays[] = [
            'name' => $row['name'],
            'date' => $thisYear->format('M j'),
            'age' => (int) $thisYear->format('Y') - (int) $birthday->format('Y'),
            'days' => $daysAway,
        ];
    }
}

$suguanPage = null;
if ($action === 'list' || $action === 'generator') {
    $suguanPage = suguanWeekPayload($pdo, suguanWeekStart(null)) + [
        'types' => SUGUAN_TYPES,
        'officers' => array_column(
            $pdo->query(
                'SELECT name FROM officers WHERE is_active = 1
                 ORDER BY masterlist_number IS NULL, masterlist_number ASC, name ASC'
            )->fetchAll(),
            'name'
        ),
    ];
}

function icon(string $name): string
{
    $paths = [
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
        'printer' => '<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
        'pen' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
        'arrow-left' => '<path d="M19 12H5M12 19l-7-7 7-7"/>',
        'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
        'filter' => '<path d="M3 6h18M7 12h10M10 18h4"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'gift' => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C11 3 12 8 12 8s1-5 4.5-5a2.5 2.5 0 0 1 0 5"/>',
        'settings' => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
        'moon' => '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
        'rotate' => '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
        'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
        'sort' => '<path d="m3 16 4 4 4-4M7 20V4M21 8l-4-4-4 4M17 4v16"/>',
        'sheet' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h8M10 9H8"/>',
        'trash' => '<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="m19 6-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
        'image' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21"/>',
        'more' => '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.7 4 3 9 3s9-1.3 9-3V5"/><path d="M3 12c0 1.7 4 3 9 3s9-1.3 9-3"/>',
    ];

    return '<svg class="icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($paths[$name] ?? '') . '</svg>';
}

$signatories = [
    'pastor' => '',
    'pd_tagasubaybay' => '',
    'tsv_lead' => '',
    'assistant_tsv_lead' => '',
    'tsv_secretary' => '',
    'locale_name' => '',
    'district_name' => '',
    'district_supervisor' => '',
    'district_tsv_overseer' => '',
    'webex_display_name' => '',
    'pd_tagasubaybay_birthday' => '',
    'pd_tagasubaybay_wedding' => '',
];
$weeklyMeeting = [
    'webex_display_name' => '',
    'datetime' => '',
    'venue' => '',
];
$agendaValues = [
    'first_prayer' => '',
    'last_prayer' => '',
    'lecture' => '',
    'topics' => '',
    'monthly_date' => '',
    'report_month' => '',
    'webex_count' => '',
    'webex_reason' => '',
];
$savedRecruits = '[]';
$savedExtraForms = '';
$printSelections = [];
foreach ($printForms as $frequency => $forms) {
    foreach (array_keys($forms) as $formKey) {
        $printSelections[$formKey] = $frequency === 'weekly';
    }
}
$settingsStatement = $pdo->query('SELECT setting_key, setting_value FROM app_settings');
foreach ($settingsStatement->fetchAll() as $setting) {
    if (array_key_exists($setting['setting_key'], $signatories)) {
        $signatories[$setting['setting_key']] = $setting['setting_value'];
    } elseif ($setting['setting_key'] === 'weekly_meeting_datetime') {
        $weeklyMeeting['datetime'] = $setting['setting_value'];
    } elseif ($setting['setting_key'] === 'forms_resumen_extra') {
        $savedExtraForms = $setting['setting_value'];
    } elseif ($setting['setting_key'] === 'monthly_recruits') {
        $savedRecruits = $setting['setting_value'];
    } elseif ($setting['setting_key'] === 'weekly_meeting_venue') {
        $weeklyMeeting['venue'] = $setting['setting_value'];
    } elseif (str_starts_with($setting['setting_key'], 'print_form_')) {
        $formKey = substr($setting['setting_key'], strlen('print_form_'));
        if (array_key_exists($formKey, $printSelections)) {
            $printSelections[$formKey] = $setting['setting_value'] === '1';
        }
    }
}
$weeklyMeeting['webex_display_name'] = $signatories['webex_display_name'];
$postedWebexName = is_string($_POST['webex_display_name'] ?? null) ? trim($_POST['webex_display_name']) : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_print_forms') {
    $meetingDateTimeValue = $_POST['meeting_datetime'] ?? '';
    $meetingVenueValue = $_POST['meeting_venue'] ?? '';
    $weeklyMeeting['datetime'] = is_string($meetingDateTimeValue) ? trim($meetingDateTimeValue) : '';
    $weeklyMeeting['venue'] = is_string($meetingVenueValue) ? trim($meetingVenueValue) : '';
    $savedExtraForms = is_string($_POST['extra_forms'] ?? null) ? trim($_POST['extra_forms']) : '';
    foreach ($agendaValues as $key => $_) {
        $postedValue = $_POST[$key === 'topics' ? 'agenda_topics' : $key] ?? '';
        $agendaValues[$key] = is_string($postedValue) ? trim($postedValue) : '';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'settings') {
    foreach ($signatories as $key => $_) {
        $rawValue = $_POST[$key] ?? '';
        $signatories[$key] = is_string($rawValue) ? trim($rawValue) : '';
    }
}

$formValues = is_array($editingOfficer) ? $editingOfficer : [
    'id' => '',
    'masterlist_number' => '',
    'name' => '',
    'tungkulin' => '',
    'oath_taking_date' => '',
    'oath_taking_text' => '',
    'ndi_signing_date' => '',
    'ndi_signing_text' => '',
    'is_active' => 1,
    'birthday' => '',
    'wedding_anniversary' => '',
    'contact_telegram' => '',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'form') {
    $repostId = $_POST['id'] ?? '';
    $repostName = $_POST['name'] ?? '';
    $repostTungkulin = $_POST['tungkulin'] ?? '';
    $repostOath = $_POST['oath_taking_date'] ?? '';
    $repostNdi = $_POST['ndi_signing_date'] ?? '';
    $repostBirthday = $_POST['birthday'] ?? '';
    $repostWedding = $_POST['wedding_anniversary'] ?? '';
    $repostContact = $_POST['contact_telegram'] ?? '';
    $formValues = array_merge($formValues, [
        'id' => is_string($repostId) || is_int($repostId) ? (string) $repostId : '',
        'name' => is_string($repostName) ? trim($repostName) : '',
        'tungkulin' => is_string($repostTungkulin) ? trim($repostTungkulin) : '',
        'oath_taking_date' => is_string($repostOath) ? $repostOath : '',
        'ndi_signing_date' => is_string($repostNdi) ? $repostNdi : '',
        'is_active' => ($_POST['is_active'] ?? '') === '1' ? 1 : 0,
        'birthday' => is_string($repostBirthday) ? $repostBirthday : '',
        'wedding_anniversary' => is_string($repostWedding) ? $repostWedding : '',
        'contact_telegram' => is_string($repostContact) ? trim($repostContact) : '',
    ]);
}
$isEditing = !empty($formValues['id']);
require __DIR__ . '/view.php';
