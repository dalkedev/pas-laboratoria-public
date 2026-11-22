<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z4_monitor.php';
z4_require_login();
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$row = $id ? z4_domain((int) $id) : null;
z4_host_form('/z4/kasuj2.php', 'usuń host', $row ?? []);
