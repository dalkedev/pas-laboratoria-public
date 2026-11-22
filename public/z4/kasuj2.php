<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z4_monitor.php';
z4_require_login();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('/z4/kasuj1.php');
}
z4_check_csrf();
try {
    [$host, $port] = z4_host_port();
} catch (InvalidArgumentException $exception) {
    http_response_code(422);
    z4_host_form('/z4/kasuj2.php', 'usuń host', [], $exception->getMessage());
    exit;
}
[$where, $parameters] = z4_owner_clause();
$id = post_string('id');
if ($id !== '' && (!ctype_digit($id) || (int) $id < 1)) {
    http_response_code(422);
    z4_host_form('/z4/kasuj2.php', 'usuń host', [], 'nieprawidłowa usługa.');
    exit;
}
if ($id !== '') {
    $where .= ' AND id=?';
    $parameters[] = (int) $id;
}
$stmt = db()->prepare('SELECT id FROM domeny WHERE host=? AND port=? AND ' . $where);
$stmt->execute(array_merge([$host, $port], $parameters));
$matching = $stmt->fetchAll();
if (count($matching) > 1) {
    http_response_code(409);
    z4_host_form('/z4/kasuj2.php', 'usuń host', [], 'wybierz usługę z tabeli.');
    exit;
}
$stmt = db()->prepare('DELETE FROM domeny WHERE host=? AND port=? AND ' . $where);
$stmt->execute(array_merge([$host, $port], $parameters));
if ($stmt->rowCount() === 0) {
    http_response_code(404);
    z4_host_form('/z4/kasuj2.php', 'usuń host', [], 'nie znaleziono usługi.');
    exit;
}
redirect('/z4/index1.php');
