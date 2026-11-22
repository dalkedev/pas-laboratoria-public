<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z4.php';
z4_require_https();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('/z4/logowanie.php');
}
z4_check_csrf();
z4_throttle_guard('login');
$user = authenticate(post_string('user'), post_string('pass'));
if ($user === null) {
    throttle_failure('login');
    http_response_code(401);
    z4_login_form('nieprawidłowy login lub hasło.');
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
redirect('/z4/');
