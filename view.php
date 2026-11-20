<?php
declare(strict_types=1);

// The page markup. A port of the desktop/Android app's page.rs so the website looks and works the same.
// Expects the variables index.php prepares ($action, $errors, $officers, $signatories, ...).

$csrfInput = '<input type="hidden" name="csrf_token" value="' . h($_SESSION['csrf_token']) . '">';
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
$todayYmd = $today->format('Y-m-d');
$localeBadge = $signatories['locale_name'] !== '' ? $signatories['locale_name'] : 'Locale';
$active = static fn(string $a): string => $action === $a ? 'is-active' : '';
$sel = static fn(bool $cond): string => $cond ? 'selected' : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Locale TSV Secretary System</title>
    <link rel="icon" type="image/png" href="logo.png">
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
    <link rel="stylesheet" href="style.css?v=31">
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <a class="team" href="index.php">
            <span class="team-avatar" aria-hidden="true"></span>
            <span class="team-name" title="Locale TSV Secretary System">LTSVSS</span>
            <span class="team-badge"><?= h($localeBadge) ?></span>
        </a>
        <nav class="side-nav" aria-label="Main">
            <a class="side-link <?= $active('list') ?>" href="index.php"><?= icon('grid') ?><span>Overview</span></a>
            <a class="side-link <?= $active('form') ?>" href="index.php?action=form"><?= icon('user-plus') ?><span><?= $action === 'form' && $isEditing ? 'Edit officer' : 'Add officer' ?></span></a>
            <?php if ($action === 'list'): ?>
                <button class="side-link desktop-nav" type="button" data-open-generator><?= icon('printer') ?><span>Form generator</span></button>
            <?php else: ?>
                <a class="side-link desktop-nav" href="index.php#generator"><?= icon('printer') ?><span>Form generator</span></a>
            <?php endif; ?>
            <a class="side-link mobile-nav <?= $active('generator') ?>" href="index.php?action=generator"><?= icon('printer') ?><span>Form generator</span></a>
            <a class="side-link <?= $active('settings') ?>" href="index.php?action=settings"><?= icon('pen') ?><span>Signatories</span></a>
            <?php if ($isSuperadmin): ?>
                <a class="side-link <?= $active('accounts') ?>" href="index.php?action=accounts"><?= icon('users') ?><span>Accounts</span></a>
            <?php endif; ?>
        </nav>
    </aside>

    <div class="main">
        <header class="topbar">
            <a class="mobile-brand" href="index.php"><span class="team-avatar" aria-hidden="true"></span><span>LTSVSS</span></a>
            <div class="crumbs">
                <a href="index.php">All officers</a>
                <?php if ($action === 'form'): ?>
                    <span class="crumb-sep">/</span><span><?= $isEditing ? 'Edit' : 'New' ?></span>
                <?php elseif ($action === 'settings'): ?>
                    <span class="crumb-sep">/</span><span>Signatories</span>
                <?php elseif ($action === 'generator'): ?>
                    <span class="crumb-sep">/</span><span>Generate forms</span>
                <?php elseif ($action === 'accounts'): ?>
                    <span class="crumb-sep">/</span><span>Accounts</span>
                <?php endif; ?>
            </div>
            <div class="topbar-actions">
                <?php if ($action === 'list'): ?>
                    <button class="topbar-link" type="button" data-open-generator><?= icon('printer') ?><span>Generate forms</span></button>
                <?php endif; ?>
                <a class="topbar-link" href="index.php?action=form"><?= icon('plus') ?><span>Add New Officer</span></a>
                <form method="post" action="index.php?action=logout" class="topbar-signout">
                    <?= $csrfInput ?>
                    <button class="theme-toggle" type="submit" aria-label="Sign out" title="Sign out (<?= h($currentUser['email']) ?>)"><?= icon('logout') ?></button>
                </form>
                <button class="theme-toggle" type="button" data-theme-toggle aria-label="Toggle light and dark mode" title="Toggle theme">
                    <span class="theme-icon-dark"><?= icon('moon') ?></span>
                    <span class="theme-icon-light"><?= icon('sun') ?></span>
                </button>
            </div>
        </header>

        <main class="content">
<?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
<?php endforeach; ?>
            <div id="status-feedback" class="alert alert-error" role="alert" hidden></div>
<?php
if ($action === 'settings') {
    viewSettings($csrfInput, $signatories, $todayYmd);
} elseif ($action === 'accounts') {
    echo '<div class="page-title"><h1>Accounts</h1></div>';
    echo accountsBody($pdo, $currentUser, $accountMessage ?? null);
} elseif ($action === 'import_review' && is_array($_SESSION['import_preview'] ?? null)) {
    viewImportReview($csrfInput, $_SESSION['import_preview'], $officerTotals['total']);
} elseif ($action === 'form') {
    viewOfficerForm($csrfInput, $formValues, $isEditing, $allowedRoles, $todayYmd);
} elseif ($action === 'generator') {
    echo '<div class="page-title"><h1>Generate forms</h1></div>';
    viewGenerator(true);
} else {
    viewOverview($csrfInput, $officers, $search ?? '', $statusFilter, $officerTotals, $birthdays, $today);
    viewGenerator(false);
}
?>
        </main>
    </div>
