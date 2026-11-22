<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z3.php';
z3_require_login();
?>
<header>
<nav class="navbar navbar-expand-md bg-body-tertiary border-bottom" aria-label="menu">
<div class="container">
<a class="navbar-brand" href="/z3/">zadanie 3</a>
<button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#main_nav" aria-controls="main_nav" aria-expanded="false" aria-label="rozwiń menu"><span class="navbar-toggler-icon"></span></button>
<div class="collapse navbar-collapse" id="main_nav">
<ul class="navbar-nav me-auto">
<?php foreach (['serwer' => [1 => 'netstat', 2 => 'phpinfo', 3 => 'whoami', 4 => 'top', 5 => 'pliki'], 'dns' => [6 => 'dns', 7 => 'nazwy i ip']] as $group => $items): ?>
<li class="nav-item dropdown">
<button class="nav-link dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><?= e($group) ?></button>
<ul class="dropdown-menu">
<?php foreach ($items as $number => $label): ?>
<li><a class="dropdown-item" href="/z3/index<?= $number ?>.php"><?= e($label) ?></a></li>
<?php endforeach; ?>
</ul>
</li>
<?php endforeach; ?>
<li class="nav-item"><a class="nav-link" href="/z3/index8.php">geolokalizacja</a></li>
<li class="nav-item"><a class="nav-link" href="/z3/index9.php">goście portalu</a></li>
</ul>
<span class="navbar-text text-nowrap"><img class="rounded-circle object-fit-cover me-1" src="/z3/avatar.php" width="24" height="24" alt="avatar"> <?= e((string) $_SESSION['username']) ?></span>
<form class="d-flex ms-md-3" method="post" action="/z3/logout.php">
<?= csrf_field() ?>
<button class="btn btn-link" type="submit"><svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><path d="M6 2H2v12h4M9 4l4 4-4 4M5 8h8" fill="none" stroke="currentColor" stroke-width="1.5"/></svg> wyloguj</button>
</form>
</div>
</div>
</nav>
</header>
