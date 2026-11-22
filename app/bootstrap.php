<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_exception_handler(static function (Throwable $error): void {
    error_log('Application failure: ' . get_class($error));
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
    }
    echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>dalke</title><link rel="stylesheet" href="/z2/vendor/bootstrap.min.css"></head><body><main class="container py-4"><h1 class="h4 mb-4">usługa chwilowo niedostępna</h1><p><a class="btn btn-outline-secondary" href="/">powrót</a></p></main></body></html>';
});

$configPath = getenv('APP_CONFIG_FILE') ?: __DIR__ . '/config.local.php';
$fileConfig = [];
if (is_file($configPath)) {
    $fileConfig = require $configPath;
    if (!is_array($fileConfig)) {
        throw new RuntimeException('Invalid application configuration.');
    }
}
$GLOBALS['app_config'] = $fileConfig;

function config(string $name, string $default = ''): string
{
    $value = getenv($name);
    if ($value !== false) {
        return $value;
    }
    $value = $GLOBALS['app_config'][$name] ?? $default;
    return is_scalar($value) ? (string) $value : $default;
}

function app_task(): string
{
    $path = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    return preg_match('~^/(z[1-5]|z6a|z6b)(?:/|$)~D', $path, $match) === 1 ? $match[1] : 'z1';
}

function app_session_name(): string
{
    $task = app_task();
    return $task === 'z1' ? 'PAS_SESSION' : 'PAS_' . strtoupper($task) . '_SESSION';
}

function app_session_path(): string
{
    return '/' . app_task() . '/';
}

