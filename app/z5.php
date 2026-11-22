<?php

declare(strict_types=1);
require_once __DIR__ . '/z2.php';

function z5_require_https(): void
{
    if (!is_local() && !is_https()) {
        http_response_code(403);
        z5_page_start('wymagane https');
        z5_page_end();
        exit;
    }
}

function z5_require_login(): void
{
    z5_require_https();
    start_app_session();
    if (($_SESSION['loggedin'] ?? false) !== true || !isset($_SESSION['user_id'], $_SESSION['username'])) {
        redirect('/z5/logowanie.php');
    }
}

function require_z5_login(): void
{
    z5_require_login();
}

function z5_clip(string $value, int $length): string
{
    $value = preg_match('//u', $value) === 1 ? $value : '';
    $value = substr($value, 0, $length);
    while ($value !== '' && preg_match('//u', $value) !== 1) {
        $value = substr($value, 0, -1);
    }
    return $value;
}

function z5_client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    if (is_local() && config('APP_TRUST_PROXY') === '1') {
        $forwarded = (string) ($_SERVER['HTTP_X_REAL_IP'] ?? '');
        if (filter_var($forwarded, FILTER_VALIDATE_IP) !== false) {
            $ip = $forwarded;
        }
    }
    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
}

function z5_record_visit(): array
{
    static $visit = null;
    if (is_array($visit)) {
        return $visit;
    }
    start_app_session();
    $stmt = db()->prepare("INSERT INTO goscieportalu (ipaddress, user_agent, browser, application, event_type) VALUES (?, ?, ?, 'z5', 'visit')");
    $stmt->execute([z5_client_ip(), z5_clip((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 512), 'nieustalona']);
    $id = db()->lastInsertId();
    $nonce = bin2hex(random_bytes(32));
    $pending = $_SESSION['z5_visits'] ?? [];
    $pending = is_array($pending) ? $pending : [];
    foreach ($pending as $key => $entry) {
        if (!is_array($entry) || (int) ($entry['expires'] ?? 0) < time()) {
            unset($pending[$key]);
        }
    }
    $pending[$id] = ['nonce' => $nonce, 'expires' => time() + 1800];
    $_SESSION['z5_visits'] = array_slice($pending, -20, null, true);
    $visit = ['id' => $id, 'nonce' => $nonce];
    return $visit;
}

function z5_page_start(string $heading, bool $authenticated = false): void
{
    $visit = z5_record_visit();
    $csrf = csrf_token();
    echo '<!doctype html><html lang="pl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="z5-visit-id" content="' . e($visit['id']) . '"><meta name="z5-visit-nonce" content="' . e($visit['nonce']) . '"><meta name="csrf-token" content="' . e($csrf) . '"><title>dalke</title><link rel="stylesheet" href="/z2/vendor/bootstrap.min.css"><link rel="stylesheet" href="/z5/twoj_css.css?v=bootstrap-20261109"><script src="/z3/vendor/ua-parser.min.js" defer></script>';
    if ($authenticated) {
        echo '<script src="/z2/vendor/bootstrap.bundle.min.js" defer></script>';
    }
    echo '<script src="/z5/twoj_js.js" defer></script></head><body>';
    if ($authenticated) {
        echo '<div id="myHeader"></div><main class="container py-4 z5-main" data-authenticated="true"><h1 class="h3 mb-4">' . e($heading) . '</h1>';
    } else {
        echo '<main class="container py-5 z5-main z5-auth"><h1 class="h3 mb-4">' . e($heading) . '</h1>';
    }
}

function z5_page_end(): void
{
    require rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/') . '/z5/footer.php';
    echo '</main></body></html>';
}

function z5_check_csrf(): void
{
    start_app_session();
    $token = $_POST['csrf'] ?? null;
    $stored = $_SESSION['csrf'] ?? null;
    if (!is_string($token) || !is_string($stored) || !hash_equals($stored, $token)) {
        http_response_code(403);
        z5_page_start('nieprawidłowy formularz');
        echo '<a class="btn btn-outline-secondary" href="/z5/">powrót</a>';
        z5_page_end();
        exit;
    }
}

function z5_throttle_guard(string $scope): void
{
    $stmt = db()->prepare('SELECT blocked_until FROM auth_attempts WHERE bucket = ?');
    $stmt->execute([throttle_bucket($scope)]);
    $blockedUntil = (int) ($stmt->fetchColumn() ?: 0);
    if ($blockedUntil > time()) {
        http_response_code(429);
        header('Retry-After: ' . (string) ($blockedUntil - time()));
        z5_page_start('spróbuj za minutę');
        echo '<a class="btn btn-outline-secondary" href="/z5/">powrót</a>';
        z5_page_end();
        exit;
    }
}

function z5_login_form(string $error = ''): void
{
    z5_require_https();
    start_app_session();
    z5_page_start('logowanie');
    if ($error !== '') {
        echo '<p class="alert alert-danger" role="alert">' . e($error) . '</p>';
    }
    if (($_GET['registered'] ?? null) === '1') {
        echo '<p class="alert alert-success" role="status">konto zostało utworzone.</p>';
    }
    echo '<form method="post" action="/z5/weryfikuj.php" class="col-md-6 col-lg-4 mb-3">' . csrf_field() . '<div class="mb-3"><label class="form-label" for="user">login:</label><input class="form-control" id="user" name="user" maxlength="128" autocomplete="username" required></div><div class="mb-3"><label class="form-label" for="pass">hasło:</label><input class="form-control" id="pass" type="password" name="pass" maxlength="72" autocomplete="current-password" required></div><button class="btn btn-primary" type="submit">zaloguj</button></form><p><a class="btn btn-outline-primary" href="/z5/rejestruj.php">rejestracja</a></p><a class="btn btn-outline-secondary" href="/">powrót</a>';
    z5_page_end();
}

function z5_registration_form(string $error = '', string $username = ''): void
{
    z5_require_https();
    start_app_session();
    z5_page_start('rejestracja');
    if ($error !== '') {
        echo '<p class="alert alert-danger" role="alert">' . e($error) . '</p>';
    }
    echo '<form method="post" action="/z5/add.php" enctype="multipart/form-data" class="col-md-6 col-lg-4 mb-3">' . csrf_field() . '<div class="mb-3"><label class="form-label" for="user">login:</label><input class="form-control" id="user" name="user" minlength="3" maxlength="128" pattern="[A-Za-z0-9_.-]{3,128}" autocomplete="username" value="' . e($username) . '" required></div><div class="mb-3"><label class="form-label" for="pass">hasło:</label><input class="form-control" id="pass" name="pass" type="password" maxlength="72" autocomplete="new-password" required></div><div class="mb-3"><label class="form-label" for="pass-repeat">powtórz hasło:</label><input class="form-control" id="pass-repeat" name="pass_repeat" type="password" maxlength="72" autocomplete="new-password" required></div><div class="mb-3"><label class="form-label" for="avatar">avatar:</label><input class="form-control" id="avatar" name="avatar" type="file" accept="image/png,image/jpeg"></div><button class="btn btn-primary" type="submit">zarejestruj</button></form><p><a class="btn btn-outline-secondary" href="/z5/logowanie.php">powrót</a></p>';
    z5_page_end();
}
