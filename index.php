<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/docx.php';
require __DIR__ . '/docx_templates.php';

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

$allowedActions = ['list', 'form', 'settings'];
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
        'tsv_form3' => 'TSV Form 3',
        'directory_report' => 'Directory Report',
    ],
];
$allPrintFormKeys = array_merge(array_keys($printForms['weekly']), array_keys($printForms['monthly']));
$availablePrintFormKeys = ['pagpapalaganp', 'weekly_attendance', 'meeting_agenda'];
$action = $_GET['action'] ?? 'list';
if (!is_string($action) || !in_array($action, $allowedActions, true)) {
    $action = 'list';
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
    if ($postAction === 'save_print_selection' || $postAction === 'generate_masterlist') {
        $rawSelectedForms = $_POST['print_forms'] ?? [];
        $selectedForms = [];
        if (is_array($rawSelectedForms)) {
            foreach ($rawSelectedForms as $formKey) {
                if (is_string($formKey) && in_array($formKey, $allPrintFormKeys, true)) {
                    $selectedForms[] = $formKey;
                }
            }
        }
        $selectedForms = array_values(array_unique($selectedForms));

        try {
            savePrintSelections($pdo, $allPrintFormKeys, $selectedForms);
            if ($postAction === 'save_print_selection') {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true]);
                exit;
            }

            $masterlist = $pdo->query(
                'SELECT * FROM officers ORDER BY masterlist_number IS NULL, masterlist_number ASC, is_active DESC, name ASC'
            )->fetchAll();
            $docx = generateOfficerMasterlistDocx($masterlist);
            header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
            header('Content-Disposition: attachment; filename="tsv-officer-masterlist-' . date('Y-m-d') . '.docx"');
            header('Content-Length: ' . strlen($docx));
            echo $docx;
            exit;
        } catch (Throwable $e) {
            $operation = $postAction === 'save_print_selection'
                ? 'Save print selections'
                : 'Generate officer masterlist';
            error_log($operation . ' failed: ' . $e->getMessage());
            if ($postAction === 'save_print_selection' && $expectsJson) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Could not save print selections. Please try again.']);
                exit;
            }
            http_response_code(500);
            exit('Could not generate the officer masterlist DOCX. Please check the PHP error log and try again.');
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
                $_SESSION['notice'] = 'Signatory names updated.';
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