function database_config(string $field, string $default = ''): string
{
    $task = app_task();
    if ($task === 'z1') {
        return config('DB_' . $field, $default);
    }
    return config('DB_' . strtoupper($task) . '_' . $field, $default);
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function is_local(): bool
{
    return config('APP_ENV', 'production') === 'local';
}

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Cache-Control: no-store, private, max-age=0');
if (is_https() && !is_local()) {
    header('Strict-Transport-Security: max-age=31536000');
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function require_secure_auth(): void
{
    if (!is_local() && !is_https()) {
        http_response_code(403);
        page_header('wymagane https');
        echo '<p><a href="/z1/">powrót</a></p>';
        page_footer();
        exit;
    }
}

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name(app_session_name());
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => app_session_path(),
        'secure' => is_https() || !is_local(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    $task = app_task();
    if (isset($_SESSION['task']) && $_SESSION['task'] !== $task) {
        $_SESSION = [];
        session_regenerate_id(false);
    } elseif (isset($_SESSION['loggedin']) && !isset($_SESSION['task'])) {
        $_SESSION = [];
        session_regenerate_id(false);
    }
    $_SESSION['task'] = $task;
    $lastActive = $_SESSION['last_active'] ?? 0;
    if (isset($_SESSION['loggedin']) && (!is_int($lastActive) || time() - $lastActive > 1800)) {
        $_SESSION = ['task' => $task];
        session_regenerate_id(true);
    }
    if (($_SESSION['loggedin'] ?? false) === true) {
        $_SESSION['last_active'] = time();
    }
}

function csrf_token(): string
{
    start_app_session();
    if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function check_csrf(): void
{
    start_app_session();
    $submitted = $_POST['csrf'] ?? null;
    $stored = $_SESSION['csrf'] ?? null;
    if (!is_string($submitted) || !is_string($stored) || !hash_equals($stored, $submitted)) {
        http_response_code(403);
        page_header('formularz wygasł');
        echo '<p>nieprawidłowy formularz.</p><a href="/z1/">powrót</a>';
        page_footer();
        exit;
    }
}

function post_string(string $name): string
{
    $value = $_POST[$name] ?? '';
    return is_string($value) ? $value : '';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $port = database_config('PORT', config('DB_PORT', '3306'));
    if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException('Invalid database port.');
    }
    $task = app_task();
    $host = database_config('HOST', config('DB_HOST', 'db'));
    $name = database_config('NAME', is_local() ? $task : '');
    $user = database_config('USER', is_local() ? $task : '');
    $password = database_config('PASSWORD', is_local() ? 'local-only-' . $task : '');
    if (!preg_match('/^[a-zA-Z0-9_.:-]+$/D', $host) || !preg_match('/^[a-zA-Z0-9_-]+$/D', $name) || $user === '' || $password === '') {
        throw new RuntimeException('Invalid database configuration.');
    }
    $pdo = new PDO(
        'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4',
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
    return $pdo;
}

function throttle_bucket(string $scope): string
{
    return hash('sha256', $scope . '|' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function throttle_guard(string $scope): void
{
    $stmt = db()->prepare('SELECT blocked_until FROM auth_attempts WHERE bucket = ?');
    $stmt->execute([throttle_bucket($scope)]);
    $blockedUntil = (int) ($stmt->fetchColumn() ?: 0);
    if ($blockedUntil > time()) {
        http_response_code(429);
        header('Retry-After: ' . (string) ($blockedUntil - time()));
        page_header('spróbuj ponownie za chwilę');
        echo '<p>zbyt wiele prób. spróbuj za minutę.</p><a href="/z1/">powrót</a>';
        page_footer();
        exit;
    }
}

function throttle_failure(string $scope): void
{
    $pdo = db();
    $bucket = throttle_bucket($scope);
    $now = time();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT IGNORE INTO auth_attempts (bucket, failures, window_started, blocked_until) VALUES (?, 0, ?, 0)');
        $stmt->execute([$bucket, $now]);
        $stmt = $pdo->prepare('SELECT failures, window_started, blocked_until FROM auth_attempts WHERE bucket = ? FOR UPDATE');
        $stmt->execute([$bucket]);
        $row = $stmt->fetch();
        $failures = $now - (int) $row['window_started'] >= 60 ? 1 : min(65535, (int) $row['failures'] + 1);
        $window = $now - (int) $row['window_started'] >= 60 ? $now : (int) $row['window_started'];
        $blockedUntil = $failures >= 5 ? max((int) $row['blocked_until'], $now + 60) : 0;
        $stmt = $pdo->prepare('UPDATE auth_attempts SET failures = ?, window_started = ?, blocked_until = ? WHERE bucket = ?');
        $stmt->execute([$failures, $window, $blockedUntil, $bucket]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function throttle_clear(string $scope): void
{
    $stmt = db()->prepare('DELETE FROM auth_attempts WHERE bucket = ?');
    $stmt->execute([throttle_bucket($scope)]);
}

function authenticate(string $username, string $password): ?array
{
    if ($username === '' || strlen($username) > 128 || $password === '' || strlen($password) > 72 || str_contains($password, "\0")) {
        return null;
    }
    $stmt = db()->prepare('SELECT id, username, password FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    $dummyHash = PHP_VERSION_ID >= 80400
        ? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi'
        : '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
    $valid = password_verify($password, $user === false ? $dummyHash : (string) $user['password']);
    if ($user === false || !$valid) {
        return null;
    }
    if (password_needs_rehash((string) $user['password'], PASSWORD_DEFAULT)) {
        $stmt = db()->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    return ['id' => (int) $user['id'], 'username' => (string) $user['username']];
}

function require_login(): void
{
    require_secure_auth();
    start_app_session();
    if (($_SESSION['loggedin'] ?? false) !== true || !isset($_SESSION['user_id'], $_SESSION['username'])) {
        redirect('/z1/index3.php');
    }
}

function page_header(string $heading, string $eyebrow = ''): void
{
    echo '<!doctype html><html lang="pl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>dalke</title><link rel="stylesheet" href="/z2/vendor/bootstrap.min.css"></head><body><main class="container py-4"><h1 class="h4 mb-4">' . e($heading) . '</h1>';
}

function page_footer(): void
{
    echo '</main></body></html>';
}

function login_form(int $phase, string $error = ''): void
{
    require_secure_auth();
    start_app_session();
    page_header('formularz logowania (wersja ' . $phase . ')');
    if ($error !== '') {
        echo '<p class="alert alert-danger" role="alert">' . e($error) . '</p>';
    }
    if (isset($_GET['registered']) && $_GET['registered'] === '1') {
        echo '<p class="alert alert-success" role="status">konto zostało utworzone.</p>';
    }
    echo '<form class="col-12 col-md-6 col-lg-4 mb-3" method="post" action="/z1/weryfikuj' . $phase . '.php">' . csrf_field() . '<div class="mb-3"><label class="form-label" for="user">login:</label><input class="form-control" id="user" name="user" type="text" maxlength="128" autocomplete="username" required></div><div class="mb-3"><label class="form-label" for="pass">hasło:</label><input class="form-control" id="pass" name="pass" type="password" maxlength="72" autocomplete="current-password" required></div><button class="btn btn-primary" type="submit">zaloguj</button></form><p><a class="btn btn-outline-secondary" href="/z1/">powrót</a></p>';
    page_footer();
}

function registration_form(string $error = '', string $username = ''): void
{
    require_secure_auth();
    start_app_session();
    page_header('formularz rejestracji');
    if ($error !== '') {
        echo '<p class="alert alert-danger" role="alert">' . e($error) . '</p>';
    }
    echo '<form class="col-12 col-md-6 col-lg-4 mb-3" method="post" action="/z1/add.php">' . csrf_field() . '<div class="mb-3"><label class="form-label" for="user">login:</label><input class="form-control" id="user" name="user" type="text" minlength="3" maxlength="128" pattern="[A-Za-z0-9_.-]{3,128}" autocomplete="username" value="' . e($username) . '" required></div><div class="mb-3"><label class="form-label" for="pass">hasło:</label><input class="form-control" id="pass" name="pass" type="password" maxlength="72" autocomplete="new-password" required></div><div class="mb-3"><label class="form-label" for="pass-repeat">powtórz hasło:</label><input class="form-control" id="pass-repeat" name="pass_repeat" type="password" maxlength="72" autocomplete="new-password" required></div><button class="btn btn-primary" type="submit">zarejestruj</button></form><p><a class="btn btn-outline-secondary" href="/z1/">powrót</a></p>';
    page_footer();
}
