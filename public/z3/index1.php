<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z3_diagnostics.php';
z3_require_login();
z3_page_start('netstat', true);
z3_table(z3_local_ports());
z3_page_end();
