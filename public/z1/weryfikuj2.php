<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_secure_auth();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('/z1/index2.php');
}
check_csrf();
throttle_guard('login');
$user = authenticate(post_string('user'), post_string('pass'));
if ($user === null) {
    throttle_failure('login');
    http_response_code(401);
    login_form(2, 'nieprawidłowy login lub hasło.');
    exit;
}
throttle_clear('login');
page_header('dane zweryfikowane');
echo '<p role="status" data-result="success">weryfikacja poprawna. użytkownik: ' . e($user['username']) . '</p><a class="btn btn-outline-secondary" href="/z1/">powrót</a>';
page_footer();
