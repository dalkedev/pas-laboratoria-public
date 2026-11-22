<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z5_storage.php';
z5_require_login();
try {
    $folder = z5_folder($_GET['dir'] ?? '');
    $lock = z5_lock_home(null, false);
    try { z5_directory($lock, $folder); } finally { z5_unlock($lock); }
} catch (InvalidArgumentException $error) { z5_fail(422, $error->getMessage()); }
catch (OutOfBoundsException $error) { z5_fail(404, $error->getMessage()); }
z5_page_start('prześlij plik', true);
?>
<form action="/z5/upload.php" method="post" enctype="multipart/form-data" class="col-md-6 col-lg-4 mb-3 z5-upload">
<?= csrf_field() ?><input type="hidden" name="dir" value="<?= e($folder) ?>">
<label for="fileToUpload" class="form-label">plik:</label><input class="form-control" type="file" name="fileToUpload" id="fileToUpload" required>
<button class="btn btn-primary mt-3" type="submit">prześlij</button></form>
<p><a class="btn btn-outline-secondary" href="/z5/?<?= e(http_build_query(['dir' => $folder])) ?>">powrót</a></p>
<?php z5_page_end(); ?>
