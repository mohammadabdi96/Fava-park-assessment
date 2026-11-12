<?php
/**
 * @var array $rows
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var array $filter
 * @var array $counts
 */
use Fava\Core\View;
use Fava\Domain\Labels;

$tabs = ['' => ['همه', $counts['total']]];
foreach (Labels::PROPOSAL as $k => $st) {
    $tabs[$k] = [$st[0], $counts[$k]];
}
$filtered = $filter['q'] !== '' || $filter['status'] !== '' || $filter['decision'] !== '';
?>
<div class="flex items-end justify-between gap-4 flex-wrap mb-5">
  <div>
    <h1 class="text-2xl font-extrabold text-slate-900">طرح‌ها</h1>
    <p class="text-sm text-slate-500 mt-1">همه طرح‌های بارگذاری‌شده و وضعیت داوری هر کدام</p>
  </div>
  <div class="flex items-center gap-2 flex-wrap">
    <details class="relative">
      <summary class="btn btn-secondary"><?= icon('download', 'w-4 h-4') ?> خروجی اکسل</summary>
      <div class="absolute end-0 mt-2 w-64 card p-2 z-20 text-sm">
        <a class="block rounded-lg px-3 py-2 hover:bg-slate-50" href="<?= e(url('/admin/export/proposals')) ?>">فهرست طرح‌ها و نتایج <span class="block text-xs text-slate-400">هر طرح یک سطر (CSV)</span></a>
        <a class="block rounded-lg px-3 py-2 hover:bg-slate-50" href="<?= e(url('/admin/export/scores')) ?>">ریز امتیاز داوران <span class="block text-xs text-slate-400">هر فرم ثبت‌شده یک سطر (CSV)</span></a>
      </div>
    </details>
    <button type="button" class="btn btn-primary" data-toggle="#upload-box"><?= icon('upload', 'w-4 h-4') ?> بارگذاری طرح جدید</button>
  </div>
</div>

<div id="upload-box" class="mb-5" <?= $counts['total'] > 0 ? 'hidden' : '' ?>>
  <?= View::partial('partials/upload', ['compact' => $counts['total'] > 0]) ?>
</div>

<div class="card overflow-hidden">
  <div class="px-4 pt-3 border-b border-slate-100">
    <div class="flex items-center gap-1 overflow-x-auto text-sm -mb-px">
      <?php foreach ($tabs as $key => $t): $active = $filter['status'] === $key; ?>
        <a href="<?= e(url('/admin/proposals', array_filter(['status' => $key, 'q' => $filter['q']], 'strlen'))) ?>"
           class="px-3 py-2.5 border-b-2 whitespace-nowrap font-medium <?= $active ? 'border-brand-700 text-brand-800' : 'border-transparent text-slate-500 hover:text-slate-800' ?>">
          <?= e($t[0]) ?> <span class="num text-xs <?= $active ? 'text-brand-600' : 'text-slate-400' ?>">(<?= fa((string) $t[1]) ?>)</span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <form method="get" action="<?= e(url('/admin/proposals')) ?>" class="p-4 flex items-center gap-2 flex-wrap border-b border-slate-100">
    <?= route_field('/admin/proposals') ?>
    <?php if ($filter['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($filter['status']) ?>"><?php endif; ?>
    <div class="relative flex-1 min-w-[14rem]">
      <span class="absolute inset-y-0 start-3 flex items-center text-slate-400"><?= icon('search', 'w-4 h-4') ?></span>
      <input class="input ps-9" type="search" name="q" value="<?= e($filter['q']) ?>" placeholder="جست‌وجو در عنوان، شرکت، کد یا نام فایل…">
    </div>
    <select class="input w-auto" name="decision" aria-label="نتیجه">
      <option value="">همه نتیجه‌ها</option>
      <option value="approve" <?= $filter['decision'] === 'approve' ? 'selected' : '' ?>>تأیید</option>
      <option value="conditional" <?= $filter['decision'] === 'conditional' ? 'selected' : '' ?>>تأیید مشروط</option>
      <option value="reject" <?= $filter['decision'] === 'reject' ? 'selected' : '' ?>>رد</option>
    </select>
    <button class="btn btn-secondary" type="submit">اعمال</button>
    <?php if ($filtered): ?><a class="btn btn-ghost" href="<?= e(url('/admin/proposals')) ?>">حذف فیلتر</a><?php endif; ?>
  </form>

  <?php if (!$rows): ?>
    <div class="p-10 text-center text-sm text-slate-500">
      <?= $filtered ? 'طرحی با این مشخصات پیدا نشد.' : 'هنوز طرحی بارگذاری نشده است. فایل Word کاربرگ را در کادر بالا رها کنید.' ?>
    </div>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="tbl">
        <thead>
          <tr>
            <th>کد</th><th>طرح</th><th>وضعیت</th><th>داور فنی</th><th>داور کسب‌وکاری</th><th>امتیاز</th><th>نتیجه</th><th>آخرین تغییر</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $p):
            $decision = $p['final_decision'] ?: $p['system_decision']; ?>
          <tr>
            <td class="num ltr text-xs text-slate-500 whitespace-nowrap"><?= e($p['code']) ?></td>
            <td class="w-full max-w-0 min-w-[14rem]">
              <a href="<?= e(url('/admin/proposals/' . $p['id'])) ?>" class="font-semibold text-slate-900 hover:text-brand-800 block truncate" title="<?= e($p['title']) ?>"><?= e($p['title'] ?: 'بدون عنوان') ?></a>
              <span class="text-xs text-slate-500 block truncate"><?= e($p['company'] ?: '—') ?></span>
            </td>
            <td>
              <?= proposal_badge($p['status']) ?>
              <?php if ($p['status'] === 'completed' && $p['needs_resolution']): ?><span class="block text-[11px] text-red-600 mt-1">نیازمند رفع اختلاف</span><?php endif; ?>
              <?php if ($p['status'] === 'review' && $p['extract_status'] !== 'ok'): ?><span class="block text-[11px] text-amber-600 mt-1"><?= e(Labels::EXTRACT[$p['extract_status']][0] ?? '') ?></span><?php endif; ?>
            </td>
            <?php foreach (['tech', 'biz'] as $role): $a = $p[$role]; ?>
              <td>
                <?php if ($a): ?>
                  <span class="block text-xs text-slate-700 truncate max-w-[9rem] mb-1"><?= e($a['judge_name']) ?></span>
                <?php endif; ?>
                <?= assignment_badge($a['status'] ?? null) ?>
              </td>
            <?php endforeach; ?>
            <td class="num font-bold whitespace-nowrap"><?= $p['total_score'] !== null ? nf($p['total_score'], 1) : '<span class="text-slate-300 font-normal">—</span>' ?></td>
            <td class="whitespace-nowrap">
              <?= decision_badge($decision) ?>
              <?php if ($decision): ?><span class="block text-[11px] text-slate-400 mt-1"><?= $p['final_decision'] ? 'تصمیم نهایی' : 'پیشنهاد سامانه' ?></span><?php endif; ?>
            </td>
            <td class="text-xs text-slate-500 whitespace-nowrap"><?= e(ago($p['updated_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?= View::partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'path' => '/admin/proposals', 'query' => $filter]) ?>
<?php if ($total > 0): ?><p class="text-center text-xs text-slate-400 mt-3"><?= fa((string) $total) ?> طرح</p><?php endif; ?>
