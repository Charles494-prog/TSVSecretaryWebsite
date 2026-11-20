<?php
declare(strict_types=1);

// Sign-in for the website: email + password, then a 6-digit code sent by email (two-step sign-in).
// Accounts are made by the superadmin, who can also revoke access or delete accounts. Passwords are only
// ever stored as hashes; nobody, including the superadmin, can see them.

const SESSION_IDLE_SECONDS = 2 * 60 * 60;      // Signed out after 2 hours without activity...
const SESSION_MAX_SECONDS = 12 * 60 * 60;      // ...and after 12 hours in any case.
const LOGIN_CODE_SECONDS = 10 * 60;            // A sign-in code works for 10 minutes,
const LOGIN_CODE_TRIES = 5;                    // and for at most 5 guesses.
const LOGIN_FAILURES_ALLOWED = 5;              // 5 wrong passwords lock that email for 15 minutes.
const LOGIN_LOCK_SECONDS = 15 * 60;
const INVITE_SECONDS = 3 * 24 * 60 * 60;       // A "set your password" link works for 3 days.

// ---------------------------------------------------------------- storage

function authEnsureTables(PDO $pdo, bool $sqlite): void
{
    $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    $text = $sqlite ? 'TEXT' : 'VARCHAR(255)';
    $long = $sqlite ? 'TEXT' : 'MEDIUMTEXT';
    $statements = [
        "CREATE TABLE IF NOT EXISTS app_users (
            id {$id},
            email {$text} NOT NULL UNIQUE,
            name {$text} NOT NULL DEFAULT '',
            role {$text} NOT NULL DEFAULT 'member',
            status {$text} NOT NULL DEFAULT 'active',
            password_hash {$text} DEFAULT NULL,
            invite_hash {$text} DEFAULT NULL,
            invite_expires INTEGER NOT NULL DEFAULT 0,
            failed_logins INTEGER NOT NULL DEFAULT 0,
            locked_until INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL DEFAULT 0,
            last_login_at INTEGER NOT NULL DEFAULT 0
        )",
        "CREATE TABLE IF NOT EXISTS app_login_codes (
            user_id INTEGER NOT NULL PRIMARY KEY,
            code_hash {$text} NOT NULL,
            expires_at INTEGER NOT NULL,
            tries INTEGER NOT NULL DEFAULT 0
        )",
        "CREATE TABLE IF NOT EXISTS app_sessions (
            id " . ($sqlite ? 'TEXT' : 'VARCHAR(128)') . " NOT NULL PRIMARY KEY,
            user_id INTEGER NOT NULL DEFAULT 0,
            data {$long} NOT NULL,
            expires_at INTEGER NOT NULL
        )",
    ];
    foreach ($statements as $sql) {
        $pdo->exec($sql);
    }
}

/** Sessions live in the database: on Vercel each request may run on a different machine. */
final class DatabaseSessionHandler implements SessionHandlerInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    private array $loaded = [];

    public function read(string $id): string|false
    {
        $statement = $this->pdo->prepare('SELECT data FROM app_sessions WHERE id = :id AND expires_at > :now');
        $statement->execute(['id' => hash('sha256', $id), 'now' => time()]);
        $data = $statement->fetchColumn();
        $this->loaded[$id] = $data === false ? null : (string) $data;
        return $this->loaded[$id] ?? '';
    }

    // One atomic upsert, so a page's parallel requests never see the session missing for a moment
    // (a delete-then-insert let one request read "signed out" and save that over the real session).
    public function write(string $id, string $data): bool
    {
        $before = $this->loaded[$id] ?? null;
        if ($before === null && $data === '') {
            return true; // Nothing to keep for a visitor with an empty session.
        }
        $this->pdo->prepare(
            'INSERT INTO app_sessions (id, user_id, data, expires_at) VALUES (:id, :user_id, :data, :expires)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), data = VALUES(data), expires_at = VALUES(expires_at)'
        )->execute([
            'id' => hash('sha256', $id),
            'user_id' => (int) ($_SESSION['user_id'] ?? 0),
            'data' => $data,
            'expires' => time() + SESSION_IDLE_SECONDS,
        ]);
        return true;
    }

    public function destroy(string $id): bool
    {
        $this->pdo->prepare('DELETE FROM app_sessions WHERE id = :id')->execute(['id' => hash('sha256', $id)]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $statement = $this->pdo->prepare('DELETE FROM app_sessions WHERE expires_at < :now');
        $statement->execute(['now' => time()]);
        return $statement->rowCount();
    }
}

