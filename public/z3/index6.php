<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z3_diagnostics.php';
z3_require_login();
z3_page_start('dns pbs.edu.pl', true);
$records = z3_dns_records();
echo '<pre class="bg-body-tertiary border rounded p-3 overflow-auto">' . e($records === [] ? 'niedostępne' : (string) json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>';
z3_page_end();