</div>
<dialog class="confirm-dialog" id="confirm-dialog" aria-labelledby="confirm-title">
    <div class="confirm-icon" data-confirm-icon><?= icon('trash') ?></div>
    <h2 id="confirm-title" data-confirm-title>Are you sure?</h2>
    <p data-confirm-message></p>
    <div class="form-actions">
        <button class="button button-light" type="button" data-confirm-cancel>Cancel</button>
        <button class="button button-danger" type="button" data-confirm-ok>Delete</button>
    </div>
</dialog>
<div class="toast-region" data-toasts aria-live="polite"></div>
<?php if ($toast): ?>
<script type="application/json" id="toast-data"><?= json_encode($toast, $jsonFlags) ?></script>
<?php endif; ?>
<script src="datepicker.js?v=3"></script>
<script src="app.js?v=32"></script>
<?php if ($action === 'list' || $action === 'generator'): ?><script src="suguan.js?v=31"></script><?php endif; ?>
</body>
</html>
<?php

// ---------------------------------------------------------------- sections

function viewSettings(string $csrfInput, array $v, string $today): void
{
    $f = static fn(string $k): string => h($v[$k] ?? '');
    ?>
<div class="page-title">
    <h1>Signatories</h1>
</div>
<section class="panel form-panel">
    <form method="post" action="index.php?action=settings">
        <?= $csrfInput ?>
        <input type="hidden" name="action" value="save_signatories">
        <div class="panel-body form-grid">
            <h2 class="field-wide form-section-title">Locale</h2>
            <label class="field"><span>Locale (Lokal)</span><input name="locale_name" type="text" maxlength="150" value="<?= $f('locale_name') ?>"></label>
            <label class="field"><span>District (Distrito)</span><input name="district_name" type="text" maxlength="150" value="<?= $f('district_name') ?>"></label>
            <label class="field"><span>Webex display name</span><input name="webex_display_name" type="text" maxlength="150" placeholder="MKN-FYM Avenue" value="<?= $f('webex_display_name') ?>"></label>
            <h2 class="field-wide form-section-title">Signatories</h2>
            <label class="field"><span>Pastor</span><input name="pastor" type="text" maxlength="150" value="<?= $f('pastor') ?>"></label>
            <label class="field"><span>PD Tagasubaybay</span><input name="pd_tagasubaybay" type="text" maxlength="150" value="<?= $f('pd_tagasubaybay') ?>"></label>
            <label class="field"><span>PD Tagasubaybay birthday (optional)</span><input name="pd_tagasubaybay_birthday" type="date" max="<?= $today ?>" value="<?= $f('pd_tagasubaybay_birthday') ?>"></label>
            <label class="field"><span>PD Tagasubaybay wedding anniversary (optional)</span><input name="pd_tagasubaybay_wedding" type="date" max="<?= $today ?>" value="<?= $f('pd_tagasubaybay_wedding') ?>"></label>
            <label class="field"><span>TSV Lead</span><input name="tsv_lead" type="text" maxlength="150" value="<?= $f('tsv_lead') ?>"></label>
            <label class="field"><span>Assistant TSV Lead</span><input name="assistant_tsv_lead" type="text" maxlength="150" value="<?= $f('assistant_tsv_lead') ?>"></label>
            <label class="field"><span>TSV Secretary</span><input name="tsv_secretary" type="text" maxlength="150" value="<?= $f('tsv_secretary') ?>"></label>
            <label class="field"><span>Tagapangasiwa ng Distrito</span><input name="district_supervisor" type="text" maxlength="150" value="<?= $f('district_supervisor') ?>"></label>
            <label class="field"><span>District TSV Overseer</span><input name="district_tsv_overseer" type="text" maxlength="150" value="<?= $f('district_tsv_overseer') ?>"></label>
        </div>
        <div class="panel-footer">
            <span></span>
            <div class="form-actions">
                <a class="button button-light" href="index.php">Cancel</a>
                <button class="button" type="submit">Save</button>
            </div>
        </div>
    </form>
</section>
    <?php
}

