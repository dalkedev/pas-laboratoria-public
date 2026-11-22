<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z2.php';
require_z2_login();
$stmt = db()->prepare('SELECT image FROM user_avatars WHERE user_id = ?');
$stmt->execute([(int) $_SESSION['user_id']]);
$image = $stmt->fetchColumn();
if ($image !== false) {
    header('Content-Type: image/png');
    header('Content-Length: ' . strlen($image));
    echo $image;
} else {
    header('Content-Type: image/svg+xml');
    readfile(__DIR__ . '/media/default-avatar.svg');
}
