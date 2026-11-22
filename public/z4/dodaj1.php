<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z4_monitor.php';
z4_require_login();
z4_host_form('/z4/dodaj2.php', 'dodaj host');
