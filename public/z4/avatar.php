<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z4.php';
z4_require_login();
$stmt = db()->prepare('SELECT image FROM user_avatars WHERE user_id = ?');
$stmt->execute([(int) $_SESSION['user_id']]);
$image = $stmt->fetchColumn();
if ($image !== false) {
    header('Content-Type: image/png');
    header('Content-Length: ' . strlen($image));
    echo $image;
} else {
    header('Content-Type: image/svg+xml');
    readfile(dirname(__DIR__) . '/z2/media/default-avatar.svg');
}
