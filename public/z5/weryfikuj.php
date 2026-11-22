<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z5_storage.php';
z5_require_https();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') redirect('/z5/logowanie.php');
z5_check_csrf();
z5_throttle_guard('login');
$username = post_string('user');
$user = authenticate($username, post_string('pass'));
if ($user === null) {
    z5_log_login($username, false);
    throttle_failure('login');
    http_response_code(401);
    z5_login_form('nieprawidłowy login lub hasło.');
    exit;
}
$lock = z5_lock_home($user);
z5_unlock($lock);
z5_log_login($username, true);
throttle_clear('login');
session_regenerate_id(true);
$_SESSION = ['task' => app_task(), 'loggedin' => true, 'user_id' => $user['id'], 'username' => $user['username'], 'last_active' => time(), 'csrf' => bin2hex(random_bytes(32))];
redirect('/z5/');
