<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z4_monitor.php';
z4_require_admin();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    z4_check_csrf();
    try {
        [$host, $port] = z4_host_port();
        $worker = post_string('user_id');
        $stmt = db()->prepare('SELECT id FROM users WHERE id=? AND worker=1');
        $stmt->execute([$worker]);
        if (!ctype_digit($worker) || $stmt->fetchColumn() === false) {
            throw new InvalidArgumentException('wybierz pracownika.');
        }
        $stmt = db()->prepare('INSERT INTO domeny (host,port,user_id) VALUES (?,?,?)');
        $stmt->execute([$host, $port, (int) $worker]);
        redirect('/z4/index1.php');
    } catch (InvalidArgumentException $exception) {
        http_response_code(422);
        z4_host_form('/z4/staff.php', 'przypisz usługę', [], $exception->getMessage(), true);
        exit;
    } catch (PDOException $exception) {
        if ($exception->getCode() !== '23000') {
            throw $exception;
        }
        http_response_code(409);
        z4_host_form('/z4/staff.php', 'przypisz usługę', [], 'host i port już istnieją.', true);
        exit;
    }
}
z4_host_form('/z4/staff.php', 'przypisz usługę', [], '', true);
