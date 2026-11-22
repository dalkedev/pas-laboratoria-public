<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z2.php';
z2_require_https();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('/z2/logowanie.php');
}
z2_check_csrf();
throttle_guard('login');
$user = authenticate(post_string('user'), post_string('pass'));
if ($user === null) {
    throttle_failure('login');
    http_response_code(401);
    z2_login_form('nieprawidłowy login lub hasło.');
    exit;
}
throttle_clear('login');
session_regenerate_id(true);
$_SESSION = [
    'task' => app_task(),
    'loggedin' => true,
    'user_id' => $user['id'],
    'username' => $user['username'],
    'last_active' => time(),
    'csrf' => bin2hex(random_bytes(32)),
];
redirect('/z2/');
