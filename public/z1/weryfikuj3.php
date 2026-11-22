<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_secure_auth();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('/z1/index3.php');
}
check_csrf();
throttle_guard('login');
$user = authenticate(post_string('user'), post_string('pass'));
if ($user === null) {
    throttle_failure('login');
    http_response_code(401);
    login_form(3, 'nieprawidłowy login lub hasło.');
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
redirect('/z1/index4.php');
