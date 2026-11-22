<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/z4_monitor.php';
z4_require_login();
z4_page_start('monitorowanie usług', true);
?>
<div class="d-flex flex-wrap gap-3 align-items-center mb-3">
<button class="btn btn-primary" id="probe-now" type="button">sprawdź teraz</button>
<label class="form-label mb-0" for="alarm-sound">dźwięk:</label><select class="form-select w-auto z4-sound" id="alarm-sound"><option value="1">sygnał 1</option><option value="2">sygnał 2</option><option value="3">sygnał 3</option><option value="off">wyłączony</option></select>
<span id="probe-status" role="status"></span>
</div>
<div class="table-responsive mb-4"><table class="table table-striped table-bordered table-hover align-middle"><thead class="table-light"><tr><th scope="col">id</th><th scope="col">host</th><th scope="col">port</th><th scope="col">stan</th><th scope="col">błąd</th><th scope="col">kolejne błędy</th><th scope="col">początek awarii</th><th scope="col">wszystkie błędy</th><th scope="col">czas awarii</th><?php if (z4_is_admin()): ?><th scope="col">użytkownik</th><?php endif; ?><th scope="col">zmień</th></tr></thead><tbody id="monitor-rows">
<?php foreach (z4_domains() as $row): ?>
<tr data-domain-id="<?= e((string) $row['id']) ?>"><td><?= e((string) $row['id']) ?></td><td><?= e($row['host']) ?></td><td><?= e((string) $row['port']) ?></td><td data-field="status"><?= e($row['status']) ?></td><td data-field="last_error"><?= e($row['last_error']) ?></td><td data-field="errors"><?= e((string) $row['errors']) ?></td><td data-field="failure"><?= e((string) ($row['failure'] ?? '-')) ?></td><td data-field="total_errors"><?= e((string) $row['total_errors']) ?></td><td data-field="downtime"><?= e(z4_duration((int) $row['total_errors'] * 10)) ?></td><?php if (z4_is_admin()): ?><td><?= e($row['username']) ?></td><?php endif; ?><td class="text-nowrap"><a class="btn btn-sm btn-outline-primary" href="/z4/edit.php?id=<?= (int) $row['id'] ?>">edytuj</a> <a class="btn btn-sm btn-outline-danger" href="/z4/kasuj1.php?id=<?= (int) $row['id'] ?>">usuń</a></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php z4_page_end(); ?>