function viewImportReview(string $csrfInput, array $p, int $total): void
{
    $replace = $p['mode'] === 'replace';
    $n = count($p['records']);
    $summary = $p['file'] . " · {$n} officer records found";
    if ($p['skipped']) {
        $summary .= ' · ' . count($p['skipped']) . ' skipped';
    }
    if ($n === 0) {
        $message = "This file has no officer records, so the officers are left unchanged.";
    } elseif ($replace) {
        $message = "This will permanently delete all {$total} current officer records and replace them with the {$n} records below. Export an SQL backup first if you might need the current records.";
    } else {
        $message = "This will add the {$n} records below to the {$total} existing officer records. Blg. numbers that are already in use will be left blank.";
    }
    $confirm = $replace
        ? "All {$total} current officer records will be deleted and replaced with {$n} records from the file. This cannot be undone."
        : "{$n} officer records will be added to the masterlist.";
    ?>
<div class="page-title">
    <a class="back-link" href="index.php"><?= icon('arrow-left') ?>Back to officers</a>
    <h1>Review import</h1>
    <p><?= h($summary) ?></p>
</div>
<div class="confirm-box <?= $replace ? 'confirm-danger' : '' ?>">
    <strong>Are you sure?</strong>
    <p><?= h($message) ?></p>
</div>
<?php if ($p['skipped']): ?>
<div class="alert alert-error"><?php foreach (array_slice($p['skipped'], 0, 10) as $m): ?><div><?= h($m) ?></div><?php endforeach; ?></div>
<?php endif; ?>
<section class="panel">
    <div class="table-wrap">
        <table>
            <thead><tr><th>Blg.</th><th>Name</th><th>Tungkulin</th><th>Status</th><th>Oath-taking</th><th>Birthday</th><th>Telegram #</th></tr></thead>
            <tbody>
            <?php foreach ($p['records'] as $r): ?>
                <tr>
                    <td class="mono muted"><?= h((string) ($r['masterlist_number'] ?? '')) ?></td>
                    <td class="name-cell"><?= h($r['name']) ?></td>
                    <td class="muted"><?= h($r['tungkulin']) ?></td>
                    <td><span class="status <?= $r['is_active'] ? 'status-active' : 'status-inactive' ?>"><?= $r['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td class="muted"><?= h(displayRecordDate($r['oath_taking_date'], $r['oath_taking_text'])) ?></td>
                    <td class="muted"><?= h(displayDate($r['birthday'])) ?></td>
                    <td class="mono"><?= h($r['contact_telegram'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="panel-footer">
        <form method="post" action="index.php">
            <?= $csrfInput ?>
            <input type="hidden" name="action" value="import_cancel">
            <button class="button button-light" type="submit">Cancel</button>
        </form>
        <form method="post" action="index.php" data-confirm="<?= h($confirm) ?>" data-confirm-title="<?= $replace ? 'Replace all officer records?' : 'Add these officers?' ?>" data-confirm-button="<?= $replace ? 'Replace all' : 'Add officers' ?>"<?= $replace ? '' : ' data-confirm-safe' ?>>
            <?= $csrfInput ?>
            <input type="hidden" name="action" value="import_commit">
            <button class="button <?= $replace ? 'button-danger' : '' ?>" type="submit"><?= $replace ? 'Yes, replace all records' : "Yes, add {$n} records" ?></button>
        </form>
    </div>
</section>
    <?php
}


function viewOfficerForm(string $csrfInput, array $v, bool $editing, array $roles, string $today): void
{
    $oath = ($v['oath_taking_text'] ?? '') !== '' ? $v['oath_taking_text'] : (string) ($v['oath_taking_date'] ?? '');
    $ndi = ($v['ndi_signing_text'] ?? '') !== '' ? $v['ndi_signing_text'] : (string) ($v['ndi_signing_date'] ?? '');
    $id = (string) ($v['id'] ?? '');
    ?>
<div class="page-title">
    <a class="back-link" href="index.php"><?= icon('arrow-left') ?>Back to officers</a>
    <h1><?= $editing ? 'Edit officer' : 'Add officer' ?></h1>
</div>
<section class="panel form-panel">
    <form method="post" action="index.php?action=form<?= $editing ? '&amp;id=' . h($id) : '' ?>">
        <?= $csrfInput ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= h($id) ?>">
        <div class="panel-body form-grid">
            <label class="field field-wide">
                <span>Name <b>*</b></span>
                <input name="name" type="text" maxlength="150" required value="<?= h($v['name']) ?>" autocomplete="name">
            </label>
            <label class="field">
                <span>Tungkulin <b>*</b></span>
                <select name="tungkulin" required>
                    <option value="">Choose a tungkulin</option>
                    <?php foreach ($roles as $role): ?><option value="<?= h($role) ?>" <?= $v['tungkulin'] === $role ? 'selected' : '' ?>><?= h($role) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>Status</span>
                <select name="is_active">
                    <option value="1" <?= (int) $v['is_active'] === 1 ? 'selected' : '' ?>>Active</option>
                    <option value="0" <?= (int) $v['is_active'] === 0 ? 'selected' : '' ?>>Inactive</option>
                </select>
            </label>
            <label class="field"><span>Date of oath-taking</span><input name="oath_taking_date" type="text" maxlength="50" placeholder="Full date, year, or month and year" value="<?= h($oath) ?>"></label>
            <label class="field"><span>Date of NDA signing</span><input name="ndi_signing_date" type="text" maxlength="50" placeholder="If not applicable, just leave empty" value="<?= h($ndi) ?>"></label>
            <label class="field"><span>Birthday</span><input name="birthday" type="date" max="<?= $today ?>" value="<?= h((string) ($v['birthday'] ?? '')) ?>"></label>
            <label class="field"><span>Wedding anniversary (optional)</span><input name="wedding_anniversary" type="date" max="<?= $today ?>" value="<?= h((string) ($v['wedding_anniversary'] ?? '')) ?>"></label>
            <label class="field"><span>Contact (Active) Telegram #</span><input name="contact_telegram" type="text" maxlength="50" value="<?= h((string) ($v['contact_telegram'] ?? '')) ?>" autocomplete="tel"></label>
        </div>
        <div class="panel-footer">
            <span></span>
            <div class="form-actions">
                <a class="button button-light" href="index.php">Cancel</a>
                <button class="button" type="submit"><?= $editing ? 'Save changes' : 'Add officer' ?></button>
            </div>
        </div>
    </form>
</section>
    <?php
}

function viewOverview(string $csrfInput, array $officers, string $search, string $status, array $totals, array $birthdays, DateTimeImmutable $today): void
{
    ?>
<div class="page-title page-title-row">
    <div>
        <h1>Officers</h1>
    </div>
    <div class="page-title-actions">
        <button class="button button-light" type="button" data-open-import><?= icon('upload') ?>Import</button>
        <a class="button button-light" href="index.php?action=export_sql" title="Backup of the officer records and settings" data-download-toast="Backup Exported" data-download-message="The officer records were saved as an SQL file."><?= icon('database') ?>Export SQL</a>
        <a class="button button-light" href="index.php?action=export_xlsx" data-download-toast="Excel Exported" data-download-message="The masterlist was saved as an Excel file."><?= icon('sheet') ?>Export Excel</a>
        <form method="post" action="index.php" data-download-toast="Masterlist Generated!" data-download-message="The masterlist DOCX is downloading.">
            <input type="hidden" name="download_token" value="">
            <?= $csrfInput ?>
            <input type="hidden" name="action" value="generate_masterlist">
            <button class="button" type="submit"><?= icon('download') ?>Generate masterlist DOCX</button>
        </form>
    </div>
</div>

<section class="stats-row" aria-label="Officer totals">
    <div class="stat-card"><span>Total officers</span><strong data-total-count><?= $totals['total'] ?></strong></div>
    <div class="stat-card"><span><i class="dot dot-active"></i>Active</span><strong data-active-count><?= $totals['active'] ?></strong></div>
    <div class="stat-card"><span><i class="dot dot-inactive"></i>Inactive</span><strong data-inactive-count><?= $totals['inactive'] ?></strong></div>
</section>
<section class="panel birthdays" aria-label="Birthdays this month">
    <div class="birthdays-head">
        <?= icon('gift') ?>
        <strong>Birthdays in <?= $today->format('F') ?></strong>
        <span class="muted"><?= count($birthdays) ?> <?= count($birthdays) === 1 ? 'officer' : 'officers' ?></span>
    </div>
    <?php if (!$birthdays): ?>
        <p class="birthday-empty muted">No active officers have a birthday this month.</p>
    <?php else: ?>
        <ul class="birthday-list">
        <?php foreach ($birthdays as $b):
            $days = $b['days'];
            $when = $days === 0 ? "Today · turns {$b['age']}" : ($days > 0 ? "In {$days} " . ($days === 1 ? 'day' : 'days') . " · turns {$b['age']}" : "Turned {$b['age']}"); ?>
            <li class="<?= $days === 0 ? 'is-today' : ($days < 0 ? 'is-past' : '') ?>"><span class="birthday-date mono"><?= h($b['date']) ?></span><span class="birthday-name"><?= h($b['name']) ?></span><span class="birthday-when"><?= h($when) ?></span></li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<form class="filter-bar" action="index.php" method="get" data-filter-form>
    <label class="find">
        <?= icon('search') ?>
        <input type="search" name="search" placeholder="Find by name or tungkulin..." value="<?= h($search) ?>" aria-label="Search officers">
        <kbd>F</kbd>
    </label>
    <label class="pill">
        <?= icon('filter') ?>
        <span class="pill-label">Status</span>
        <select name="status" aria-label="Filter by status" data-status-filter="<?= h($status) ?>">
            <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All</option>
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
    </label>
    <?php if ($search !== ''): ?><span class="pill pill-static"><span class="pill-label">Search</span><?= h($search) ?></span><?php endif; ?>
    <?php if ($search !== '' || $status !== 'all'): ?><a class="pill pill-clear" href="index.php"><?= icon('x') ?>Clear</a><?php endif; ?>
    <button class="visually-hidden" type="submit">Search</button>
</form>

<section class="panel">
    <div class="table-wrap">
        <table class="officers-table">
            <thead>
                <tr>
                    <th>Blg.</th><th>Name</th><th>Tungkulin</th><th>Status</th><th>Oath-taking</th><th>NDA signing</th><th>Birthday</th><th>Telegram #</th>
                    <th class="actions-column"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody data-officers-body>
            <?php if (!$officers):
                $hasSearch = $search !== '';
                $msg = match (true) {
                    $status === 'active' && $hasSearch => 'No active officers match your search.',
                    $status === 'inactive' && $hasSearch => 'No inactive officers match your search.',
                    $status === 'active' => 'No active officers yet.',
                    $status === 'inactive' => 'No inactive officers.',
                    $hasSearch => 'No officers match your search.',
                    default => 'No officer records yet.',
                }; ?>
                <tr><td class="empty-state" colspan="9"><?= $msg ?></td></tr>
            <?php endif; ?>
            <?php foreach ($officers as $of):
                $act = (int) $of['is_active'] === 1;
                $name = h($of['name']);
                $id = (int) $of['id'];
                $cells = [
                    ['muted', 'Oath-taking', displayRecordDate($of['oath_taking_date'], $of['oath_taking_text'])],
                    ['muted', 'NDA signing', displayRecordDate($of['ndi_signing_date'], $of['ndi_signing_text'])],
                    ['muted', 'Birthday', displayDate($of['birthday'])],
                    ['mono', 'Telegram #', (string) ($of['contact_telegram'] ?? '')],
                ]; ?>
                <tr data-officer-row data-active="<?= $act ? '1' : '0' ?>">
                    <td class="mono muted cell-blg"><?= h((string) ($of['masterlist_number'] ?? '')) ?></td>
                    <td class="name-cell"><?= $name ?></td>
                    <td class="muted cell-role"><?= h($of['tungkulin']) ?></td>
                    <td class="cell-status">
                        <form method="post" action="index.php" class="status-toggle-form" data-status-toggle>
                            <?= $csrfInput ?>
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <button class="status status-toggle <?= $act ? 'status-active' : 'status-inactive' ?>" type="submit" aria-label="Set <?= $name ?> to <?= $act ? 'inactive' : 'active' ?>" title="Click to change status"><?= $act ? 'Active' : 'Inactive' ?></button>
                        </form>
                    </td>
                    <?php foreach ($cells as [$cls, $label, $value]): ?>
                    <td class="<?= $cls ?> cell-detail" data-label="<?= $label ?>"<?= $value === '' || $value === '—' ? ' data-empty' : '' ?>><?= h($value) ?></td>
                    <?php endforeach; ?>
                    <td class="row-actions">
                        <a class="row-icon" href="index.php?action=form&amp;id=<?= $id ?>" aria-label="Edit <?= $name ?>" title="Edit"><?= icon('pen') ?></a>
                        <form method="post" action="index.php" data-confirm="<?= $name ?> will be removed from the masterlist. This cannot be undone." data-confirm-title="Delete this officer?" data-confirm-button="Delete">
                            <?= $csrfInput ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <button class="row-icon row-icon-danger" type="submit" aria-label="Delete <?= $name ?>" title="Delete"><?= icon('trash') ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<dialog class="generator-dialog import-dialog" id="import-dialog" aria-labelledby="import-title">
    <form method="post" action="index.php" enctype="multipart/form-data" class="generator-form">
        <?= $csrfInput ?>
        <input type="hidden" name="action" value="import_preview">
        <div class="generator-heading">
            <div><h2 id="import-title">Import officer records</h2></div>
            <button class="dialog-close" type="button" data-close-dialog aria-label="Close"><?= icon('x') ?></button>
        </div>
        <div class="generator-body">
            <label class="dropzone" data-dropzone>
                <input class="dropzone-input" name="import_file" type="file" accept=".sql,.mysql,.txt" required data-import-file>
                <span class="dropzone-icon"><?= icon('upload') ?></span>
                <strong data-dropzone-title>Drag &amp; drop your backup here</strong>
                <span class="muted" data-dropzone-hint data-max-bytes="<?= importMaxBytes() ?>">or click to browse · .sql or .mysql, up to <?= h(formatBytes(importMaxBytes())) ?></span>
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
    <?php
}

/** The form generator: a pop-up on the overview page, or ($inline) a page of its own for phones. */
function viewGenerator(bool $inline): void
{
    global $pdo, $printForms, $printSelections, $suguanPage, $weeklyMeeting, $agendaValues, $savedExtraForms, $savedRecruits, $today, $jsonFlags;
    $reposted = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_print_forms';
    $posted = static fn(string $k): string => $reposted && is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
    $postedList = static fn(string $k): array => is_array($_POST[$k] ?? null) ? array_values($_POST[$k]) : [];
    $reportMonth = $posted('report_month');
    $webexCount = $posted('webex_count');
    $todayYmd = $today->format('Y-m-d');
    ?>
<dialog class="generator-dialog<?= $inline ? ' generator-inline' : '' ?>" id="print-generator" aria-labelledby="generator-title"<?= $inline ? ' open' : '' ?>>
    <form method="post" action="index.php<?= $inline ? '?action=generator' : '' ?>" class="generator-form" data-download-toast="Form Generated!" data-download-message="Your forms are downloading."<?= $inline ? '' : ' data-download-close' ?>>
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="generate_print_forms">
        <input type="hidden" name="download_token" value="">
        <div class="generator-heading">
            <div><h2 id="generator-title">Generate forms</h2></div>
            <button class="dialog-close" type="button" data-close-generator aria-label="Close"><?= icon('x') ?></button>
        </div>
        <div class="generator-body">
            <div class="generator-groups">
            <?php foreach ($printForms as $frequency => $forms): ?>
                <fieldset class="generator-group"><legend><?= $frequency === 'weekly' ? 'Weekly' : 'Monthly' ?></legend>
                <?php foreach ($forms as $key => $label): ?>
                    <label class="generator-option "><input type="checkbox" name="print_forms[]" value="<?= h($key) ?>" <?= !empty($printSelections[$key]) ? 'checked' : '' ?> ><span><?= h($label) ?></span></label>
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
                        <button class="button button-light" type="button" data-suguan-sort title="Match the worship schedule reported in the INC Directory"><?= icon('sort') ?>Normalize</button>
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
                <script type="application/json" id="suguan-data"><?= json_encode($suguanPage + ['csrf' => $_SESSION['csrf_token']], $jsonFlags) ?></script>
            </fieldset>
            <div class="form-grid meeting-fields" data-meeting-fields>
                <label class="field"><span>Petsa at Oras ng Weekly Pulong</span><input name="meeting_datetime" type="text" maxlength="150" required placeholder="Sunday, 3:00 PM" value="<?= h($weeklyMeeting['datetime']) ?>"></label>
                <label class="field"><span>Dako ng Pulong</span><input name="meeting_venue" type="text" maxlength="150" required placeholder="Kapilya" value="<?= h($weeklyMeeting['venue']) ?>"></label>
            </div>
            <fieldset class="generator-group agenda-fields" data-agenda-fields hidden>
                <legend>Meeting agenda details</legend>
                <div class="form-grid">
                    <label class="field"><span>Unang Panalangin (optional)</span><input name="first_prayer" type="text" maxlength="150" value="<?= h($agendaValues['first_prayer']) ?>"></label>
                    <label class="field"><span>Huling Panalangin (optional)</span><input name="last_prayer" type="text" maxlength="150" value="<?= h($agendaValues['last_prayer']) ?>"></label>
                    <label class="field field-wide"><span>Pagpapayo/Lektura — name (optional)</span><input name="lecture" type="text" maxlength="150" value="<?= h($agendaValues['lecture']) ?>"></label>
                    <label class="field field-wide">
                        <span>Agenda topics</span>
                        <textarea name="agenda_topics" rows="8" maxlength="6000" placeholder="Paghahanda sa Pagsamba ng Linggo&#10;Pagtiyak na naka-charge ang mga camera&#10;Pagsusuri ng internet connection&#10;&#10;Recruitment ng bagong maytungkulin&#10;Pagpili ng mga kandidato"><?= h($agendaValues['topics']) ?></textarea>
                    </label>
                </div>
            </fieldset>
            <fieldset class="generator-group agenda-fields" data-forms-resumen-fields data-shown-for="forms_resumen" hidden>
                <legend>Forms Resumen</legend>
                <label class="field field-wide">
                    <span>Karagdagang forms? (optional, one per line)</span>
                    <textarea name="extra_forms" rows="4" maxlength="3000" placeholder="Initial report: HVO Training with picture attachment"><?= h($savedExtraForms) ?></textarea>
                </label>
            </fieldset>
            <fieldset class="generator-group agenda-fields" data-monthly-fields hidden>
                <legend>Monthly reports</legend>
                <div class="form-grid">
                    <label class="field"><span>Buwan (month covered)</span><input name="report_month" type="month" value="<?= h($reportMonth !== '' ? $reportMonth : $today->format('Y-m')) ?>"></label>
                    <label class="field" data-only-for="monthly_attendance"><span>Petsa ng Monthly Pulong</span><input name="monthly_date" type="text" maxlength="150" placeholder="<?= h($today->format('F j, Y')) ?>" value="<?= h($posted('monthly_date')) ?>" data-required></label>
<?php
    // Recruits: after a failed submit the posted rows come back; otherwise the last generated list is shown.
    $recruitRows = [];
    if (isset($_POST['recruit_count'])) {
        $b = $postedList('recruit_birthday');
        $w = $postedList('recruit_wedding');
        foreach ($postedList('recruit_name') as $i => $n) {
            $recruitRows[] = [(string) $n, (string) ($b[$i] ?? ''), (string) ($w[$i] ?? '')];
        }
        $recruitCount = (int) $_POST['recruit_count'];
    } else {
        foreach (json_decode($savedRecruits, true) ?: [] as $r) {
            if (is_array($r)) {
                $r = array_values($r);
                $recruitRows[] = [(string) ($r[0] ?? ''), (string) ($r[1] ?? ''), (string) ($r[2] ?? '')];
            }
        }
        $recruitCount = count($recruitRows);
    }
    if (!$recruitRows) {
        $recruitRows[] = ['', '', ''];
    }
    ?>
                    <label class="field" data-only-for="monthly_attendance"><span>Bilang ng recruits</span><input name="recruit_count" type="number" min="0" max="20" step="1" value="<?= max(0, min(20, $recruitCount)) ?>"></label>
                    <div class="field field-wide row-list" data-only-for="monthly_attendance" data-recruit-list>
                        <span>Recruits</span>
                        <div class="row-list-row recruit-row row-list-head" aria-hidden="true"><span>Pangalan</span><span>Birthday (optional)</span><span>Wedding (optional)</span></div>
                        <div data-rows="recruit_count" data-max-rows="20">
                        <?php foreach ($recruitRows as [$n, $b, $w]): ?>
                            <div class="row-list-row recruit-row" data-row>
                                <input name="recruit_name[]" type="text" maxlength="150" aria-label="Pangalan ng recruit" value="<?= h($n) ?>" data-required>
                                <input name="recruit_birthday[]" type="date" max="<?= $todayYmd ?>" aria-label="Birthday" value="<?= h($b) ?>">
                                <input name="recruit_wedding[]" type="date" max="<?= $todayYmd ?>" aria-label="Wedding date" value="<?= h($w) ?>">
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                    <label class="field" data-only-for="tsv_form3"><span>Bilang kung ilang beses ginamit</span><input name="webex_count" type="number" min="0" step="1" value="<?= h($webexCount !== '' ? $webexCount : '0') ?>"></label>
                    <label class="field field-wide" data-only-for="tsv_form3" data-webex-when="unused"><span>Kung hindi ginamit, ano ang dahilan?</span><input name="webex_reason" type="text" maxlength="250" placeholder="Wala pong aktibidad" value="<?= h($posted('webex_reason')) ?>"></label>
                    <div class="field field-wide row-list activity-list" data-only-for="tsv_form3" data-webex-when="used">
                        <span>Mga isinagawang aktibidad ng lokal — one row per use of Webex</span>
                        <div class="row-list-row activity-row row-list-head" aria-hidden="true"><span>Aktibidad ng Lokal</span><span>Layunin</span><span>Petsa na Isinagawa</span></div>
                        <div data-rows="webex_count" data-max-rows="12">
<?php
    $names = $postedList('activity_name');
    $purposes = $postedList('activity_purpose');
    $dates = $postedList('activity_date');
    $activityRows = [];
    foreach ($names as $i => $n) {
        $activityRows[] = [(string) $n, (string) ($purposes[$i] ?? ''), (string) ($dates[$i] ?? '')];
    }
    if (!$activityRows) {
        $activityRows[] = ['', '', ''];
    }
    foreach ($activityRows as [$n, $p, $d]): ?>
                            <div class="row-list-row activity-row" data-row>
                                <input name="activity_name[]" type="text" maxlength="250" aria-label="Aktibidad ng Lokal" value="<?= h($n) ?>" data-required>
                                <input name="activity_purpose[]" type="text" maxlength="250" aria-label="Layunin" value="<?= h($p) ?>" data-required>
                                <input name="activity_date[]" type="date" aria-label="Petsa na Isinagawa" value="<?= h($d) ?>" data-required>
                            </div>
<?php endforeach; ?>
                        </div>
                    </div>
<?php viewDirectoryFields($pdo, $reposted, $today); ?>
                </div>
            </fieldset>
        </div>
        <div class="panel-footer">
            <span></span>
            <div class="form-actions">
                <?php if ($inline): ?><a class="button button-light" href="index.php">Cancel</a><?php else: ?><button class="button button-light" type="button" data-close-generator>Cancel</button><?php endif; ?>
                <button class="button" type="submit">Generate</button>
            </div>
        </div>
    </form>
</dialog>
    <?php
}

/** The Directory Report form: filled with the last report (or the Suguan before the first one). */
function viewDirectoryFields(PDO $pdo, bool $reposted, DateTimeImmutable $today): void
{
    $month = $today->modify('first day of this month');
    $saved = directoryFormValues($pdo, $month);
    $postedDays = is_array($_POST['dir_day'] ?? null) ? array_values($_POST['dir_day']) : [];
    if ($reposted && $postedDays) {
        $times = array_values($_POST['dir_time'] ?? []);
        $langs = array_values($_POST['dir_lang'] ?? []);
        $rows = [];
        foreach ($postedDays as $i => $d) {
            $rows[] = ['day' => (string) $d, 'time' => (string) ($times[$i] ?? ''), 'lang' => (string) ($langs[$i] ?? '')];
        }
    } else {
        $rows = $saved['services'];
    }
    $value = static fn(string $key, string $savedValue): string => h($reposted && isset($_POST[$key]) ? trim((string) $_POST[$key]) : $savedValue);
    $baseline = directoryBaseline($pdo, $month);
    $baselineRows = array_map(static fn(array $s): array => [$s['day'], $s['time'], $s['lang']], $baseline['services']);
    $updateOn = $reposted ? ($_POST['dir_update'] ?? '') === '1' : directoryChangesThisMonth($pdo, $month);
    $dayOptions = DIRECTORY_DAYS;
    foreach (DIRECTORY_DAYS as $d) {
        $dayOptions[] = "{$d} (PNK)";
    }
    ?>
                    <label class="field field-wide generator-option dir-toggle" data-only-for="directory_report">
                        <span>Update data in the INC Directory</span>
                        <input type="checkbox" name="dir_update" value="1" data-dir-toggle<?= $updateOn ? ' checked' : '' ?>>
                    </label>
                    <div class="field field-wide row-list" data-only-for="directory_report" data-dir-detail data-dir-baseline="<?= h(json_encode($baselineRows, JSON_UNESCAPED_UNICODE)) ?>">
                        <span>Mga Oras ng Pagsamba</span>
                        <div class="row-list-row directory-row row-list-head" aria-hidden="true"><span>Araw</span><span>Oras</span><span>Wika</span></div>
                        <?php foreach ($rows as $row): $english = $row['lang'] === 'English'; ?>
                        <div class="row-list-row directory-row" data-dir-row>
                            <select name="dir_day[]" aria-label="Araw"><?php foreach ($dayOptions as $d): ?><option value="<?= h($d) ?>" <?= $d === $row['day'] ? 'selected' : '' ?>><?= h($d) ?></option><?php endforeach; ?></select>
                            <input name="dir_time[]" type="time" aria-label="Oras" value="<?= h($row['time']) ?>" data-required>
                            <select name="dir_lang[]" aria-label="Wika"><option value="Tagalog" <?= $english ? '' : 'selected' ?>>Tagalog</option><option value="English" <?= $english ? 'selected' : '' ?>>English (EWS)</option></select>
                            <small class="dir-was" hidden></small>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <label class="field field-wide" data-only-for="directory_report" data-dir-detail><span>Address</span><input name="dir_address" type="text" maxlength="250" value="<?= $value('dir_address', $saved['address']) ?>" data-baseline="<?= h($baseline['address']) ?>"></label>
                    <label class="field" data-only-for="directory_report" data-dir-detail><span>Latitude</span><input name="dir_latitude" type="text" maxlength="30" value="<?= $value('dir_latitude', $saved['latitude']) ?>" data-baseline="<?= h($baseline['latitude']) ?>"></label>
                    <label class="field" data-only-for="directory_report" data-dir-detail><span>Longitude</span><input name="dir_longitude" type="text" maxlength="30" value="<?= $value('dir_longitude', $saved['longitude']) ?>" data-baseline="<?= h($baseline['longitude']) ?>"></label>
                    <label class="field" data-only-for="directory_report" data-dir-detail><span>Landline</span><input name="dir_landline" type="text" maxlength="60" placeholder="Wala po" value="<?= $value('dir_landline', $saved['landline']) ?>"></label>
                    <label class="field" data-only-for="directory_report" data-dir-detail><span>Email</span><input name="dir_email" type="text" maxlength="150" placeholder="Wala po" value="<?= $value('dir_email', $saved['email']) ?>"></label>
                    <label class="field field-wide" data-only-for="directory_report" data-dir-detail><span>Pansin (optional)</span><input name="dir_note" type="text" maxlength="500" value="<?= $value('dir_note', $saved['note']) ?>"></label>
    <?php
}
