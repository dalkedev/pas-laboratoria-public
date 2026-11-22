<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z5_storage.php';
z5_require_login();
$user = z5_cloud_user();
$stmt = db()->prepare("SELECT datetime, ipaddress, login_success FROM goscieportalu WHERE application = 'z5' AND event_type = 'login' AND login_name = ? ORDER BY id DESC LIMIT 100");
$stmt->execute([$user['username']]);
z5_page_start('logowania', true);
echo '<div class="table-responsive mb-4"><table class="table table-striped table-bordered table-hover align-middle"><thead class="table-light"><tr><th scope="col">data</th><th scope="col">ip</th><th scope="col">wynik</th></tr></thead><tbody>';
foreach ($stmt as $row) echo '<tr><td>' . e($row['datetime']) . '</td><td>' . e($row['ipaddress']) . '</td><td>' . ((int) $row['login_success'] === 1 ? 'poprawne' : 'błędne') . '</td></tr>';
echo '</tbody></table></div>';
z5_page_end();
