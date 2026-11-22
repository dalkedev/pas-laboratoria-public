<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z5_storage.php';
z5_require_https();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') redirect('/z5/rejestruj.php');
z5_check_csrf();
z5_throttle_guard('registration');
$username = post_string('user');
$password = post_string('pass');
$error = '';
$avatar = null;
if (!preg_match('/^[A-Za-z0-9_.-]{3,128}$/D', $username) || $username === '.' || $username === '..') $error = 'nieprawidłowy login.';
elseif ($password === '' || strlen($password) > 72 || str_contains($password, "\0") || preg_match('//u', $password) !== 1) $error = 'nieprawidłowe hasło.';
elseif (!hash_equals($password, post_string('pass_repeat'))) $error = 'hasła nie są takie same.';
else {
    try { $avatar = z2_avatar_upload(); }
    catch (InvalidArgumentException $exception) { $error = $exception->getMessage(); }
}
if ($error !== '') {
    throttle_failure('registration');
    http_response_code(422);
    z5_registration_form($error, z5_clip($username, 128));
    exit;
}
$pdo = db();
$lock = null;
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('INSERT INTO users (username, password) VALUES (?, ?)');
    $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
    $id = (int) $pdo->lastInsertId();
    if ($avatar !== null) {
        $stmt = $pdo->prepare('INSERT INTO user_avatars (user_id, image) VALUES (?, ?)');
        $stmt->bindValue(1, $id, PDO::PARAM_INT);
        $stmt->bindValue(2, $avatar, PDO::PARAM_LOB);
        $stmt->execute();
    }
    $lock = z5_lock_home(['id' => $id, 'username' => $username]);
    $pdo->commit();
    z5_unlock($lock);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($lock !== null) z5_unlock($lock, true);
    if (!$exception instanceof PDOException || $exception->getCode() !== '23000') throw $exception;
    throttle_failure('registration');
    http_response_code(409);
    z5_registration_form('wybierz inny login.', $username);
    exit;
}
throttle_failure('registration');
redirect('/z5/logowanie.php?registered=1');
