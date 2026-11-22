<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');
try {
    db()->query('SELECT id FROM users LIMIT 0');
    db()->query('SELECT bucket FROM auth_attempts LIMIT 0');
    echo json_encode(['status' => 'ok', 'database' => 'ok'], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('Database readiness check failed: ' . get_class($error));
    http_response_code(503);
    echo json_encode(['status' => 'unavailable'], JSON_THROW_ON_ERROR);
}
