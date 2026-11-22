<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z4_monitor.php';
z4_require_login();
?>
<header><nav class="navbar navbar-expand-md bg-body-tertiary border-bottom" aria-label="menu"><div class="container z4-nav-inner">
<a class="navbar-brand" href="/z4/">zadanie 4</a>
<button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#main_nav" aria-controls="main_nav" aria-expanded="false" aria-label="rozwiń menu"><span class="navbar-toggler-icon"></span></button>
<div class="collapse navbar-collapse" id="main_nav"><ul class="navbar-nav me-auto">
<li class="nav-item"><a class="nav-link" href="/z4/index1.php">monitorowanie</a></li>
<li class="nav-item"><a class="nav-link" href="/z4/dodaj1.php">dodaj</a></li>
<li class="nav-item"><a class="nav-link" href="/z4/kasuj1.php">usuń</a></li>
<li class="nav-item"><a class="nav-link" href="/z4/history.php">historia</a></li>
<li class="nav-item"><a class="nav-link" href="/z4/visits.php">goście</a></li>
<?php if (z4_is_admin()): ?><li class="nav-item"><a class="nav-link" href="/z4/staff.php">pracownicy</a></li><?php endif; ?>
</ul><span class="navbar-text text-nowrap"><img class="rounded-circle object-fit-cover me-1" src="/z4/avatar.php" width="24" height="24" alt="avatar"> <?= e((string) $_SESSION['username']) ?></span>
<form class="d-flex ms-md-3 mt-2 mt-md-0" method="post" action="/z4/logout.php"><?= csrf_field() ?><button class="btn btn-outline-secondary" type="submit">wyloguj</button></form>
</div></div></nav></header>
