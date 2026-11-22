<?php

declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
page_header('zadania laboratoryjne');
?>
<div class="list-group">
    <a class="list-group-item list-group-item-action" href="/z1/">zadanie 1 logowanie, sesja i rejestracja</a>
    <a class="list-group-item list-group-item-action" href="/z2/">zadanie 2 bootstrap</a>
    <a class="list-group-item list-group-item-action" href="/z3/">zadanie 3 geolokalizacja</a>
    <a class="list-group-item list-group-item-action" href="/z4/">zadanie 4 monitorowanie usług</a>
    <a class="list-group-item list-group-item-action" href="/z5/">zadanie 5 mycloud</a>
    <a class="list-group-item list-group-item-action" href="/z6a/">zadanie 6a myspotify</a>
    <a class="list-group-item list-group-item-action" href="/z6b/">zadanie 6b mynetflix</a>
    <?php for ($task = 7; $task <= 12; $task++): ?>
    <a class="list-group-item list-group-item-action" href="/z<?= $task ?>/">zadanie <?= $task ?></a>
    <?php endfor; ?>
    <a class="list-group-item list-group-item-action" href="/projekt/">projekt</a>
</div>
<?php page_footer(); ?>
