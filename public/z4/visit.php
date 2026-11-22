<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z4.php';
header('Content-Type: application/json; charset=UTF-8');

function visit_response(int $status, string $result): never
{
    http_response_code($status);
    echo json_encode(['status' => $result], JSON_THROW_ON_ERROR);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    visit_response(405, 'method');
}
if (!is_local() && !is_https()) {
    visit_response(403, 'https');
}
if (!str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    visit_response(415, 'content-type');
}
$body = file_get_contents('php://input', false, null, 0, 4097);
if (!is_string($body) || strlen($body) > 4096) {
    visit_response(413, 'size');
}
try {
    $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
} catch (JsonException $error) {
    visit_response(400, 'json');
}
if (!is_array($data)) {
    visit_response(400, 'json');
}
start_app_session();
$csrf = $data['csrf'] ?? null;
$stored = $_SESSION['csrf'] ?? null;
if (!is_string($csrf) || !is_string($stored) || !hash_equals($stored, $csrf)) {
    visit_response(403, 'csrf');
}
$id = $data['visit_id'] ?? null;
$nonce = $data['visit_nonce'] ?? null;
if ((!is_string($id) && !is_int($id)) || !preg_match('/^[1-9][0-9]{0,19}$/D', (string) $id) || !is_string($nonce)) {
    visit_response(422, 'visit');
}
$entry = $_SESSION['z4_visits'][(string) $id] ?? null;
if (!is_array($entry) || (int) ($entry['expires'] ?? 0) < time() || !is_string($entry['nonce'] ?? null) || !hash_equals($entry['nonce'], $nonce)) {
    visit_response(403, 'visit');
}
foreach (['screen_width', 'screen_height', 'viewport_width', 'viewport_height'] as $field) {
    if (!is_int($data[$field] ?? null) || $data[$field] < 1 || $data[$field] > 65535) {
        visit_response(422, $field);
    }
}
if (!is_int($data['colors'] ?? null) || $data['colors'] < 1 || $data['colors'] > 64 || !is_bool($data['cookies_enabled'] ?? null) || !is_bool($data['java_enabled'] ?? null)) {
    visit_response(422, 'metrics');
}
$language = $data['language'] ?? null;
$browser = $data['browser'] ?? null;
if (!is_string($language) || !preg_match('/^[A-Za-z0-9-]{1,35}$/D', $language) || !is_string($browser) || $browser === '' || strlen($browser) > 128 || preg_match('//u', $browser) !== 1) {
    visit_response(422, 'browser');
}
$browser = trim((string) preg_replace('/[\x00-\x1f\x7f]/u', ' ', $browser));
if ($browser === '') {
    visit_response(422, 'browser');
}
$stmt = db()->prepare('UPDATE goscieportalu SET browser = ?, screen_width = ?, screen_height = ?, viewport_width = ?, viewport_height = ?, colors = ?, cookies_enabled = ?, java_enabled = ?, language = ? WHERE id = ?');
$stmt->execute([$browser, $data['screen_width'], $data['screen_height'], $data['viewport_width'], $data['viewport_height'], $data['colors'], (int) $data['cookies_enabled'], (int) $data['java_enabled'], $language, (string) $id]);
unset($_SESSION['z4_visits'][(string) $id]);
visit_response(200, 'ok');
