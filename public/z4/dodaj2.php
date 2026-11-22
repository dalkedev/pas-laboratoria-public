<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z4_monitor.php';
z4_require_login();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('/z4/dodaj1.php');
}
z4_check_csrf();
try {
    [$host, $port] = z4_host_port();
    $stmt = db()->prepare('INSERT INTO domeny (host,port,user_id) VALUES (?,?,?)');
    $stmt->execute([$host, $port, (int) $_SESSION['user_id']]);
} catch (InvalidArgumentException $exception) {
    http_response_code(422);
    z4_host_form('/z4/dodaj2.php', 'dodaj host', [], $exception->getMessage());
    exit;
} catch (PDOException $exception) {
    if ($exception->getCode() !== '23000') {
        throw $exception;
    }
    http_response_code(409);
    z4_host_form('/z4/dodaj2.php', 'dodaj host', [], 'host i port już istnieją.');
    exit;
}
redirect('/z4/index1.php');
