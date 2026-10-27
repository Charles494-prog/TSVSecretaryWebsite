<?php
declare(strict_types=1);

// Shown instead of the app when MySQL cannot be reached.
http_response_code(503);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TSV Officer Masterlist</title>
    <script>
        try {
            document.documentElement.dataset.theme = localStorage.getItem('theme') === 'light' ? 'light' : 'dark';
        } catch {
            document.documentElement.dataset.theme = 'dark';
        }
    </script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@400;500&display=swap">
    <link rel="stylesheet" href="style.css?v=13">
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <a class="team" href="index.php">
            <span class="team-avatar" aria-hidden="true"></span>
            <span class="team-name">TSV Secretary</span>
        </a>
        <div class="side-footer">
            <span class="team-avatar team-avatar-small" aria-hidden="true"></span>
            <span data-db-label>No connection</span>
            <span class="side-footer-dot is-offline" data-db-dot title="MySQL is not reachable"></span>
        </div>
    </aside>
    <div class="main">
        <main class="content">
            <div class="offline-card panel">
                <span class="offline-dot" aria-hidden="true"></span>
                <h1>No database connection</h1>
                <p>The site can't reach MySQL, so officer records can't be loaded right now.</p>
                <ol>
                    <li>Open the <strong>XAMPP Control Panel</strong> and click <strong>Start</strong> next to <strong>MySQL</strong>.</li>
                    <li>Make sure the <code>tsv_secretary</code> database was imported from <code>database.sql</code>.</li>
                    <li>If you changed the MySQL password, update it in <code>db.php</code>.</li>
                </ol>
                <a class="button" href="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'index.php', ENT_QUOTES, 'UTF-8') ?>">Retry</a>
                <p class="muted offline-note">This page checks again automatically every few seconds.</p>
            </div>
        </main>
    </div>
</div>
<script>
    window.setInterval(async () => {
        try {
            const response = await fetch('index.php?action=ping', { headers: { Accept: 'application/json' } });
            if ((await response.json()).db === true) {
                window.location.reload();
            }
        } catch {
            // Still offline.
        }
    }, 5000);
</script>
</body>
</html>
