<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z2.php';
require_z2_login();
?>
<header>
<nav class="navbar navbar-expand-md bg-body-tertiary border-bottom" aria-label="menu">
<div class="container">
<button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#main_nav" aria-controls="main_nav" aria-expanded="false" aria-label="rozwiń menu"><span class="navbar-toggler-icon"></span></button>
<div class="collapse navbar-collapse" id="main_nav">
<ul class="navbar-nav me-auto">
<?php foreach ([1 => 2, 2 => 2, 3 => 3] as $group => $count): ?>
<li class="nav-item dropdown">
<button class="nav-link dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">polecenia <?= $group ?>.x</button>
<ul class="dropdown-menu">
<?php for ($number = 1; $number <= $count; $number++): ?>
<li><a class="dropdown-item" href="/z2/polecenie<?= $group ?>_<?= $number ?>.php">polecenie <?= $group ?>.<?= $number ?></a></li>
<?php endfor; ?>
</ul>
</li>
<?php endforeach; ?>
</ul>
<span class="navbar-text text-nowrap"><img class="rounded-circle object-fit-cover me-1" src="/z2/avatar.php" width="24" height="24" alt="avatar"> <?= e((string) $_SESSION['username']) ?></span>
<form class="d-flex ms-md-3" method="post" action="/z2/logout.php">
<?= csrf_field() ?>
<button class="btn btn-link" type="submit"><svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><path d="M6 2H2v12h4M9 4l4 4-4 4M5 8h8" fill="none" stroke="currentColor" stroke-width="1.5"/></svg> wyloguj</button>
</form>
</div>
</div>
</nav>
</header>
