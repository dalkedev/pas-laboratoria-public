<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_secure_auth();
start_app_session();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    check_csrf();
    $_SESSION = [];
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => $cookie['path'],
        'secure' => $cookie['secure'],
        'httponly' => $cookie['httponly'],
        'samesite' => $cookie['samesite'],
    ]);
    session_destroy();
    redirect('/z1/index3.php');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}
page_header('wylogowanie');
if (($_SESSION['loggedin'] ?? false) === true) {
    echo '<form class="mb-3" method="post" action="/z1/logout.php">' . csrf_field() . '<button class="btn btn-primary" type="submit">wyloguj</button></form>';
} else {
    echo '<p>brak aktywnej sesji.</p>';
}
echo '<p><a class="btn btn-outline-secondary" href="/z1/">powrót</a></p>';
page_footer();
