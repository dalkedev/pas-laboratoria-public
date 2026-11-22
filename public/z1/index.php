<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
page_header('zadanie 1');
?>
<div class="list-group">
<a class="list-group-item list-group-item-action" href="/z1/index1.php">1. logowanie podatne na sql injection</a>
<a class="list-group-item list-group-item-action" href="/z1/index2.php">2. logowanie zabezpieczone</a>
<a class="list-group-item list-group-item-action" href="/z1/index3.php">3. logowanie z sesją</a>
<a class="list-group-item list-group-item-action" href="/z1/index4.php">4. strona chroniona</a>
<a class="list-group-item list-group-item-action" href="/z1/rejestruj.php">5. rejestracja</a>
</div>
<?php page_footer(); ?>
