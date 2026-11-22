<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z3_diagnostics.php';
z3_require_login();
z3_page_start('top', true);
echo '<pre class="bg-body-tertiary border rounded p-3 overflow-auto">' . e(z3_top()) . '</pre>';
z3_page_end();
