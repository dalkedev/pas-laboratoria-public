<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();
page_header('strona chroniona');
?>
<p data-authenticated="true">jestem zalogowany: <?= e((string) $_SESSION['username']) ?></p>
<a class="btn btn-primary me-2" href="/z1/logout.php">wyloguj</a>
<a class="btn btn-outline-secondary" href="/z1/">powrót</a>
<?php page_footer(); ?>