$signatories = [
    'pastor' => '',
    'pd_tagasubaybay' => '',
    'tsv_lead' => '',
    'assistant_tsv_lead' => '',
    'tsv_secretary' => '',
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
    } elseif (str_starts_with($setting['setting_key'], 'print_form_')) {
        $formKey = substr($setting['setting_key'], strlen('print_form_'));
        if (array_key_exists($formKey, $printSelections)) {
            $printSelections[$formKey] = $setting['setting_value'] === '1';
        }
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
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <main class="container">
        <header class="page-header">
            <div>
                <p class="eyebrow">TSV SECRETARY</p>
                <h1>Officer Masterlist</h1>
                <p class="subtitle">Manage officer records and generate the all-officer masterlist.</p>
            </div>
            <nav class="header-actions" aria-label="Masterlist actions">
                <?php if ($action === 'list'): ?>
                    <a class="button button-light" href="index.php?action=settings">Edit signatories</a>
                    <button class="button button-light" type="button" data-open-generator>Form generator</button>
                    <a class="button" href="index.php?action=form">＋ Add officer</a>
                <?php elseif ($action === 'settings'): ?>
                    <a class="button button-light" href="index.php">Back to masterlist</a>
                <?php endif; ?>
            </nav>
        </header>

        <?php if ($notice): ?>
            <div class="alert alert-success"><?= h($notice) ?></div>
        <?php endif; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php endforeach; ?>
        <div id="status-feedback" class="alert alert-error" role="alert" hidden></div>

        <?php if ($action === 'settings'): ?>
            <section class="panel form-panel">
                <div class="section-heading">
                    <div>
                        <p class="eyebrow">PRINTED INFORMATION SHEETS</p>
                        <h2>Signatory names</h2>
                        <p class="muted">These names are stored in the database and appear on officer information sheets.</p>
                    </div>
                </div>
                <form method="post" action="index.php?action=settings">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="save_signatories">
                    <div class="form-grid">
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
                    </div>
                    <div class="form-actions">
                        <a class="button button-light" href="index.php">Cancel</a>
                        <button class="button" type="submit">Save names</button>
                    </div>
                </form>
            </section>
        <?php elseif ($action === 'form'): ?>
            <section class="panel form-panel">
                <div class="section-heading">
                    <div>
                        <p class="eyebrow">OFFICER RECORD</p>
                        <h2><?= $isEditing ? 'Edit officer' : 'Add officer' ?></h2>
                    </div>
                    <a class="text-link" href="index.php">← Back to masterlist</a>
                </div>
                <form method="post" action="index.php?action=form<?= $isEditing ? '&amp;id=' . rawurlencode((string) $formValues['id']) : '' ?>">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?= h((string) $formValues['id']) ?>">
                    <div class="form-grid">
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
                        <label class="field field-wide">
                            <span>Contact (Active) Telegram #</span>
                            <input name="contact_telegram" type="text" maxlength="50" value="<?= h((string) ($formValues['contact_telegram'] ?? '')) ?>" autocomplete="tel">
                        </label>
                    </div>
                    <div class="form-actions">
                        <a class="button button-light" href="index.php">Cancel</a>
                        <button class="button" type="submit"><?= $isEditing ? 'Save changes' : 'Add officer' ?></button>
                    </div>
                </form>
            </section>
        <?php else: ?>
            <section class="stats-row" aria-label="Officer totals">
                <div class="stat-card">
                    <span>Total officers</span>
                    <strong data-total-count><?= count($officers) ?></strong>
                </div>
                <div class="stat-card">
                    <span>Active officers</span>
                    <strong data-active-count><?= count(array_filter($officers, static fn(array $officer): bool => (bool) $officer['is_active'])) ?></strong>
                </div>
            </section>

            <section class="panel">
                <div class="list-toolbar">
                    <div>
                        <h2>All officers</h2>
                        <p class="muted">Search by name or tungkulin.</p>
                    </div>
                    <form class="search-form" action="index.php" method="get" data-filter-form>
                        <input type="search" name="search" placeholder="Search officers..." value="<?= h($search ?? '') ?>" aria-label="Search officers">
                        <select name="status" aria-label="Filter by status" data-status-filter="<?= h($statusFilter) ?>">
                            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All statuses</option>
                            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active only</option>
                            <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive only</option>
                        </select>
                        <button class="button button-small" type="submit">Search</button>
                        <?php if (!empty($search) || $statusFilter !== 'all'): ?><a class="text-link" href="index.php">Clear</a><?php endif; ?>
                    </form>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Blg.</th>
                                <th>Name</th>
                                <th>Tungkulin</th>
                                <th>Oath-taking</th>
                                <th>NDA signing</th>
                                <th>Birthday</th>
                                <th>Contact (Active) Telegram #</th>
                                <th>Status</th>
                                <th class="actions-column">Actions</th>
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
                                        <td><?= h((string) ($officer['masterlist_number'] ?? '')) ?></td>
                                        <td class="name-cell"><?= h($officer['name']) ?></td>
                                        <td><?= h($officer['tungkulin']) ?></td>
                                        <td><?= h(displayRecordDate($officer['oath_taking_date'], $officer['oath_taking_text'])) ?></td>
                                        <td><?= h(displayRecordDate($officer['ndi_signing_date'], $officer['ndi_signing_text'])) ?></td>
                                        <td><?= h(displayDate($officer['birthday'])) ?></td>
                                        <td><?= h($officer['contact_telegram'] ?? '') ?></td>
                                        <td>
                                            <form method="post" action="index.php" class="status-toggle-form" data-status-toggle>
                                                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="id" value="<?= h((string) $officer['id']) ?>">
                                                <button class="status status-toggle <?= $officer['is_active'] ? 'status-active' : 'status-inactive' ?>" type="submit" aria-label="Set <?= h($officer['name']) ?> to <?= $officer['is_active'] ? 'inactive' : 'active' ?>" title="Click to change status"><?= $officer['is_active'] ? 'Active' : 'Inactive' ?></button>
                                            </form>
                                        </td>
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
            <dialog class="generator-dialog" id="print-generator" aria-labelledby="generator-title">
                <form method="post" action="index.php" class="generator-form">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="generate_masterlist">
                    <div class="generator-heading">
                        <div>
                            <p class="eyebrow">PRINT PORTAL</p>
                            <h2 id="generator-title">What do you want to print?</h2>
                        </div>
                        <button class="dialog-close" type="button" data-close-generator aria-label="Close">×</button>
                    </div>
                    <p class="muted">Choose your usual weekly and monthly forms. Your selections are remembered for next time. Generate masterlist downloads one DOCX containing all officers.</p>
                    <div class="generator-groups">
                        <?php foreach ($printForms as $frequency => $forms): ?>
                            <fieldset class="generator-group">
                                <legend><?= ucfirst($frequency) ?></legend>
                                <?php foreach ($forms as $formKey => $formLabel): ?>
                                    <label class="generator-option">
                                        <input type="checkbox" name="print_forms[]" value="<?= h($formKey) ?>" <?= $printSelections[$formKey] ? 'checked' : '' ?>>
                                        <span><?= h($formLabel) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                        <?php endforeach; ?>
                    </div>
                    <p class="generator-note">The masterlist DOCX is ready now. The weekly and monthly form templates need their document layouts before those selected forms can be auto-filled.</p>
                    <div class="form-actions">
                        <button class="button button-light" type="button" data-close-generator>Cancel</button>
                        <button class="button" type="submit">Generate masterlist DOCX</button>
                    </div>
                </form>
            </dialog>
        <?php endif; ?>
        <footer>TSV Officer Masterlist <span>•</span> Records are stored in your local MySQL database.</footer>
    </main>
<script src="app.js?v=4"></script>
</body>
</html>
