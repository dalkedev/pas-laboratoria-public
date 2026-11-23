<?php

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/avatar.php';
header("Content-Security-Policy: default-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");

function z2_require_https(): void
{
    if (!is_local() && !is_https()) {
        http_response_code(403);
        z2_page_start('wymagane https');
        z2_page_end();
        exit;
    }
}

function require_z2_login(): void
{
    z2_require_https();
    start_app_session();
    if (($_SESSION['loggedin'] ?? false) !== true || !isset($_SESSION['user_id'], $_SESSION['username'])) {
        redirect('/z2/logowanie.php');
    }
}

function z2_check_csrf(): void
{
    start_app_session();
    $token = $_POST['csrf'] ?? null;
    $stored = $_SESSION['csrf'] ?? null;
    if (!is_string($token) || !is_string($stored) || !hash_equals($stored, $token)) {
        http_response_code(403);
        z2_page_start('nieprawidłowy formularz');
        echo '<a href="/z2/">powrót</a>';
        z2_page_end();
        exit;
    }
}

function z2_page_start(string $heading, bool $authenticated = false): void
{
    echo '<!doctype html><html lang="pl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>dalke</title><link rel="stylesheet" href="/z2/vendor/bootstrap.min.css"><link rel="stylesheet" href="/z2/twoj_css.css?v=bootstrap-20261109">';
    if ($authenticated) {
        echo '<script src="/z2/vendor/bootstrap.bundle.min.js" defer></script><script src="/z2/twoj_js.js" defer></script>';
    }
    echo '</head><body>';
    if ($authenticated) {
        echo '<div id="myHeader"></div><main class="container py-4" data-authenticated="true"><h1 class="h4 mb-4">' . e($heading) . '</h1>';
    } else {
        echo '<main class="container py-4"><h1 class="h4 mb-4">' . e($heading) . '</h1>';
    }
}

function z2_page_end(): void
{
    require rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/') . '/z2/footer.php';
    echo '</main></body></html>';
}

function z2_login_form(string $error = ''): void
{
    z2_require_https();
    start_app_session();
    z2_page_start('logowanie');
    if ($error !== '') {
        echo '<p class="alert alert-danger" role="alert">' . e($error) . '</p>';
    }
    if (($_GET['registered'] ?? null) === '1') {
        echo '<p class="alert alert-success" role="status">konto zostało utworzone.</p>';
    }
    echo '<form class="col-12 col-md-6 col-lg-4 mb-4" method="post" action="/z2/weryfikuj.php">' . csrf_field() . '<div class="mb-3"><label class="form-label" for="user">login:</label><input class="form-control" id="user" name="user" maxlength="128" autocomplete="username" required></div><div class="mb-3"><label class="form-label" for="pass">hasło:</label><input class="form-control" id="pass" type="password" name="pass" maxlength="72" autocomplete="current-password" required></div><button class="btn btn-secondary" type="submit">zaloguj</button></form><p><a href="/z2/rejestruj.php">rejestracja</a></p><a href="/">powrót</a>';
    z2_page_end();
}

function z2_registration_form(string $error = '', string $username = ''): void
{
    z2_require_https();
    start_app_session();
    z2_page_start('rejestracja');
    if ($error !== '') {
        echo '<p class="alert alert-danger" role="alert">' . e($error) . '</p>';
    }
    echo '<form class="col-12 col-md-6 col-lg-4 mb-4" method="post" action="/z2/add.php" enctype="multipart/form-data">' . csrf_field() . '<div class="mb-3"><label class="form-label" for="user">login:</label><input class="form-control" id="user" name="user" minlength="3" maxlength="128" pattern="[A-Za-z0-9_.-]{3,128}" autocomplete="username" value="' . e($username) . '" required></div><div class="mb-3"><label class="form-label" for="pass">hasło:</label><input class="form-control" id="pass" name="pass" type="password" maxlength="72" autocomplete="new-password" required></div><div class="mb-3"><label class="form-label" for="pass-repeat">powtórz hasło:</label><input class="form-control" id="pass-repeat" name="pass_repeat" type="password" maxlength="72" autocomplete="new-password" required></div><div class="mb-3"><label class="form-label" for="avatar">avatar:</label><input class="form-control" id="avatar" name="avatar" type="file" accept="image/png,image/jpeg"></div><button class="btn btn-secondary" type="submit">zarejestruj</button></form><p><a href="/z2/logowanie.php">powrót</a></p>';
    z2_page_end();
}

function z2_avatar_upload(): ?string
{
    return avatar_upload([
        'invalid' => 'nieprawidłowy avatar.',
        'size' => 'avatar: maksymalnie 2 mib.',
        'format' => 'avatar: jpg lub png.',
        'dimensions' => 'avatar: maksymalnie 2000 x 2000.',
    ]);
}
