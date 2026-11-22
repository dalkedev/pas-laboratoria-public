<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z4.php';
z4_require_https();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('/z4/');
}
z4_check_csrf();
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
redirect('/z4/logowanie.php');
