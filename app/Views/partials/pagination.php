<?php
/**
 * @var int $page
 * @var int $pages
 * @var string $path   route path, e.g. /admin/proposals
 * @var array $query   other query parameters to keep
 */
if ($pages > 1):
    $from = max(1, $page - 2);
    $to = min($pages, $page + 2);
    $link = function (int $n) use ($path, $query) {
        return url($path, array_filter($query, function ($v) { return $v !== '' && $v !== null; }) + ['page' => $n]);
    };
?>
<nav class="flex items-center justify-center gap-1 mt-5 text-sm no-print" aria-label="صفحه‌بندی">
  <?php if ($page > 1): ?><a class="btn btn-secondary btn-sm" href="<?= e($link($page - 1)) ?>">قبلی</a><?php endif; ?>
  <?php if ($from > 1): ?><a class="btn btn-ghost btn-sm" href="<?= e($link(1)) ?>"><?= fa('1') ?></a><span class="text-slate-400 px-1">…</span><?php endif; ?>
  <?php for ($n = $from; $n <= $to; $n++): ?>
    <a class="btn btn-sm <?= $n === $page ? 'btn-primary' : 'btn-ghost' ?>" href="<?= e($link($n)) ?>" <?= $n === $page ? 'aria-current="page"' : '' ?>><?= fa((string) $n) ?></a>
  <?php endfor; ?>
  <?php if ($to < $pages): ?><span class="text-slate-400 px-1">…</span><a class="btn btn-ghost btn-sm" href="<?= e($link($pages)) ?>"><?= fa((string) $pages) ?></a><?php endif; ?>
  <?php if ($page < $pages): ?><a class="btn btn-secondary btn-sm" href="<?= e($link($page + 1)) ?>">بعدی</a><?php endif; ?>
</nav>
<?php endif; ?>
