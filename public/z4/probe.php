<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z4_monitor.php';
z4_require_login();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
z4_check_csrf();
header('Content-Type: application/json; charset=UTF-8');
$id = post_string('id');
if ($id !== '' && (!ctype_digit($id) || (int) $id < 1)) {
    http_response_code(422);
    echo json_encode(['status' => 'id'], JSON_THROW_ON_ERROR);
    exit;
}
$targets = z4_domains();
usort($targets, static fn (array $left, array $right): int => strcmp((string) $left['checked_at'], (string) $right['checked_at']) ?: (int) $left['id'] <=> (int) $right['id']);
$ids = $id !== '' ? [(int) $id] : array_slice(array_column($targets, 'id'), 0, 25);
$rows = [];
$deadline = microtime(true) + 4;
foreach ($ids as $item) {
    if (microtime(true) > $deadline) {
        break;
    }
    $row = z4_probe_one((int) $item);
    if ($row !== null) {
        $row['downtime'] = z4_duration((int) $row['total_errors'] * 10);
        $rows[] = $row;
    }
}
if ($id !== '' && $rows === []) {
    http_response_code(404);
}
echo json_encode(['status' => 'ok', 'rows' => $rows], JSON_THROW_ON_ERROR);
