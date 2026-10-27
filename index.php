<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');
session_start();
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
require __DIR__ . '/docx.php';
require __DIR__ . '/docx_templates.php';
require __DIR__ . '/suguan.php';
require __DIR__ . '/monthly_reports.php';
require __DIR__ . '/import_export.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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

function validDate(string $value, string $label, array &$errors): ?string
{
    if ($value === '') {
        return null;
    }

    $date = DateTime::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        $errors[] = "{$label} must be a valid date.";
        return null;
    }

    return $value;
}

if (($_GET['action'] ?? '') === 'suguan') {
    header('Location: index.php#generator');
    exit;
}
$allowedActions = ['list', 'form', 'settings', 'suguan_data', 'export_xlsx', 'export_sql', 'import_review'];
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

function sendDownload(string $filename, string $contents, string $contentType): never
{
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
    sendDownload('tsv-officers-' . date('Y-m-d') . '.sql', officersSqlDump($pdo), 'application/sql; charset=utf-8');
}
$errors = [];
$notice = '';
$expectsJson = str_contains(
    strtolower($_SERVER['HTTP_ACCEPT'] ?? ''),
    'application/json'
) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
if (isset($_SESSION['notice']) && is_string($_SESSION['notice'])) {
    $notice = $_SESSION['notice'];
}
unset($_SESSION['notice']);
$search = '';

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
            if ($upload['size'] > IMPORT_MAX_BYTES) {
                throw new InvalidArgumentException('The backup file is larger than 5 MB.');
            }
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
            try {
                $count = importOfficers($pdo, $preview['records'], $preview['mode'] === 'replace');
                $_SESSION['notice'] = $preview['mode'] === 'replace'
                    ? "Imported {$count} officer records, replacing the previous records."
                    : "Added {$count} officer records.";
                header('Location: index.php');
                exit;
            } catch (PDOException $e) {
                error_log('Import officers failed: ' . $e->getMessage());
                $errors[] = 'The import failed and no records were changed. The file may contain duplicate record IDs.';
                $action = 'list';
            }
        }
    } elseif ($postAction === 'import_cancel') {
        unset($_SESSION['import_preview']);
        $_SESSION['notice'] = 'Import cancelled. No records were changed.';
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
        $rawAgendaTopics = $_POST['agenda_topics'] ?? '';
        $agendaTopics = parseAgendaTopics(is_string($rawAgendaTopics) ? $rawAgendaTopics : '');
        // Recruit rows: a name and an optional birthday from the date picker.
        $recruits = [];
        $recruitBirthdays = is_array($_POST['recruit_birthday'] ?? null) ? array_values($_POST['recruit_birthday']) : [];
        foreach (is_array($_POST['recruit_name'] ?? null) ? array_values($_POST['recruit_name']) : [] as $index => $rawName) {
            $name = is_string($rawName) ? trim($rawName) : '';
            $birthday = is_string($recruitBirthdays[$index] ?? null) ? trim($recruitBirthdays[$index]) : '';
            $birthdayDate = DateTimeImmutable::createFromFormat('!Y-m-d', $birthday);
            if ($name !== '') {
                $recruits[] = [mb_substr($name, 0, 150), $birthdayDate && $birthdayDate->format('Y-m-d') === $birthday ? $birthday : ''];
            }
        }
        $rawMonthlyDate = $_POST['monthly_date'] ?? '';
        $monthlyDate = is_string($rawMonthlyDate) ? trim($rawMonthlyDate) : '';
        $suguanMonday = suguanWeekStart(is_string($_POST['suguan_week'] ?? null) ? $_POST['suguan_week'] : null);
        $needsMeetingDetails = array_intersect(['weekly_attendance', 'meeting_agenda'], $selectedForms) !== [];
        $reportMonth = reportMonthStart(is_string($_POST['report_month'] ?? null) ? $_POST['report_month'] : null, new DateTimeZone('Asia/Manila'));
        $postedText = static fn(string $key): string => is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
        $webexName = $postedText('webex_display_name');
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
            if ($webexName === '' || mb_strlen($webexName) > 150 || mb_strlen($webexCount) > 50 || mb_strlen($webexReason) > 250) {
                $errors[] = 'Enter the Webex display name (150 characters or fewer) and a short usage count and reason.';
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
            if ($firstPrayer === '' || mb_strlen($firstPrayer) > 150) {
                $errors[] = 'Enter the name for Unang Panalangin (150 characters or fewer).';
            }
            if ($lastPrayer === '' || mb_strlen($lastPrayer) > 150) {
                $errors[] = 'Enter the name for Huling Panalangin (150 characters or fewer).';
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

        if (!$errors) {
            try {
                savePrintSelections($pdo, $availablePrintFormKeys, $selectedForms);
                $pdo->beginTransaction();
                $saveMeetingSetting = $pdo->prepare(
                    'INSERT INTO app_settings (setting_key, setting_value)
                     VALUES (:setting_key, :setting_value)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
                );
                foreach (array_filter([
                    'weekly_meeting_datetime' => $meetingDateTime,
                    'weekly_meeting_venue' => $meetingVenue,
                    'webex_display_name' => $webexName,
                ], static fn(string $value): bool => $value !== '') as $settingKey => $settingValue) {
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
                    $generatedDocuments["directory-report-{$monthSlug}.docx"] = generateDirectoryReportDocx(
                        monthlyScheduleServices($pdo, $reportMonth),
                        $signatories,
                        $reportMonth,
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
                if (count($generatedDocuments) === 1) {
                    $filename = array_key_first($generatedDocuments);
                    sendDownload($filename, $generatedDocuments[$filename], str_ends_with($filename, '.xlsx') ? XLSX_MIME : DOCX_MIME);
                }
                sendDownload('tsv-forms.zip', zipFiles($generatedDocuments), 'application/zip');
            } catch (InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
                $action = 'list';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Generate weekly forms failed: ' . $e->getMessage());
                $errors[] = 'Could not generate the selected weekly DOCX forms. Please check the PHP error log and try again.';
                $action = 'list';
            }
        } else {
            $action = 'list';
        }
    } elseif ($postAction === 'delete') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($id) || $id < 1) {
            $errors[] = 'A valid officer ID is required.';
        } else {
            try {
                $statement = $pdo->prepare('DELETE FROM officers WHERE id = :id');
                $statement->execute(['id' => $id]);
                $_SESSION['notice'] = $statement->rowCount() > 0
                    ? 'Officer record deleted.'
                    : 'That officer record was not found.';
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
                    $_SESSION['notice'] = 'That officer record was not found.';
                } else {
                    $newStatus = (int) $newStatus;
                    if ($isAjaxRequest) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode(['success' => true, 'is_active' => $newStatus]);
                        exit;
                    }
                    $_SESSION['notice'] = 'Officer status updated.';
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
        ];
        $signatoryValues = [];
        foreach ($signatoryFields as $key => $label) {
            $rawValue = $_POST[$key] ?? '';
            $value = is_string($rawValue) ? trim($rawValue) : '';
            if (mb_strlen($value) > 150) {
                $errors[] = "{$label} name must be 150 characters or fewer.";
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
                $_SESSION['notice'] = 'Signatories saved.';
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
        $rawRosterNumber = $_POST['masterlist_number'] ?? '';
        $rawContact = $_POST['contact_telegram'] ?? '';
        $name = is_string($rawName) ? trim($rawName) : '';
        $tungkulin = is_string($rawTungkulin) ? trim($rawTungkulin) : '';
        $rosterNumberValue = is_string($rawRosterNumber) || is_int($rawRosterNumber) ? trim((string) $rawRosterNumber) : '';
        $masterlistNumber = $rosterNumberValue === '' ? null : filter_var($rosterNumberValue, FILTER_VALIDATE_INT);
        $rawContactValue = is_string($rawContact) ? trim($rawContact) : '';
        $contactTelegram = $rawContactValue === '' ? null : $rawContactValue;
        $isActive = ($_POST['is_active'] ?? '') === '1' ? 1 : 0;
        $rawOath = is_string($_POST['oath_taking_date'] ?? '') ? (string) $_POST['oath_taking_date'] : '';
        $rawNdi = is_string($_POST['ndi_signing_date'] ?? '') ? (string) $_POST['ndi_signing_date'] : '';
        $rawBirthday = is_string($_POST['birthday'] ?? '') ? (string) $_POST['birthday'] : '';
        $oathText = trim($rawOath) === '' ? null : trim($rawOath);
        $ndiText = trim($rawNdi) === '' ? null : trim($rawNdi);
        $oathDate = $oathText === null ? null : normalizeFullDate($oathText);
        $ndiDate = $ndiText === null ? null : normalizeFullDate($ndiText);
        $birthday = validDate($rawBirthday, 'Birthday', $errors);

        if ($idValue !== '' && (!is_int($id) || $id < 1)) {
            $errors[] = 'A valid officer ID is required.';
        }
        if ($rosterNumberValue !== '' && (!is_int($masterlistNumber) || $masterlistNumber < 1)) {
            $errors[] = 'Roster number must be a positive whole number.';
        }
        if (is_string($masterlistNumber) || (is_int($masterlistNumber) && $masterlistNumber < 1)) {
            $errors[] = 'Roster number must be a positive whole number.';
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
                    $statement = $pdo->prepare(
                        'INSERT INTO officers (masterlist_number, name, tungkulin, oath_taking_date, oath_taking_text,
                             ndi_signing_date, ndi_signing_text, birthday, contact_telegram, is_active)
                         VALUES (:masterlist_number, :name, :tungkulin, :oath_taking_date, :oath_taking_text,
                             :ndi_signing_date, :ndi_signing_text, :birthday, :contact_telegram, :is_active)'
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
                        'contact_telegram' => $contactTelegram,
                    ]);
                    $_SESSION['notice'] = 'Officer record added.';
                } else {
                    $statement = $pdo->prepare(
                        'UPDATE officers
                         SET masterlist_number = :masterlist_number, name = :name, tungkulin = :tungkulin,
                             oath_taking_date = :oath_taking_date, oath_taking_text = :oath_taking_text,
                             ndi_signing_date = :ndi_signing_date, ndi_signing_text = :ndi_signing_text,
                             is_active = :is_active, birthday = :birthday, contact_telegram = :contact_telegram
                         WHERE id = :id'
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
                        'contact_telegram' => $contactTelegram,
                        'id' => $id,
                    ]);
                    $_SESSION['notice'] = $statement->rowCount() > 0
                        ? 'Officer record updated.'
                        : 'Officer record updated.';
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
if ($action === 'list') {
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
        'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
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
    } elseif ($setting['setting_key'] === 'webex_display_name') {
        $weeklyMeeting['webex_display_name'] = $setting['setting_value'];
    } elseif ($setting['setting_key'] === 'weekly_meeting_venue') {
        $weeklyMeeting['venue'] = $setting['setting_value'];
    } elseif (str_starts_with($setting['setting_key'], 'print_form_')) {
        $formKey = substr($setting['setting_key'], strlen('print_form_'));
        if (array_key_exists($formKey, $printSelections)) {
            $printSelections[$formKey] = $setting['setting_value'] === '1';
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_print_forms') {
    $meetingDateTimeValue = $_POST['meeting_datetime'] ?? '';
    $meetingVenueValue = $_POST['meeting_venue'] ?? '';
    $weeklyMeeting['datetime'] = is_string($meetingDateTimeValue) ? trim($meetingDateTimeValue) : '';
    $weeklyMeeting['venue'] = is_string($meetingVenueValue) ? trim($meetingVenueValue) : '';
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
    'contact_telegram' => '',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'form') {
    $repostId = $_POST['id'] ?? '';
    $repostRosterNumber = $_POST['masterlist_number'] ?? '';
    $repostName = $_POST['name'] ?? '';
    $repostTungkulin = $_POST['tungkulin'] ?? '';
    $repostOath = $_POST['oath_taking_date'] ?? '';
    $repostNdi = $_POST['ndi_signing_date'] ?? '';
    $repostBirthday = $_POST['birthday'] ?? '';
    $repostContact = $_POST['contact_telegram'] ?? '';
    $formValues = array_merge($formValues, [
        'id' => is_string($repostId) || is_int($repostId) ? (string) $repostId : '',
        'masterlist_number' => is_string($repostRosterNumber) || is_int($repostRosterNumber) ? (string) $repostRosterNumber : '',
        'name' => is_string($repostName) ? trim($repostName) : '',
        'tungkulin' => is_string($repostTungkulin) ? trim($repostTungkulin) : '',
        'oath_taking_date' => is_string($repostOath) ? $repostOath : '',
        'ndi_signing_date' => is_string($repostNdi) ? $repostNdi : '',
        'is_active' => ($_POST['is_active'] ?? '') === '1' ? 1 : 0,
        'birthday' => is_string($repostBirthday) ? $repostBirthday : '',
        'contact_telegram' => is_string($repostContact) ? trim($repostContact) : '',
    ]);
}
$isEditing = !empty($formValues['id']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TSV Officer Masterlist</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@400;500&display=swap">
    <script>
        try {
            document.documentElement.dataset.theme = localStorage.getItem('theme') === 'light' ? 'light' : 'dark';
        } catch {
            document.documentElement.dataset.theme = 'dark';
        }
    </script>
    <link rel="stylesheet" href="style.css?v=18">
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <a class="team" href="index.php">
            <span class="team-avatar" aria-hidden="true"></span>
            <span class="team-name">TSV Secretary</span>
            <span class="team-badge"><?= h($signatories['locale_name'] ?: 'Locale') ?></span>
        </a>
        <nav class="side-nav" aria-label="Main">
            <a class="side-link <?= $action === 'list' ? 'is-active' : '' ?>" href="index.php"><?= icon('grid') ?><span>Overview</span></a>
            <a class="side-link <?= $action === 'form' ? 'is-active' : '' ?>" href="index.php?action=form"><?= icon('user-plus') ?><span><?= $action === 'form' && $isEditing ? 'Edit officer' : 'Add officer' ?></span></a>
            <?php if ($action === 'list'): ?>
                <button class="side-link" type="button" data-open-generator><?= icon('printer') ?><span>Form generator</span></button>
            <?php else: ?>
                <a class="side-link" href="index.php#generator"><?= icon('printer') ?><span>Form generator</span></a>
            <?php endif; ?>
            <a class="side-link <?= $action === 'settings' ? 'is-active' : '' ?>" href="index.php?action=settings"><?= icon('pen') ?><span>Signatories</span></a>
        </nav>
        <div class="side-footer">
            <span class="team-avatar team-avatar-small" aria-hidden="true"></span>
            <span data-db-label>Local MySQL</span>
            <span class="side-footer-dot" data-db-dot title="Connected"></span>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <div class="crumbs">
                <a href="index.php">All officers</a>
                <?php if ($action === 'form'): ?>
                    <span class="crumb-sep">/</span><span><?= $isEditing ? 'Edit' : 'New' ?></span>
                <?php elseif ($action === 'settings'): ?>
                    <span class="crumb-sep">/</span><span>Signatories</span>
                <?php endif; ?>
            </div>
            <div class="topbar-actions">
                <?php if ($action === 'list'): ?>
                    <button class="topbar-link" type="button" data-open-generator><?= icon('printer') ?><span>Generate forms</span></button>
                <?php endif; ?>
                <a class="topbar-link" href="index.php?action=form"><?= icon('plus') ?><span>Add New Officer</span></a>
                <button class="theme-toggle" type="button" data-theme-toggle aria-label="Toggle light and dark mode" title="Toggle theme">
                    <span class="theme-icon-dark"><?= icon('moon') ?></span>
                    <span class="theme-icon-light"><?= icon('sun') ?></span>
                </button>
            </div>
        </header>

        <main class="content">
            <?php if ($notice): ?>
                <div class="alert alert-success"><?= h($notice) ?></div>
            <?php endif; ?>
            <?php foreach ($errors as $error): ?>
                <div class="alert alert-error"><?= h($error) ?></div>
            <?php endforeach; ?>
            <div id="status-feedback" class="alert alert-error" role="alert" hidden></div>

            <?php if ($action === 'settings'): ?>
                <div class="page-title">
                    <h1>Signatories</h1>
                    <p>Locale details and the names printed on generated forms.</p>
                </div>
                <section class="panel form-panel">
                    <form method="post" action="index.php?action=settings">
                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="save_signatories">
                        <div class="panel-body form-grid">
                            <h2 class="field-wide form-section-title">Locale</h2>
                            <label class="field">
                                <span>Locale (Lokal)</span>
                                <input name="locale_name" type="text" maxlength="150" value="<?= h($signatories['locale_name']) ?>">
                            </label>
                            <label class="field">
                                <span>District (Distrito)</span>
                                <input name="district_name" type="text" maxlength="150" value="<?= h($signatories['district_name']) ?>">
                            </label>
                            <h2 class="field-wide form-section-title">Signatories</h2>
                            <label class="field">
                                <span>Pastor</span>
                                <input name="pastor" type="text" maxlength="150" value="<?= h($signatories['pastor']) ?>">
                            </label>
                            <label class="field">
                                <span>PD Tagasubaybay</span>
                                <input name="pd_tagasubaybay" type="text" maxlength="150" value="<?= h($signatories['pd_tagasubaybay']) ?>">
                            </label>
                            <label class="field">
                                <span>TSV Lead</span>
                                <input name="tsv_lead" type="text" maxlength="150" value="<?= h($signatories['tsv_lead']) ?>">
                            </label>
                            <label class="field">
                                <span>Assistant TSV Lead</span>
                                <input name="assistant_tsv_lead" type="text" maxlength="150" value="<?= h($signatories['assistant_tsv_lead']) ?>">
                            </label>
                            <label class="field">
                                <span>TSV Secretary</span>
                                <input name="tsv_secretary" type="text" maxlength="150" value="<?= h($signatories['tsv_secretary']) ?>">
                            </label>
                            <label class="field">
                                <span>Tagapangasiwa ng Distrito</span>
                                <input name="district_supervisor" type="text" maxlength="150" value="<?= h($signatories['district_supervisor']) ?>">
                            </label>
                            <label class="field">
                                <span>District TSV Overseer</span>
                                <input name="district_tsv_overseer" type="text" maxlength="150" value="<?= h($signatories['district_tsv_overseer']) ?>">
                            </label>
                        </div>
                        <div class="panel-footer">
                            <p class="muted">Used on the Suguan, checklist, Resumen, attendance, agenda and monthly reports.</p>
                            <div class="form-actions">
                                <a class="button button-light" href="index.php">Cancel</a>
                                <button class="button" type="submit">Save</button>
                            </div>
                        </div>
                    </form>
                </section>
            <?php elseif ($action === 'import_review' && is_array($_SESSION['import_preview'] ?? null)): ?>
                <?php $preview = $_SESSION['import_preview']; $isReplace = $preview['mode'] === 'replace'; ?>
                <div class="page-title">
                    <a class="back-link" href="index.php"><?= icon('arrow-left') ?>Back to officers</a>
                    <h1>Review import</h1>
                    <p><?= h($preview['file']) ?> · <?= count($preview['records']) ?> officer records found<?= $preview['skipped'] ? ' · ' . count($preview['skipped']) . ' skipped' : '' ?></p>
                </div>
                <div class="confirm-box <?= $isReplace ? 'confirm-danger' : '' ?>">
                    <strong>Are you sure?</strong>
                    <p><?= $isReplace
                        ? 'This will permanently delete all ' . $officerTotals['total'] . ' current officer records and replace them with the ' . count($preview['records']) . ' records below. Export an SQL backup first if you might need the current records.'
                        : 'This will add the ' . count($preview['records']) . ' records below to the ' . $officerTotals['total'] . ' existing officer records. Blg. numbers that are already in use will be left blank.' ?></p>
                </div>
                <?php if ($preview['skipped']): ?>
                    <div class="alert alert-error">
                        <?php foreach (array_slice($preview['skipped'], 0, 10) as $message): ?><div><?= h($message) ?></div><?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <section class="panel">
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Blg.</th><th>Name</th><th>Tungkulin</th><th>Status</th><th>Oath-taking</th><th>Birthday</th><th>Telegram #</th></tr></thead>
                            <tbody>
                                <?php foreach ($preview['records'] as $record): ?>
                                    <tr>
                                        <td class="mono muted"><?= h((string) ($record['masterlist_number'] ?? '')) ?></td>
                                        <td class="name-cell"><?= h($record['name']) ?></td>
                                        <td class="muted"><?= h($record['tungkulin']) ?></td>
                                        <td><span class="status <?= $record['is_active'] ? 'status-active' : 'status-inactive' ?>"><?= $record['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                                        <td class="muted"><?= h(displayRecordDate($record['oath_taking_date'], $record['oath_taking_text'])) ?></td>
                                        <td class="muted"><?= h(displayDate($record['birthday'])) ?></td>
                                        <td class="mono"><?= h($record['contact_telegram'] ?? '') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="panel-footer">
                        <form method="post" action="index.php">
                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="import_cancel">
                            <button class="button button-light" type="submit">Cancel</button>
                        </form>
                        <form method="post" action="index.php" data-confirm="<?= $isReplace ? 'Delete all current officer records and import ' . count($preview['records']) . ' records? This cannot be undone.' : 'Add ' . count($preview['records']) . ' officer records?' ?>">
                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="import_commit">
                            <button class="button <?= $isReplace ? 'button-danger' : '' ?>" type="submit"><?= $isReplace ? 'Yes, replace all records' : 'Yes, add ' . count($preview['records']) . ' records' ?></button>
                        </form>
                    </div>
                </section>
            <?php elseif ($action === 'form'): ?>
                <div class="page-title">
                    <a class="back-link" href="index.php"><?= icon('arrow-left') ?>Back to officers</a>
                    <h1><?= $isEditing ? 'Edit officer' : 'Add officer' ?></h1>
                    <p><?= $isEditing ? 'Update this officer record. Changes appear in the next generated masterlist.' : 'Create a new officer record for the masterlist.' ?></p>
                </div>
                <section class="panel form-panel">
                    <form method="post" action="index.php?action=form<?= $isEditing ? '&amp;id=' . rawurlencode((string) $formValues['id']) : '' ?>">
                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="id" value="<?= h((string) $formValues['id']) ?>">
                        <div class="panel-body form-grid">
                            <label class="field">
                                <span>Masterlist number</span>
                                <input name="masterlist_number" type="number" min="1" step="1" value="<?= h((string) ($formValues['masterlist_number'] ?? '')) ?>">
                            </label>
                            <label class="field field-wide">
                                <span>Name <b>*</b></span>
                                <input name="name" type="text" maxlength="150" required value="<?= h((string) $formValues['name']) ?>" autocomplete="name">
                            </label>
                            <label class="field">
                                <span>Tungkulin <b>*</b></span>
                                <select name="tungkulin" required>
                                    <option value="">Choose a tungkulin</option>
                                    <?php foreach ($allowedRoles as $role): ?>
                                        <option value="<?= h($role) ?>" <?= (string) $formValues['tungkulin'] === $role ? 'selected' : '' ?>><?= h($role) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="field">
                                <span>Status</span>
                                <select name="is_active">
                                    <option value="1" <?= (int) $formValues['is_active'] === 1 ? 'selected' : '' ?>>Active</option>
                                    <option value="0" <?= (int) $formValues['is_active'] === 0 ? 'selected' : '' ?>>Inactive</option>
                                </select>
                            </label>
                            <label class="field">
                                <span>Date of oath-taking</span>
                                <input name="oath_taking_date" type="text" maxlength="50" placeholder="Full date, year, or month and year" value="<?= h((string) (($formValues['oath_taking_text'] ?? '') ?: ($formValues['oath_taking_date'] ?? ''))) ?>">
                            </label>
                            <label class="field">
                                <span>Date of NDA signing</span>
                                <input name="ndi_signing_date" type="text" maxlength="50" placeholder="Full date, month and year, or N/A" value="<?= h((string) (($formValues['ndi_signing_text'] ?? '') ?: ($formValues['ndi_signing_date'] ?? ''))) ?>">
                            </label>
                            <label class="field">
                                <span>Birthday</span>
                                <input name="birthday" type="date" value="<?= h((string) $formValues['birthday']) ?>">
                            </label>
                            <label class="field">
                                <span>Contact (Active) Telegram #</span>
                                <input name="contact_telegram" type="text" maxlength="50" value="<?= h((string) ($formValues['contact_telegram'] ?? '')) ?>" autocomplete="tel">
                            </label>
                        </div>
                        <div class="panel-footer">
                            <p class="muted">Fields marked <b>*</b> are required.</p>
                            <div class="form-actions">
                                <a class="button button-light" href="index.php">Cancel</a>
                                <button class="button" type="submit"><?= $isEditing ? 'Save changes' : 'Add officer' ?></button>
                            </div>
                        </div>
                    </form>
                </section>
            <?php else: ?>
                <div class="page-title page-title-row">
                    <div>
                        <h1>Officers</h1>
                        <p>Manage officer records and generate the all-officer masterlist.</p>
                    </div>
                    <div class="page-title-actions">
                        <button class="button button-light" type="button" data-open-import><?= icon('upload') ?>Import</button>
                        <a class="button button-light" href="index.php?action=export_sql" title="Backup of the officer records table"><?= icon('database') ?>Export SQL</a>
                        <a class="button button-light" href="index.php?action=export_xlsx"><?= icon('sheet') ?>Export Excel</a>
                        <form method="post" action="index.php">
                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="generate_masterlist">
                            <button class="button" type="submit"><?= icon('download') ?>Generate masterlist DOCX</button>
                        </form>
                    </div>
                </div>

                <section class="stats-row" aria-label="Officer totals">
                    <div class="stat-card">
                        <span>Total officers</span>
                        <strong data-total-count><?= $officerTotals['total'] ?></strong>
                    </div>
                    <div class="stat-card">
                        <span><i class="dot dot-active"></i>Active</span>
                        <strong data-active-count><?= $officerTotals['active'] ?></strong>
                    </div>
                    <div class="stat-card">
                        <span><i class="dot dot-inactive"></i>Inactive</span>
                        <strong data-inactive-count><?= $officerTotals['inactive'] ?></strong>
                    </div>
                </section>

                <section class="panel birthdays" aria-label="Birthdays this month">
                    <div class="birthdays-head">
                        <?= icon('gift') ?>
                        <strong>Birthdays in <?= h($today->format('F')) ?></strong>
                        <span class="muted"><?= count($birthdays) ?> <?= count($birthdays) === 1 ? 'officer' : 'officers' ?></span>
                    </div>
                    <?php if ($birthdays): ?>
                        <ul class="birthday-list">
                            <?php foreach ($birthdays as $birthday): ?>
                                <li class="<?= $birthday['days'] === 0 ? 'is-today' : ($birthday['days'] < 0 ? 'is-past' : '') ?>">
                                    <span class="birthday-date mono"><?= h($birthday['date']) ?></span>
                                    <span class="birthday-name"><?= h($birthday['name']) ?></span>
                                    <span class="birthday-when"><?php
                                        if ($birthday['days'] === 0) {
                                            echo 'Today · turns ' . $birthday['age'];
                                        } elseif ($birthday['days'] > 0) {
                                            echo 'In ' . $birthday['days'] . ($birthday['days'] === 1 ? ' day' : ' days') . ' · turns ' . $birthday['age'];
                                        } else {
                                            echo 'Turned ' . $birthday['age'];
                                        }
                                    ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="birthday-empty muted">No active officers have a birthday this month.</p>
                    <?php endif; ?>
                </section>

                <form class="filter-bar" action="index.php" method="get" data-filter-form>
                    <label class="find">
                        <?= icon('search') ?>
                        <input type="search" name="search" placeholder="Find by name or tungkulin..." value="<?= h($search ?? '') ?>" aria-label="Search officers">
                        <kbd>F</kbd>
                    </label>
                    <label class="pill">
                        <?= icon('filter') ?>
                        <span class="pill-label">Status</span>
                        <select name="status" aria-label="Filter by status" data-status-filter="<?= h($statusFilter) ?>">
                            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>
                            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </label>
                    <?php if (!empty($search)): ?>
                        <span class="pill pill-static"><span class="pill-label">Search</span><?= h($search) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($search) || $statusFilter !== 'all'): ?>
                        <a class="pill pill-clear" href="index.php"><?= icon('x') ?>Clear</a>
                    <?php endif; ?>
                    <button class="visually-hidden" type="submit">Search</button>
                </form>

                <section class="panel">
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Blg.</th>
                                    <th>Name</th>
                                    <th>Tungkulin</th>
                                    <th>Status</th>
                                    <th>Oath-taking</th>
                                    <th>NDA signing</th>
                                    <th>Birthday</th>
                                    <th>Telegram #</th>
                                    <th class="actions-column"><span class="visually-hidden">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody data-officers-body>
                                <?php if (!$officers): ?>
                                    <tr><td class="empty-state" colspan="9"><?php
                                        if ($statusFilter === 'active') {
                                            echo !empty($search) ? 'No active officers match your search.' : 'No active officers found.';
                                        } elseif ($statusFilter === 'inactive') {
                                            echo !empty($search) ? 'No inactive officers match your search.' : 'No inactive officers found.';
                                        } else {
                                            echo !empty($search) ? 'No officers match your search.' : 'No officer records yet. Add an officer to get started.';
                                        }
                                    ?></td></tr>
                                <?php else: ?>
                                    <?php foreach ($officers as $officer): ?>
                                        <tr data-officer-row data-active="<?= $officer['is_active'] ? '1' : '0' ?>">
                                            <td class="mono muted"><?= h((string) ($officer['masterlist_number'] ?? '')) ?></td>
                                            <td class="name-cell"><?= h($officer['name']) ?></td>
                                            <td class="muted"><?= h($officer['tungkulin']) ?></td>
                                            <td>
                                                <form method="post" action="index.php" class="status-toggle-form" data-status-toggle>
                                                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="id" value="<?= h((string) $officer['id']) ?>">
                                                    <button class="status status-toggle <?= $officer['is_active'] ? 'status-active' : 'status-inactive' ?>" type="submit" aria-label="Set <?= h($officer['name']) ?> to <?= $officer['is_active'] ? 'inactive' : 'active' ?>" title="Click to change status"><?= $officer['is_active'] ? 'Active' : 'Inactive' ?></button>
                                                </form>
                                            </td>
                                            <td class="muted"><?= h(displayRecordDate($officer['oath_taking_date'], $officer['oath_taking_text'])) ?></td>
                                            <td class="muted"><?= h(displayRecordDate($officer['ndi_signing_date'], $officer['ndi_signing_text'])) ?></td>
                                            <td class="muted"><?= h(displayDate($officer['birthday'])) ?></td>
                                            <td class="mono"><?= h($officer['contact_telegram'] ?? '') ?></td>
                                            <td class="row-actions">
                                                <a href="index.php?action=form&amp;id=<?= h((string) $officer['id']) ?>">Edit</a>
                                                <form method="post" action="index.php" data-confirm="Delete <?= h($officer['name']) ?>? This cannot be undone.">
                                                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= h((string) $officer['id']) ?>">
                                                    <button class="link-button delete-link" type="submit">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <dialog class="generator-dialog import-dialog" id="import-dialog" aria-labelledby="import-title">
                    <form method="post" action="index.php" enctype="multipart/form-data" class="generator-form">
                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="import_preview">
                        <div class="generator-heading">
                            <div>
                                <h2 id="import-title">Import officer records</h2>
                                <p class="muted">Upload a .sql / .mysql backup (from Export SQL or phpMyAdmin). You'll review the records before anything changes.</p>
                            </div>
                            <button class="dialog-close" type="button" data-close-dialog aria-label="Close"><?= icon('x') ?></button>
                        </div>
                        <div class="generator-body">
                            <label class="dropzone" data-dropzone>
                                <input class="dropzone-input" name="import_file" type="file" accept=".sql,.mysql,.txt" required data-import-file>
                                <span class="dropzone-icon"><?= icon('upload') ?></span>
                                <strong data-dropzone-title>Drag &amp; drop your backup here</strong>
                                <span class="muted" data-dropzone-hint>or click to browse · .sql or .mysql, up to 5 MB</span>
                            </label>
                            <fieldset class="generator-group import-modes">
                                <legend>Import mode</legend>
                                <label class="generator-option">
                                    <input type="radio" name="import_mode" value="add" checked>
                                    <span><strong>Add to existing records</strong><br><small class="muted">Keeps the current officers and adds the ones from the file.</small></span>
                                </label>
                                <label class="generator-option">
                                    <input type="radio" name="import_mode" value="replace">
                                    <span><strong>Replace all records</strong><br><small class="muted">Deletes the current officers and restores the file's records.</small></span>
                                </label>
                            </fieldset>
                        </div>
                        <div class="panel-footer">
                            <span></span>
                            <div class="form-actions">
                                <button class="button button-light" type="button" data-close-dialog>Cancel</button>
                                <button class="button" type="submit">Review import</button>
                            </div>
                        </div>
                    </form>
                </dialog>
                <dialog class="generator-dialog" id="print-generator" aria-labelledby="generator-title">
                    <form method="post" action="index.php" class="generator-form">
                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="generate_print_forms">
                        <div class="generator-heading">
                            <div>
                                <h2 id="generator-title">Generate forms</h2>
                                <p class="muted">Choose the forms to print. Tick Suguan to plan the week's operators; the checklist and Resumen use the same week.</p>
                            </div>
                            <button class="dialog-close" type="button" data-close-generator aria-label="Close"><?= icon('x') ?></button>
                        </div>
                        <div class="generator-body">
                            <div class="generator-groups">
                                <?php foreach ($printForms as $frequency => $forms): ?>
                                    <fieldset class="generator-group">
                                        <legend><?= ucfirst($frequency) ?></legend>
                                        <?php foreach ($forms as $formKey => $formLabel): ?>
                                            <?php $formAvailable = in_array($formKey, $availablePrintFormKeys, true); ?>
                                            <label class="generator-option <?= $formAvailable ? '' : 'generator-option-unavailable' ?>">
                                                <input type="checkbox" name="print_forms[]" value="<?= h($formKey) ?>" <?= $printSelections[$formKey] && $formAvailable ? 'checked' : '' ?> <?= $formAvailable ? '' : 'disabled' ?>>
                                                <span><?= h($formLabel) ?><?php if ($formKey === 'resumen_form6'): ?><small class="option-note">Half sheet (8.5 × 7 in), one week. Includes a salaysay letter for each Pagsamba with fewer than 3 operators.</small><?php endif; ?></span>
                                                <?php if (!$formAvailable): ?><em class="tag">Soon</em><?php endif; ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </fieldset>
                                <?php endforeach; ?>
                            </div>
                            <fieldset class="generator-group suguan-builder" data-suguan-builder hidden>
                                <legend>Suguan</legend>
                                <input type="hidden" name="suguan_week" value="<?= h($suguanPage['week']) ?>" data-suguan-week-input>
                                <div class="suguan-builder-head">
                                    <div class="week-nav">
                                        <button class="icon-button" type="button" data-week-step="previous" aria-label="Previous week"><?= icon('chevron-left') ?></button>
                                        <div class="week-label">
                                            <strong data-week-label><?= h($suguanPage['label']) ?></strong>
                                            <span class="muted" data-week-meta>Week <?= h($suguanPage['weekNumber']) ?> · This week</span>
                                        </div>
                                        <button class="icon-button" type="button" data-week-step="next" aria-label="Next week"><?= icon('chevron-right') ?></button>
                                        <button class="pill pill-static" type="button" data-week-step="current" hidden>This week</button>
                                    </div>
                                    <div class="suguan-toolbar">
                                        <button class="button button-light" type="button" data-suguan-rotate title="Computer → Audio, Audio → Video, Video → Computer"><?= icon('rotate') ?>Auto rotate</button>
                                        <button class="button button-light" type="button" data-suguan-sort><?= icon('sort') ?>Sort</button>
                                        <button class="button button-light" type="button" data-suguan-add><?= icon('plus') ?>Add service</button>
                                        <span class="save-state" data-save-state><?= $suguanPage['saved'] ? 'Saved' : 'Not saved yet' ?></span>
                                    </div>
                                </div>
                                <section class="officer-picker" aria-label="Active officers">
                                    <div class="officer-picker-head">
                                        <strong>Officers</strong>
                                        <span class="muted" data-picker-hint>Pick a name, then click a role below.</span>
                                    </div>
                                    <div class="officer-chips" data-officer-chips></div>
                                </section>
                                <div class="service-list" data-service-list></div>
                                <script type="application/json" id="suguan-data"><?= json_encode($suguanPage + [
                                    'csrf' => $_SESSION['csrf_token'],
                                ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
                            </fieldset>
                            <div class="form-grid meeting-fields" data-meeting-fields>
                                <label class="field">
                                    <span>Petsa at Oras ng Weekly Pulong</span>
                                    <input name="meeting_datetime" type="text" maxlength="150" required placeholder="Sunday, 3:00 PM" value="<?= h($weeklyMeeting['datetime']) ?>">
                                </label>
                                <label class="field">
                                    <span>Dako ng Pulong</span>
                                    <input name="meeting_venue" type="text" maxlength="150" required placeholder="Kapilya" value="<?= h($weeklyMeeting['venue']) ?>">
                                </label>
                            </div>
                            <fieldset class="generator-group agenda-fields" data-agenda-fields hidden>
                                <legend>Meeting agenda details</legend>
                                <div class="form-grid">
                                    <label class="field">
                                        <span>Unang Panalangin</span>
                                        <input name="first_prayer" type="text" maxlength="150" value="<?= h($agendaValues['first_prayer']) ?>">
                                    </label>
                                    <label class="field">
                                        <span>Huling Panalangin</span>
                                        <input name="last_prayer" type="text" maxlength="150" value="<?= h($agendaValues['last_prayer']) ?>">
                                    </label>
                                    <label class="field field-wide">
                                        <span>Pagpapayo/Lektura — name (optional)</span>
                                        <input name="lecture" type="text" maxlength="150" value="<?= h($agendaValues['lecture']) ?>">
                                    </label>
                                    <label class="field field-wide">
                                        <span>Agenda — first line is the topic title, the lines under it are its points; leave a blank line between topics.</span>
                                        <textarea name="agenda_topics" rows="8" maxlength="6000" placeholder="Paghahanda sa Pagsamba ng Linggo&#10;Pagtiyak na naka-charge ang mga camera&#10;Pagsusuri ng internet connection&#10;&#10;Recruitment ng bagong maytungkulin&#10;Pagpili ng mga kandidato"><?= h($agendaValues['topics']) ?></textarea>
                                    </label>
                                </div>
                            </fieldset>
                            <fieldset class="generator-group agenda-fields" data-monthly-fields hidden>
                                <legend>Monthly reports</legend>
                                <div class="form-grid">
                                    <label class="field">
                                        <span>Buwan (month covered)</span>
                                        <input name="report_month" type="month" value="<?= h($agendaValues['report_month'] ?: $today->format('Y-m')) ?>">
                                    </label>
                                    <label class="field" data-only-for="monthly_attendance">
                                        <span>Petsa ng Monthly Pulong</span>
                                        <input name="monthly_date" type="text" maxlength="150" placeholder="<?= h($today->format('F j, Y')) ?>" value="<?= h($agendaValues['monthly_date']) ?>" data-required>
                                    </label>
                                    <?php
                                    $recruitRows = [];
                                    foreach ((array) ($_POST['recruit_name'] ?? []) as $index => $name) {
                                        $recruitRows[] = [
                                            is_string($name) ? $name : '',
                                            is_string($_POST['recruit_birthday'][$index] ?? null) ? $_POST['recruit_birthday'][$index] : '',
                                        ];
                                    }
                                    ?>
                                    <label class="field" data-only-for="monthly_attendance">
                                        <span>Bilang ng recruits</span>
                                        <input name="recruit_count" type="number" min="0" max="20" step="1" value="<?= count($recruitRows) ?>">
                                    </label>
                                    <div class="field field-wide row-list" data-only-for="monthly_attendance" data-recruit-list>
                                        <span>Recruits</span>
                                        <div class="row-list-row recruit-row row-list-head" aria-hidden="true">
                                            <span>Pangalan</span><span>Birthday (optional)</span>
                                        </div>
                                        <div data-rows="recruit_count" data-max-rows="20">
                                            <?php foreach ($recruitRows ?: [['', '']] as [$recruitName, $recruitBirthday]): ?>
                                                <div class="row-list-row recruit-row" data-row>
                                                    <input name="recruit_name[]" type="text" maxlength="150" aria-label="Pangalan ng recruit" value="<?= h($recruitName) ?>" data-required>
                                                    <input name="recruit_birthday[]" type="date" aria-label="Birthday" value="<?= h($recruitBirthday) ?>">
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <label class="field" data-only-for="tsv_form3">
                                        <span>Webex display name</span>
                                        <input name="webex_display_name" type="text" maxlength="150" value="<?= h($weeklyMeeting['webex_display_name']) ?>" data-required>
                                    </label>
                                    <label class="field" data-only-for="tsv_form3">
                                        <span>Bilang kung ilang beses ginamit</span>
                                        <input name="webex_count" type="number" min="0" step="1" value="<?= h($agendaValues['webex_count'] !== '' ? $agendaValues['webex_count'] : '0') ?>">
                                    </label>
                                    <label class="field field-wide" data-only-for="tsv_form3" data-webex-when="unused">
                                        <span>Kung hindi ginamit, ano ang dahilan?</span>
                                        <input name="webex_reason" type="text" maxlength="250" placeholder="Wala pong aktibidad" value="<?= h($agendaValues['webex_reason']) ?>">
                                    </label>
                                    <?php
                                    $activityRows = [];
                                    foreach ((array) ($_POST['activity_name'] ?? []) as $index => $name) {
                                        $activityRows[] = [
                                            is_string($name) ? $name : '',
                                            is_string($_POST['activity_purpose'][$index] ?? null) ? $_POST['activity_purpose'][$index] : '',
                                            is_string($_POST['activity_date'][$index] ?? null) ? $_POST['activity_date'][$index] : '',
                                        ];
                                    }
                                    $activityRows = $activityRows ?: [['', '', '']];
                                    ?>
                                    <div class="field field-wide row-list activity-list" data-only-for="tsv_form3" data-webex-when="used">
                                        <span>Mga isinagawang aktibidad ng lokal — one row per use of Webex</span>
                                        <div class="row-list-row activity-row row-list-head" aria-hidden="true">
                                            <span>Aktibidad ng Lokal</span><span>Layunin</span><span>Petsa na Isinagawa</span>
                                        </div>
                                        <div data-rows="webex_count" data-max-rows="12">
                                            <?php foreach ($activityRows as [$activityName, $activityPurpose, $activityDate]): ?>
                                                <div class="row-list-row activity-row" data-row>
                                                    <input name="activity_name[]" type="text" maxlength="250" aria-label="Aktibidad ng Lokal" value="<?= h($activityName) ?>" data-required>
                                                    <input name="activity_purpose[]" type="text" maxlength="250" aria-label="Layunin" value="<?= h($activityPurpose) ?>" data-required>
                                                    <input name="activity_date[]" type="date" aria-label="Petsa na Isinagawa" value="<?= h($activityDate) ?>" data-required>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <p class="field-note">TSV Form 3 counts and the Directory Report schedule are filled from the officer records and the Suguan.</p>
                            </fieldset>
                            <p class="generator-note">Forms marked <em class="tag">Soon</em> need their DOCX templates added before they can be generated.</p>
                        </div>
                        <div class="panel-footer">
                            <span></span>
                            <div class="form-actions">
                                <button class="button button-light" type="button" data-close-generator>Cancel</button>
                                <button class="button" type="submit">Generate</button>
                            </div>
                        </div>
                    </form>
                </dialog>
            <?php endif; ?>
            <footer class="page-footer">TSV Officer Masterlist · Records are stored in your local MySQL database.</footer>
        </main>
    </div>
</div>
<script src="app.js?v=19"></script>
<?php if ($action === 'list'): ?><script src="suguan.js?v=5"></script><?php endif; ?>
</body>
</html>
