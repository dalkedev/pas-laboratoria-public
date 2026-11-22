<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z4_monitor.php';
z4_require_login();
$id = filter_var($_POST['id'] ?? $_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$row = $id === false ? null : z4_domain((int) $id);
if ($row === null) {
    http_response_code(404);
    z4_page_start('nie znaleziono usługi', true);
    z4_page_end();
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    z4_check_csrf();
    try {
        [$host, $port] = z4_host_port();
        [$where, $parameters] = z4_owner_clause();
        $stmt = db()->prepare('UPDATE domeny SET host=?,port=?,status=\'nie sprawdzono\',errors=0,total_errors=0,failure=NULL,checked_at=NULL,last_error=\'\' WHERE id=? AND ' . $where);
        $stmt->execute(array_merge([$host, $port, $id], $parameters));
        redirect('/z4/index1.php');
    } catch (InvalidArgumentException $exception) {
        http_response_code(422);
        z4_host_form('/z4/edit.php', 'edytuj host', $row, $exception->getMessage());
        exit;
    } catch (PDOException $exception) {
        if ($exception->getCode() !== '23000') {
            throw $exception;
        }
        http_response_code(409);
        z4_host_form('/z4/edit.php', 'edytuj host', $row, 'host i port już istnieją.');
        exit;
    }
}
z4_host_form('/z4/edit.php', 'edytuj host', $row);