function isHttps(): bool
{
    return ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function startSecureSession(PDO $pdo): void
{
    session_set_save_handler(new DatabaseSessionHandler($pdo), true);
    session_name(isHttps() ? '__Host-tsv' : 'tsv_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => isHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    session_start();
}

function sendSecurityHeaders(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (isHttps()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/** The first visit creates the superadmin from SUPERADMIN_EMAIL / SUPERADMIN_INITIAL_PASSWORD. */
function ensureSuperadmin(PDO $pdo): void
{
    $email = strtolower(trim(env('SUPERADMIN_EMAIL')));
    $password = env('SUPERADMIN_INITIAL_PASSWORD');
    if ($email === '' || $password === '') {
        return;
    }
    $statement = $pdo->prepare('SELECT COUNT(*) FROM app_users WHERE role = :role');
    $statement->execute(['role' => 'superadmin']);
    if ((int) $statement->fetchColumn() > 0) {
        return;
    }
    $pdo->prepare('INSERT INTO app_users (email, name, role, status, password_hash, created_at) VALUES (:email, :name, :role, :status, :hash, :now)')->execute([
        'email' => $email,
        'name' => 'Superadmin',
        'role' => 'superadmin',
        'status' => 'active',
        'hash' => password_hash($password, PASSWORD_DEFAULT),
        'now' => time(),
    ]);
}

function findUserByEmail(PDO $pdo, string $email): ?array
{
    $statement = $pdo->prepare('SELECT * FROM app_users WHERE email = :email');
    $statement->execute(['email' => strtolower(trim($email))]);
    $user = $statement->fetch();
    return $user ?: null;
}

function findUser(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare('SELECT * FROM app_users WHERE id = :id');
    $statement->execute(['id' => $id]);
    $user = $statement->fetch();
    return $user ?: null;
}

// ---------------------------------------------------------------- email (Gmail SMTP)

function smtpSend(string $to, string $subject, string $text, ?string $html = null): void
{
    $user = env('SMTP_USER');
    $password = str_replace(' ', '', env('SMTP_APP_PASSWORD'));
    if ($user === '' || $password === '') {
        throw new RuntimeException('Email is not set up (SMTP_USER / SMTP_APP_PASSWORD).');
    }
    $socket = stream_socket_client('ssl://smtp.gmail.com:465', $errno, $errstr, 15);
    if (!$socket) {
        throw new RuntimeException("Could not reach the mail server: {$errstr}");
    }
    stream_set_timeout($socket, 15);
    $expect = static function (string $code) use ($socket): void {
        $reply = '';
        while (($line = fgets($socket, 1024)) !== false) {
            $reply .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        if (!str_starts_with($reply, $code)) {
            throw new RuntimeException('Mail server said: ' . trim($reply));
        }
    };
    $say = static function (string $line, string $code) use ($socket, $expect): void {
        fwrite($socket, $line . "\r\n");
        $expect($code);
    };
    $expect('220');
    $say('EHLO tsv-secretary', '250');
    $say('AUTH LOGIN', '334');
    $say(base64_encode($user), '334');
    $say(base64_encode($password), '235');
    $say("MAIL FROM:<{$user}>", '250');
    $say("RCPT TO:<{$to}>", '250');
    $say('DATA', '354');
    $headers = [
        'From: LTSVSS <' . $user . '>',
        'To: <' . $to . '>',
        'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@tsv-secretary>',
        'MIME-Version: 1.0',
    ];
    if ($html === null) {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        $body = chunk_split(base64_encode($text));
    } else {
        // Plain text for mail apps that want it, the designed HTML for everyone else.
        $boundary = 'tsv-' . bin2hex(random_bytes(12));
        $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";
        $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text))
            . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html))
            . "--{$boundary}--";
    }
    $say(implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.", '250');
    fwrite($socket, "QUIT\r\n");
    fclose($socket);
}

/**
 * The branded HTML email: logo header, a heading, a short message, then either a big code or a button.
 * Built with tables and inline styles because that is what Gmail, Outlook and phone mail apps render reliably.
 */
function emailHtml(string $heading, string $intro, ?string $code, ?array $button, string $note): string
{
    $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $logo = $e(siteUrl() . '/logo.png');
    $font = "font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
    $highlight = '';
    if ($code !== null) {
        $spaced = $e(substr($code, 0, 3) . ' ' . substr($code, 3));
        $highlight = "<tr><td align=\"center\" style=\"padding:8px 40px 28px;\">
            <div style=\"display:inline-block;padding:18px 28px;background:#FFF8E6;border:1px solid #FBD77A;border-radius:12px;{$font}font-size:34px;font-weight:700;letter-spacing:8px;color:#1F2937;\">{$spaced}</div>
        </td></tr>";
    }
    if ($button !== null) {
        [$label, $url] = $button;
        $highlight = "<tr><td align=\"center\" style=\"padding:8px 40px 20px;\">
            <a href=\"{$e($url)}\" style=\"display:inline-block;padding:14px 28px;background:#FBBA24;border-radius:10px;{$font}font-size:15px;font-weight:600;color:#1F2937;text-decoration:none;\">{$e($label)}</a>
        </td></tr>
        <tr><td style=\"padding:0 40px 24px;{$font}font-size:12px;line-height:18px;color:#6B7280;word-break:break-all;\">If the button doesn't work, copy this link into your browser:<br><a href=\"{$e($url)}\" style=\"color:#B7791F;\">{$e($url)}</a></td></tr>";
    }
    $year = date('Y');
    return <<<HTML
<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{$e($heading)}</title></head>
<body style="margin:0;padding:0;background:#F3F4F6;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F3F4F6;padding:32px 12px;">
<tr><td align="center">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#FFFFFF;border-radius:16px;overflow:hidden;border:1px solid #E5E7EB;">
    <tr><td style="background:#111111;padding:20px 40px;">
      <table role="presentation" cellpadding="0" cellspacing="0"><tr>
        <td style="vertical-align:middle;"><img src="{$logo}" width="32" height="32" alt="" style="display:block;border:0;"></td>
        <td style="vertical-align:middle;padding-left:12px;{$font}font-size:16px;font-weight:700;color:#FFFFFF;letter-spacing:0.3px;">LTSVSS<span style="color:#9CA3AF;font-weight:400;"> &nbsp;Locale TSV Secretary System</span></td>
      </tr></table>
    </td></tr>
    <tr><td style="height:4px;background:#FBBA24;font-size:0;line-height:0;">&nbsp;</td></tr>
    <tr><td style="padding:32px 40px 8px;{$font}font-size:22px;font-weight:700;color:#111827;">{$e($heading)}</td></tr>
    <tr><td style="padding:0 40px 20px;{$font}font-size:15px;line-height:23px;color:#374151;">{$e($intro)}</td></tr>
    {$highlight}
    <tr><td style="padding:0 40px 32px;{$font}font-size:13px;line-height:20px;color:#6B7280;border-top:1px solid #F3F4F6;padding-top:20px;">{$e($note)}</td></tr>
  </table>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;">
    <tr><td align="center" style="padding:18px 20px;{$font}font-size:12px;line-height:18px;color:#9CA3AF;">This is an automatic message from the Locale TSV Secretary System. Please don't reply.<br>&copy; {$year} LTSVSS</td></tr>
  </table>
</td></tr>
</table>
</body></html>
HTML;
}

function siteUrl(): string
{
    return (isHttps() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

// ---------------------------------------------------------------- sign-in flow

function authCsrfOk(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    return is_string($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function signOut(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/** Checks the session; returns the signed-in user or null. Revoked or deleted accounts are signed out at once. */
function currentUser(PDO $pdo): ?array
{
    $id = (int) ($_SESSION['user_id'] ?? 0);
    if ($id === 0) {
        return null;
    }
    $now = time();
    if ($now - (int) ($_SESSION['signed_in_at'] ?? 0) > SESSION_MAX_SECONDS || $now - (int) ($_SESSION['last_seen'] ?? 0) > SESSION_IDLE_SECONDS) {
        signOut();
        return null;
    }
    $user = findUser($pdo, $id);
    if (!$user || $user['status'] !== 'active') {
        signOut();
        return null;
    }
    $_SESSION['last_seen'] = $now;
    return $user;
}

function issueLoginCode(PDO $pdo, array $user): void
{
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $pdo->prepare('DELETE FROM app_login_codes WHERE user_id = :id')->execute(['id' => $user['id']]);
    $pdo->prepare('INSERT INTO app_login_codes (user_id, code_hash, expires_at, tries) VALUES (:id, :hash, :expires, 0)')->execute([
        'id' => $user['id'],
        'hash' => password_hash($code, PASSWORD_DEFAULT),
        'expires' => time() + LOGIN_CODE_SECONDS,
    ]);
    $note = 'If you did not try to sign in, someone may know your password. Change it, or tell your superadmin right away.';
    smtpSend(
        $user['email'],
        "{$code} is your LTSVSS sign-in code",
        "Your sign-in code is: {$code}\n\nIt works once and expires in 10 minutes.\n\n{$note}",
        emailHtml('Your sign-in code', 'Enter this code on the sign-in page to finish signing in. It works once and expires in 10 minutes.', $code, null, $note)
    );
}

/** Handles the sign-in, code, set-password and sign-out pages. Returns only once the visitor is signed in. */
function requireSignIn(PDO $pdo): array
{
    $action = $_GET['action'] ?? '';
    if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST' && authCsrfOk()) {
        signOut();
        header('Location: index.php');
        exit;
    }
    if ($action === 'set_password') {
        setPasswordPage($pdo);
    }
    $user = currentUser($pdo);
    if ($user) {
        return $user;
    }
    // Not signed in: background requests get a plain "401", pages get the sign-in form.
    if (in_array($_GET['action'] ?? '', ['ping', 'memory_photo', 'memory_video', 'suguan_data', 'memories_data'], true)
        || str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        session_abort();
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Signed out. Reload the page to sign in again.']);
        exit;
    }

    $error = '';
    $notice = '';
    $step = isset($_SESSION['pending_user_id']) ? 'code' : 'password';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!authCsrfOk()) {
            $error = 'Your session expired. Please try again.';
        } elseif (($_POST['auth_step'] ?? '') === 'password') {
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            $candidate = findUserByEmail($pdo, $email);
            if ($candidate && (int) $candidate['locked_until'] > time()) {
                $error = 'Too many wrong passwords. Try again in 15 minutes.';
            } elseif ($candidate && $candidate['status'] === 'active' && $candidate['password_hash'] && password_verify($password, $candidate['password_hash'])) {
                $pdo->prepare('UPDATE app_users SET failed_logins = 0, locked_until = 0 WHERE id = :id')->execute(['id' => $candidate['id']]);
                try {
                    issueLoginCode($pdo, $candidate);
                    session_regenerate_id(true);
                    $_SESSION['pending_user_id'] = (int) $candidate['id'];
                    $_SESSION['pending_email'] = $candidate['email'];
                    $step = 'code';
                } catch (Throwable $e) {
                    error_log('Sending the sign-in code failed: ' . $e->getMessage());
                    $error = 'The sign-in code could not be emailed. Please try again in a minute.';
                }
            } else {
                // Same message whether the email exists or not, so accounts can't be guessed.
                if ($candidate) {
                    $failures = (int) $candidate['failed_logins'] + 1;
                    $pdo->prepare('UPDATE app_users SET failed_logins = :f, locked_until = :until WHERE id = :id')->execute([
                        'f' => $failures >= LOGIN_FAILURES_ALLOWED ? 0 : $failures,
                        'until' => $failures >= LOGIN_FAILURES_ALLOWED ? time() + LOGIN_LOCK_SECONDS : 0,
                        'id' => $candidate['id'],
                    ]);
                }
                usleep(random_int(200000, 400000));
                $error = 'Wrong email or password, or this account has no access.';
            }
        } elseif (($_POST['auth_step'] ?? '') === 'code' && isset($_SESSION['pending_user_id'])) {
            $pendingId = (int) $_SESSION['pending_user_id'];
            $statement = $pdo->prepare('SELECT * FROM app_login_codes WHERE user_id = :id');
            $statement->execute(['id' => $pendingId]);
            $row = $statement->fetch();
            $code = preg_replace('/\D/', '', (string) ($_POST['code'] ?? ''));
            if (!$row || (int) $row['expires_at'] < time() || (int) $row['tries'] >= LOGIN_CODE_TRIES) {
                $pdo->prepare('DELETE FROM app_login_codes WHERE user_id = :id')->execute(['id' => $pendingId]);
                unset($_SESSION['pending_user_id'], $_SESSION['pending_email']);
                $step = 'password';
                $error = 'That code expired or was tried too many times. Sign in again to get a new one.';
            } elseif (!password_verify($code, $row['code_hash'])) {
                $pdo->prepare('UPDATE app_login_codes SET tries = tries + 1 WHERE user_id = :id')->execute(['id' => $pendingId]);
                $error = 'That code is not right. Check the latest email and try again.';
            } else {
                $pdo->prepare('DELETE FROM app_login_codes WHERE user_id = :id')->execute(['id' => $pendingId]);
                $user = findUser($pdo, $pendingId);
                if ($user && $user['status'] === 'active') {
                    session_regenerate_id(true);
                    unset($_SESSION['pending_user_id'], $_SESSION['pending_email']);
                    $_SESSION['user_id'] = (int) $user['id'];
                    $_SESSION['signed_in_at'] = time();
                    $_SESSION['last_seen'] = time();
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    $pdo->prepare('UPDATE app_users SET last_login_at = :now WHERE id = :id')->execute(['now' => time(), 'id' => $user['id']]);
                    header('Location: index.php');
                    exit;
                }
                $step = 'password';
                $error = 'This account has no access.';
            }
        } elseif (($_POST['auth_step'] ?? '') === 'restart') {
            unset($_SESSION['pending_user_id'], $_SESSION['pending_email']);
            $step = 'password';
        }
    }
    if ($step === 'code' && !$error) {
        $notice = 'We emailed a 6-digit code to ' . ($_SESSION['pending_email'] ?? 'your email') . '. It expires in 10 minutes.';
    }
    authPage($step === 'code' ? 'Enter your code' : 'Sign in', static function () use ($step, $error, $notice): void {
        $csrf = h($_SESSION['csrf_token']);
        if ($error) {
            echo '<div class="alert alert-error">' . h($error) . '</div>';
        }
        if ($notice) {
            echo '<p class="muted">' . h($notice) . '</p>';
        }
        if ($step === 'code') {
            echo <<<HTML
            <form method="post" action="index.php" class="auth-form">
                <input type="hidden" name="csrf_token" value="{$csrf}">
                <input type="hidden" name="auth_step" value="code">
                <label class="field"><span>Sign-in code</span>
                    <input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus>
                </label>
                <button class="button" type="submit">Verify</button>
            </form>
            <form method="post" action="index.php" class="auth-form">
                <input type="hidden" name="csrf_token" value="{$csrf}">
                <input type="hidden" name="auth_step" value="restart">
                <button class="button button-light" type="submit">Use a different account</button>
            </form>
            HTML;
        } else {
            echo <<<HTML
            <form method="post" action="index.php" class="auth-form">
                <input type="hidden" name="csrf_token" value="{$csrf}">
                <input type="hidden" name="auth_step" value="password">
                <label class="field"><span>Email</span><input name="email" type="email" autocomplete="username" required autofocus></label>
                <label class="field"><span>Password</span><input name="password" type="password" autocomplete="current-password" required></label>
                <button class="button" type="submit">Continue</button>
            </form>
            HTML;
        }
    });
}

/** The page a new member (or someone resetting) opens from their email to choose a password. */
function setPasswordPage(PDO $pdo): never
{
    $token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
    $user = null;
    if (preg_match('/^[a-f0-9]{64}$/', $token)) {
        $statement = $pdo->prepare('SELECT * FROM app_users WHERE invite_hash = :hash AND invite_expires > :now AND status = :status');
        $statement->execute(['hash' => hash('sha256', $token), 'now' => time(), 'status' => 'active']);
        $user = $statement->fetch() ?: null;
    }
    $error = '';
    if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $password = (string) ($_POST['password'] ?? '');
        if (!authCsrfOk()) {
            $error = 'Your session expired. Please try again.';
        } elseif (strlen($password) < 10) {
            $error = 'Use at least 10 characters.';
        } elseif ($password !== (string) ($_POST['password_confirm'] ?? '')) {
            $error = 'The two passwords do not match.';
        } else {
            $pdo->prepare('UPDATE app_users SET password_hash = :hash, invite_hash = NULL, invite_expires = 0, failed_logins = 0, locked_until = 0 WHERE id = :id')->execute([
                'hash' => password_hash($password, PASSWORD_DEFAULT),
                'id' => $user['id'],
            ]);
            // Any session that was open for this account is ended.
            $pdo->prepare('DELETE FROM app_sessions WHERE user_id = :id')->execute(['id' => $user['id']]);
            authPage('Password saved', static function (): void {
                echo '<p>Your password is set. You can sign in now.</p><a class="button" href="index.php">Go to sign in</a>';
            });
        }
    }
    authPage('Choose your password', static function () use ($user, $token, $error): void {
        if (!$user) {
            echo '<div class="alert alert-error">This link is invalid or has expired. Ask the superadmin to send a new one.</div>';
            return;
        }
        if ($error) {
            echo '<div class="alert alert-error">' . h($error) . '</div>';
        }
        $csrf = h($_SESSION['csrf_token']);
        $email = h($user['email']);
        $tokenH = h($token);
        echo <<<HTML
        <p class="muted">For {$email}</p>
        <form method="post" action="index.php?action=set_password" class="auth-form">
            <input type="hidden" name="csrf_token" value="{$csrf}">
            <input type="hidden" name="token" value="{$tokenH}">
            <input type="email" name="username" value="{$email}" autocomplete="username" hidden>
            <label class="field"><span>New password (at least 10 characters)</span><input name="password" type="password" minlength="10" autocomplete="new-password" required autofocus></label>
            <label class="field"><span>Type it again</span><input name="password_confirm" type="password" minlength="10" autocomplete="new-password" required></label>
            <button class="button" type="submit">Save password</button>
        </form>
        HTML;
    });
}

function authPage(string $title, callable $body): never
{
    header('Cache-Control: no-store');
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> · TSV Secretary</title>
    <script>
        try { document.documentElement.dataset.theme = localStorage.getItem('theme') === 'light' ? 'light' : 'dark'; } catch { document.documentElement.dataset.theme = 'dark'; }
    </script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@400;500&display=swap">
    <link rel="stylesheet" href="style.css?v=30">
    <style>
        .auth-wrap { min-height: 100vh; display: grid; place-items: center; padding: 24px; }
        .auth-card { width: min(420px, 100%); padding: 28px; display: grid; gap: 16px; }
        .auth-card h1 { margin: 0; font-size: 1.4rem; }
        .auth-form { display: grid; gap: 14px; }
        .auth-brand { display: flex; align-items: center; gap: 10px; font-weight: 600; }
    </style>
</head>
<body>
<div class="auth-wrap">
    <div class="panel auth-card">
        <div class="auth-brand"><span class="team-avatar" aria-hidden="true"></span><span>LTSVSS</span></div>
        <h1><?= h($title) ?></h1>
        <?php $body(); ?>
    </div>
</div>
</body>
</html>
    <?php
    exit;
}

// ---------------------------------------------------------------- superadmin: accounts

function sendInvite(PDO $pdo, array $user, bool $reset): void
{
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('UPDATE app_users SET invite_hash = :hash, invite_expires = :expires WHERE id = :id')->execute([
        'hash' => hash('sha256', $token),
        'expires' => time() + INVITE_SECONDS,
        'id' => $user['id'],
    ]);
    $link = siteUrl() . '/index.php?action=set_password&token=' . $token;
    $intro = $reset
        ? 'A password reset was requested for your LTSVSS account. Choose a new password with the button below. The link works for 3 days.'
        : 'Hi ' . $user['name'] . ', an account was made for you on the Locale TSV Secretary System. Choose your password with the button below to get started. The link works for 3 days.';
    $note = 'After that, sign in at ' . siteUrl() . ' with your email and password. Each time, we will also email you a 6-digit code. If you were not expecting this email, you can ignore it.';
    smtpSend(
        $user['email'],
        $reset ? 'Reset your LTSVSS password' : 'Your LTSVSS account is ready',
        $intro . "\n\nChoose your password here:\n{$link}\n\n{$note}",
        emailHtml($reset ? 'Reset your password' : 'Welcome to LTSVSS', $intro, null, [$reset ? 'Choose a new password' : 'Set my password', $link], $note)
    );
}

/** Account actions (POST). Returns a message for the page. */
function handleAccountAction(PDO $pdo, array $me): array
{
    if (!authCsrfOk()) {
        return ['error', 'Your session expired. Please try again.'];
    }
    $op = (string) ($_POST['account_op'] ?? '');
    if ($op === 'create') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 200) {
            return ['error', 'Enter a valid email address.'];
        }
        if ($name === '' || mb_strlen($name) > 150) {
            return ['error', 'Enter the member\'s name (150 characters or fewer).'];
        }
        if (findUserByEmail($pdo, $email)) {
            return ['error', 'There is already an account with that email.'];
        }
        $pdo->prepare('INSERT INTO app_users (email, name, role, status, created_at) VALUES (:email, :name, :role, :status, :now)')->execute([
            'email' => $email, 'name' => $name, 'role' => 'member', 'status' => 'active', 'now' => time(),
        ]);
        try {
            sendInvite($pdo, findUserByEmail($pdo, $email), false);
            return ['success', "Account made. {$email} was emailed a link to choose a password."];
        } catch (Throwable $e) {
            error_log('Invite email failed: ' . $e->getMessage());
            return ['error', 'The account was made, but the email could not be sent. Use "Resend link" to try again.'];
        }
    }
    $target = findUser($pdo, (int) ($_POST['user_id'] ?? 0));
    if (!$target) {
        return ['error', 'That account no longer exists.'];
    }
    if ((int) $target['id'] === (int) $me['id'] || $target['role'] === 'superadmin') {
        return ['error', 'The superadmin account cannot be changed here.'];
    }
    $endSessions = static fn() => $pdo->prepare('DELETE FROM app_sessions WHERE user_id = :id')->execute(['id' => $target['id']]);
    switch ($op) {
        case 'revoke':
            $pdo->prepare('UPDATE app_users SET status = :s WHERE id = :id')->execute(['s' => 'revoked', 'id' => $target['id']]);
            $endSessions();
            return ['success', "{$target['email']} no longer has access and was signed out."];
        case 'restore':
            $pdo->prepare('UPDATE app_users SET status = :s WHERE id = :id')->execute(['s' => 'active', 'id' => $target['id']]);
            return ['success', "{$target['email']} has access again."];
        case 'resend':
            try {
                sendInvite($pdo, $target, (bool) $target['password_hash']);
                return ['success', "A new link was emailed to {$target['email']}."];
            } catch (Throwable $e) {
                error_log('Invite email failed: ' . $e->getMessage());
                return ['error', 'The email could not be sent. Please try again.'];
            }
        case 'delete':
            $endSessions();
            $pdo->prepare('DELETE FROM app_login_codes WHERE user_id = :id')->execute(['id' => $target['id']]);
            $pdo->prepare('DELETE FROM app_users WHERE id = :id')->execute(['id' => $target['id']]);
            return ['success', "The account for {$target['email']} was deleted."];
    }
    return ['error', 'Unknown action.'];
}

function accountsPage(PDO $pdo, array $me): never
{
    $message = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $message = handleAccountAction($pdo, $me);
    }
    $users = $pdo->query('SELECT id, email, name, role, status, password_hash IS NOT NULL AS has_password, created_at, last_login_at FROM app_users ORDER BY role DESC, name')->fetchAll();
    $sessionCounts = [];
    $statement = $pdo->prepare('SELECT user_id, COUNT(*) AS n FROM app_sessions WHERE expires_at > :now AND user_id > 0 GROUP BY user_id');
    $statement->execute(['now' => time()]);
    foreach ($statement->fetchAll() as $row) {
        $sessionCounts[(int) $row['user_id']] = (int) $row['n'];
    }
    authPageWide('Accounts', static function () use ($users, $sessionCounts, $message, $me): void {
        $csrf = h($_SESSION['csrf_token']);
        if ($message) {
            echo '<div class="alert ' . ($message[0] === 'error' ? 'alert-error' : 'alert-success') . '">' . h($message[1]) . '</div>';
        }
        echo <<<HTML
        <form method="post" action="index.php?action=accounts" class="account-create">
            <input type="hidden" name="csrf_token" value="{$csrf}">
            <input type="hidden" name="account_op" value="create">
            <label class="field"><span>Name</span><input name="name" maxlength="150" required></label>
            <label class="field"><span>Email</span><input name="email" type="email" maxlength="200" required></label>
            <button class="button" type="submit">Add account</button>
        </form>
        <p class="muted">The new member gets an email with a link to choose their password. Passwords are never shown to anyone.</p>
        <div class="table-wrap"><table class="accounts">
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last sign-in</th><th>Signed in now</th><th></th></tr></thead><tbody>
        HTML;
        foreach ($users as $u) {
            $isSuper = $u['role'] === 'superadmin';
            $status = $u['status'] === 'revoked' ? 'Revoked' : ((int) $u['has_password'] ? 'Active' : 'Waiting for password');
            $last = (int) $u['last_login_at'] ? date('M j, Y g:i A', (int) $u['last_login_at']) : '—';
            $live = $sessionCounts[(int) $u['id']] ?? 0;
            echo '<tr><td>' . h($u['name']) . '</td><td>' . h($u['email']) . '</td><td>' . ($isSuper ? 'Superadmin' : 'Member') . '</td><td>' . h($status) . '</td><td>' . h($last) . '</td><td>' . ($live ? 'Yes' : 'No') . '</td><td class="account-actions">';
            if (!$isSuper) {
                $id = (int) $u['id'];
                $button = static function (string $op, string $label, string $confirm = '') use ($id, $csrf): string {
                    $onsubmit = $confirm ? ' onsubmit="return confirm(\'' . h($confirm) . '\')"' : '';
                    return "<form method=\"post\" action=\"index.php?action=accounts\"{$onsubmit}><input type=\"hidden\" name=\"csrf_token\" value=\"{$csrf}\"><input type=\"hidden\" name=\"account_op\" value=\"{$op}\"><input type=\"hidden\" name=\"user_id\" value=\"{$id}\"><button class=\"button button-light\" type=\"submit\">{$label}</button></form>";
                };
                echo $u['status'] === 'revoked' ? $button('restore', 'Restore access') : $button('revoke', 'Revoke access', 'Revoke access for ' . $u['email'] . '? They will be signed out now.');
                echo $button('resend', (int) $u['has_password'] ? 'Send reset link' : 'Resend link');
                echo $button('delete', 'Delete', 'Delete the account for ' . $u['email'] . '? This cannot be undone.');
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    });
}

function authPageWide(string $title, callable $body): never
{
    header('Cache-Control: no-store');
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> · TSV Secretary</title>
    <script>
        try { document.documentElement.dataset.theme = localStorage.getItem('theme') === 'light' ? 'light' : 'dark'; } catch { document.documentElement.dataset.theme = 'dark'; }
    </script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@400;500&display=swap">
    <link rel="stylesheet" href="style.css?v=30">
    <style>
        .accounts-wrap { max-width: 1100px; margin: 0 auto; padding: 28px 20px; display: grid; gap: 18px; }
        .accounts-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        .account-create { display: grid; grid-template-columns: 1fr 1fr auto; gap: 12px; align-items: end; }
        .accounts { width: 100%; border-collapse: collapse; }
        .accounts th, .accounts td { text-align: left; padding: 10px 8px; border-bottom: 1px solid var(--border, #333); vertical-align: middle; }
        .account-actions { display: flex; gap: 6px; flex-wrap: wrap; }
        .account-actions form { margin: 0; }
        .table-wrap { overflow-x: auto; }
        @media (max-width: 700px) { .account-create { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="accounts-wrap">
    <div class="accounts-head"><h1><?= h($title) ?></h1><a class="button button-light" href="index.php">Back to TSV Secretary</a></div>
    <div class="panel" style="padding: 20px; display: grid; gap: 14px;"><?php $body(); ?></div>
</div>
</body>
</html>
    <?php
    exit;
}
