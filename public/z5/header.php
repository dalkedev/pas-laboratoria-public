<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z5.php';
z5_require_login();
?>
<header><nav class="navbar navbar-expand-md bg-body-tertiary border-bottom" aria-label="menu"><div class="container z5-nav-inner">
<a class="navbar-brand" href="/z5/">mycloud</a>
<button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#main_nav" aria-controls="main_nav" aria-expanded="false" aria-label="rozwiń menu"><span class="navbar-toggler-icon"></span></button>
<div class="collapse navbar-collapse" id="main_nav"><ul class="navbar-nav me-auto">
<li class="nav-item"><a class="nav-link" href="/z5/">pliki</a></li>
<li class="nav-item"><a class="nav-link" href="/z5/logowania.php">logowania</a></li>
<li class="nav-item"><a class="nav-link" href="/z5/visits.php">goście portalu</a></li>
<li class="nav-item"><a class="nav-link" href="/">zadania</a></li>
</ul><span class="navbar-text text-nowrap"><img class="rounded-circle object-fit-cover me-1" src="/z5/avatar.php" width="24" height="24" alt="avatar"> <?= e((string) $_SESSION['username']) ?></span>
<form class="d-flex ms-md-3 mt-2 mt-md-0" method="post" action="/z5/logout.php"><?= csrf_field() ?><button class="btn btn-outline-secondary" type="submit">wyloguj</button></form>
</div></div></nav></header>
