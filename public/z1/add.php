<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_secure_auth();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('/z1/rejestruj.php');
}
check_csrf();
throttle_guard('registration');
$username = post_string('user');
$password = post_string('pass');
$repeat = post_string('pass_repeat');
$error = '';
if (!preg_match('/^[A-Za-z0-9_.-]{3,128}$/D', $username)) {
    $error = 'login musi zawierać od 3 do 128 znaków: litery a do z, cyfry, kropkę, myślnik lub podkreślenie.';
} elseif ($password === '' || preg_match('//u', $password) !== 1 || strlen($password) > 72 || str_contains($password, "\0")) {
    $error = 'nieprawidłowe hasło.';
} elseif (!hash_equals($password, $repeat)) {
    $error = 'wpisane hasła muszą być takie same.';
}
if ($error !== '') {
    throttle_failure('registration');
    http_response_code(422);
    registration_form($error, substr($username, 0, 128));
    exit;
}
try {
    $stmt = db()->prepare('INSERT INTO users (username, password) VALUES (?, ?)');
    $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
} catch (PDOException $error) {
    if ($error->getCode() !== '23000') {
        throw $error;
    }
    throttle_failure('registration');
    http_response_code(409);
    registration_form('nie udało się utworzyć konta. wybierz inny login i spróbuj ponownie.', $username);
    exit;
}
throttle_failure('registration');
redirect('/z1/index3.php?registered=1');
