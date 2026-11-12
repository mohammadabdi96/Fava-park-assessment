<?php
/** @var array $flashes */
$__icons = ['success' => 'check-circle', 'error' => 'warn', 'warning' => 'warn', 'info' => 'info'];
if (!empty($flashes)): ?>
<div class="space-y-2 mb-5 no-print" role="status">
  <?php foreach ($flashes as $f):
      $type = isset($__icons[$f['type']]) ? $f['type'] : 'info'; ?>
    <div class="alert alert-<?= e($type) ?>">
      <span class="shrink-0 mt-0.5"><?= icon($__icons[$type], 'w-5 h-5') ?></span>
      <div class="min-w-0"><?= nl2br(e($f['message'])) ?></div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
