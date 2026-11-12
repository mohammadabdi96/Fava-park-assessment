<?php
/**
 * @var array $rows
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var string $action
 * @var array $labels
 */
use Fava\Core\View;

$actors = ['admin' => ['مدیر', 'blue'], 'judge' => ['داور', 'violet'], 'system' => ['سامانه', 'gray']];
?>
<div class="flex items-end justify-between gap-4 flex-wrap mb-5">
  <div>
    <h1 class="text-2xl font-extrabold text-slate-900">گزارش فعالیت‌ها</h1>
    <p class="text-sm text-slate-500 mt-1">همه رویدادهای فرایند، از بارگذاری طرح تا تصمیم نهایی</p>
  </div>
  <form method="get" action="<?= e(url('/admin/activity')) ?>" class="flex items-center gap-2">
    <?= route_field('/admin/activity') ?>
    <select class="input w-auto" name="action" onchange="this.form.submit()" aria-label="نوع رویداد">
      <option value="">همه رویدادها</option>
      <?php foreach ($labels as $k => $label): ?>
        <option value="<?= e($k) ?>" <?= $action === $k ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<div class="card overflow-hidden">
  <?php if (!$rows): ?>
    <div class="p-10 text-center text-sm text-slate-500">رویدادی ثبت نشده است.</div>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="tbl">
        <thead><tr><th>زمان</th><th>رویداد</th><th>شرح</th><th>طرح</th><th>انجام‌دهنده</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $ev): $actor = $actors[$ev['actor_type']] ?? [$ev['actor_type'], 'gray']; ?>
          <tr>
            <td class="whitespace-nowrap text-xs text-slate-500 num"><?= e(jdate($ev['created_at'], 'Y/m/d H:i')) ?></td>
            <td class="whitespace-nowrap"><?= badge($labels[$ev['action']] ?? $ev['action'], $ev['action'] === 'mail_fail' ? 'red' : ($ev['action'] === 'submit' || $ev['action'] === 'finalize' ? 'emerald' : 'gray')) ?></td>
            <td class="text-slate-700 leading-6 min-w-[16rem]"><?= e($ev['message']) ?></td>
            <td class="whitespace-nowrap text-xs">
              <?php if ($ev['proposal_id'] && $ev['proposal_code']): ?>
                <a class="link num ltr" href="<?= e(url('/admin/proposals/' . $ev['proposal_id'])) ?>" title="<?= e($ev['proposal_title']) ?>"><?= e($ev['proposal_code']) ?></a>
              <?php else: ?><span class="text-slate-300">—</span><?php endif; ?>
            </td>
            <td class="whitespace-nowrap text-xs">
              <?= badge($actor[0], $actor[1]) ?>
              <?php if ($ev['actor_name']): ?><span class="text-slate-500 ms-1"><?= e($ev['actor_name']) ?></span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?= View::partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'path' => '/admin/activity', 'query' => ['action' => $action]]) ?>
