<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z4_monitor.php';
z4_require_login();
[$where, $parameters] = z4_owner_clause('d.');
$stmt = db()->prepare('SELECT h.*,d.host,d.port FROM z4_probe_history h JOIN domeny d ON d.id=h.domena_id WHERE ' . $where . ' ORDER BY h.id DESC LIMIT 100');
$stmt->execute($parameters);
z4_page_start('historia', true);
echo '<div class="table-responsive mb-4"><table class="table table-striped table-bordered table-hover align-middle"><thead class="table-light"><tr><th scope="col">host</th><th scope="col">port</th><th scope="col">data</th><th scope="col">stan</th><th scope="col">błąd</th></tr></thead><tbody>';
foreach ($stmt->fetchAll() as $row) {
    echo '<tr><td>' . e($row['host']) . '</td><td>' . e((string) $row['port']) . '</td><td>' . e($row['checked_at']) . '</td><td>' . e($row['state']) . '</td><td>' . e($row['error']) . '</td></tr>';
}
echo '</tbody></table></div>';
z4_page_end();
