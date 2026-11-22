<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z2.php';
z2_require_https();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('/z2/rejestruj.php');
}
z2_check_csrf();
throttle_guard('registration');
$username = post_string('user');
$password = post_string('pass');
$repeat = post_string('pass_repeat');
$error = '';
$avatar = null;
if (!preg_match('/^[A-Za-z0-9_.-]{3,128}$/D', $username)) {
    $error = 'nieprawidłowy login.';
} elseif ($password === '' || preg_match('//u', $password) !== 1 || strlen($password) > 72 || str_contains($password, "\0")) {
    $error = 'nieprawidłowe hasło.';
} elseif (!hash_equals($password, $repeat)) {
    $error = 'hasła nie są takie same.';
} else {
    try {
        $avatar = z2_avatar_upload();
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    }
}
if ($error !== '') {
    throttle_failure('registration');
    http_response_code(422);
    z2_registration_form($error, substr($username, 0, 128));
    exit;
}
$pdo = db();
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('INSERT INTO users (username, password) VALUES (?, ?)');
    $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
    if ($avatar !== null) {
        $stmt = $pdo->prepare('INSERT INTO user_avatars (user_id, image) VALUES (?, ?)');
        $stmt->bindValue(1, (int) $pdo->lastInsertId(), PDO::PARAM_INT);
        $stmt->bindValue(2, $avatar, PDO::PARAM_LOB);
        $stmt->execute();
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (!$exception instanceof PDOException || $exception->getCode() !== '23000') {
        throw $exception;
    }
    throttle_failure('registration');
    http_response_code(409);
    z2_registration_form('wybierz inny login.', $username);
    exit;
}
throttle_failure('registration');
redirect('/z2/logowanie.php?registered=1');
