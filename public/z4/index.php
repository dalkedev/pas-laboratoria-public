<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z4_monitor.php';
z4_require_login();
z4_page_start('zadanie 4', true);
echo '<nav class="d-flex flex-wrap gap-2 z4-shortcuts"><a class="btn btn-outline-primary" href="/z4/index1.php">monitorowanie</a><a class="btn btn-outline-primary" href="/z4/dodaj1.php">dodaj host</a><a class="btn btn-outline-primary" href="/z4/kasuj1.php">usuń host</a><a class="btn btn-outline-primary" href="/z4/history.php">historia</a></nav>';
z4_page_end();
