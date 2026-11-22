<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z3_diagnostics.php';
z3_require_login();
z3_page_start('geolokalizacja ip', true);
$ip = z3_client_ip();
$geo = z3_geo($ip);
z3_table(['ip' => $ip, 'status' => $geo['status'], 'miejscowość' => $geo['city'] ?: '-', 'region' => $geo['region'] ?: '-', 'kraj' => $geo['country'] ?: '-', 'współrzędne przybliżone' => $geo['loc'] ?: '-']);
if ($geo['loc'] !== '') {
    echo '<a href="' . e('https://www.google.com/maps/search/?api=1&query=' . rawurlencode($geo['loc'])) . '" target="_blank" rel="noopener noreferrer">google maps</a>';
}
z3_page_end();
