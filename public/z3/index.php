<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z3.php';
z3_require_login();
z3_page_start('zadanie 3', true);
echo '<nav class="nav flex-column flex-sm-row gap-2 z3-shortcuts" aria-label="skróty"><a class="btn btn-outline-secondary" href="/z3/index1.php">serwer</a><a class="btn btn-outline-secondary" href="/z3/index6.php">dns</a><a class="btn btn-outline-secondary" href="/z3/index8.php">geolokalizacja</a><a class="btn btn-outline-secondary" href="/z3/index9.php">goście portalu</a></nav>';
z3_page_end();
