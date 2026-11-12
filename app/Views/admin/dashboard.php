<?php
/**
 * @var array $counts
 * @var array $summary
 * @var array $todo
 * @var int $todoTotal
 * @var array $waiting
 * @var array $latest
 * @var array $activity
 * @var bool $mailReady
 * @var int $judgeCount
 */
use Fava\Core\View;
use Fava\Domain\Activity;
use Fava\Domain\Labels;

$stages = Labels::PROPOSAL;
$stageIcons = ['review' => 'pencil', 'ready' => 'send', 'judging' => 'scale', 'completed' => 'flag', 'finalized' => 'check-circle'];
$tone = [
    'amber' => 'bg-amber-50 text-amber-700', 'sky' => 'bg-sky-50 text-sky-700', 'indigo' => 'bg-indigo-50 text-indigo-700',
    'violet' => 'bg-violet-50 text-violet-700', 'emerald' => 'bg-emerald-50 text-emerald-700',
];
$todoText = [
    'review' => ['بررسی و تأیید اطلاعات استخراج‌شده', 'amber', 'بررسی اطلاعات'],
    'send' => ['تعیین داوران و ارسال پیوند', 'sky', 'ارسال برای داوران'],
    'resolve' => ['نظر داوران یکسان نیست؛ سطح یا مبلغ را مشخص کنید', 'red', 'رفع اختلاف'],
    'finalize' => ['هر دو داوری کامل است؛ تصمیم نهایی را ثبت کنید', 'violet', 'ثبت تصمیم نهایی'],
];
$decided = array_sum($summary['decisions']);
?>
<div class="flex items-end justify-between gap-4 flex-wrap mb-6">
  <div>
    <h1 class="text-2xl font-extrabold text-slate-900">داشبورد</h1>
    <p class="text-sm text-slate-500 mt-1"><?= e(jdate(time(), 'l j F Y')) ?> — نمای کلی فرایند ارزش‌گذاری طرح‌ها</p>
  </div>
  <div class="flex items-center gap-2">
    <a href="<?= e(url('/admin/proposals')) ?>" class="btn btn-secondary"><?= icon('doc', 'w-4 h-4') ?> همه طرح‌ها</a>
    <a href="#upload" class="btn btn-primary"><?= icon('upload', 'w-4 h-4') ?> بارگذاری طرح جدید</a>
  </div>
</div>

<?php if (!$mailReady || $judgeCount === 0): ?>
<div class="grid md:grid-cols-2 gap-3 mb-6">
  <?php if (!$mailReady): ?>
    <div class="alert alert-warning">
      <span class="shrink-0"><?= icon('mail') ?></span>
      <div>ارسال ایمیل هنوز پیکربندی نشده است. تا آن زمان می‌توانید پیوند هر داور را کپی و خودتان بفرستید.
        <a class="link ms-1" href="<?= e(url('/admin/settings', ['tab' => 'mail'])) ?>">تنظیم ایمیل</a></div>
    </div>
  <?php endif; ?>
  <?php if ($judgeCount === 0): ?>
    <div class="alert alert-info">
      <span class="shrink-0"><?= icon('users') ?></span>
      <div>هنوز داوری در فهرست داوران ثبت نشده است. می‌توانید هنگام تعیین داور هر طرح، داور تازه را همان‌جا وارد کنید.
        <a class="link ms-1" href="<?= e(url('/admin/judges')) ?>">فهرست داوران</a></div>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- pipeline -->
<section class="mb-6" aria-label="مراحل فرایند">
  <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
    <?php $i = 0; foreach ($stages as $key => $st): $i++; ?>
      <a href="<?= e(url('/admin/proposals', ['status' => $key])) ?>" class="stat relative" title="<?= e($st[2]) ?>">
        <div class="flex items-center justify-between">
          <span class="w-9 h-9 rounded-xl flex items-center justify-center <?= e($tone[$st[1]]) ?>"><?= icon($stageIcons[$key], 'w-5 h-5') ?></span>
          <span class="text-[11px] font-bold text-slate-300 num">مرحله <?= fa((string) $i) ?></span>
        </div>
        <div class="stat-value mt-2"><?= fa((string) $counts[$key]) ?></div>
        <div class="stat-label"><?= e($st[0]) ?></div>
      </a>
    <?php endforeach; ?>
  </div>
  <p class="text-xs text-slate-400 mt-2">مجموع طرح‌های ثبت‌شده: <span class="font-bold text-slate-600"><?= fa((string) $counts['total']) ?></span> — روی هر مرحله کلیک کنید تا طرح‌های آن را ببینید.</p>
</section>

<div class="grid lg:grid-cols-3 gap-6">
  <!-- to do -->
  <section class="card lg:col-span-2">
    <div class="card-head">
      <h2 class="card-title flex items-center gap-2"><?= icon('flag', 'w-5 h-5 text-brand-700') ?> نیازمند اقدام شما</h2>
      <?php if ($todoTotal > 0): ?><span class="badge badge-blue"><?= fa((string) $todoTotal) ?> مورد</span><?php endif; ?>
    </div>
    <?php if (!$todo): ?>
      <div class="card-body text-center text-sm text-slate-500 py-10">
        <div class="mx-auto w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center mb-3"><?= icon('check-circle', 'w-7 h-7') ?></div>
        <?= $counts['total'] === 0 ? 'هنوز طرحی بارگذاری نشده است. با بارگذاری نخستین کاربرگ شروع کنید.' : 'کاری در انتظار شما نیست.' ?>
      </div>
    <?php else: ?>
      <ul class="divide-y divide-slate-100">
        <?php foreach ($todo as $t): $p = $t['p']; $tt = $todoText[$t['kind']]; ?>
          <li class="px-5 py-3.5 flex items-center gap-4 flex-wrap">
            <div class="min-w-0 flex-1">
              <a href="<?= e(url('/admin/proposals/' . $p['id'])) ?>" class="font-semibold text-slate-900 hover:text-brand-800 block truncate"><?= e($p['title'] ?: 'بدون عنوان') ?></a>
              <div class="text-xs text-slate-500 mt-0.5 flex items-center gap-2 flex-wrap">
                <span class="num ltr"><?= e($p['code']) ?></span><span>•</span><span class="truncate"><?= e($p['company'] ?: '—') ?></span>
                <span>•</span><span><?= e($tt[0]) ?></span>
              </div>
            </div>
            <a href="<?= e(url('/admin/proposals/' . $p['id'])) ?>" class="btn btn-soft btn-sm shrink-0"><?= e($tt[2]) ?></a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <!-- waiting for judges -->
  <section class="card">
    <div class="card-head">
      <h2 class="card-title flex items-center gap-2"><?= icon('clock', 'w-5 h-5 text-brand-700') ?> در انتظار داوران</h2>
    </div>
    <?php if (!$waiting): ?>
      <div class="card-body text-sm text-slate-500 text-center py-10">فرم ارسال‌شده‌ای در انتظار پاسخ نیست.</div>
    <?php else: ?>
      <ul class="divide-y divide-slate-100">
        <?php foreach ($waiting as $w): ?>
          <li class="px-5 py-3">
            <div class="flex items-center justify-between gap-2">
              <span class="font-semibold text-sm text-slate-800 truncate"><?= e($w['judge_name']) ?></span>
              <?= assignment_badge($w['status']) ?>
            </div>
            <a href="<?= e(url('/admin/proposals/' . $w['proposal_id'])) ?>#judges" class="text-xs text-slate-500 hover:text-brand-700 block truncate mt-1"><?= e(role_label($w['role'])) ?> — <?= e($w['title']) ?></a>
            <div class="text-[11px] text-slate-400 mt-0.5">ارسال: <?= e(ago($w['sent_at'])) ?><?php if ($w['due_at']): ?> • مهلت: <?= e(jdate($w['due_at'], 'j F')) ?><?php endif; ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>

<div class="grid lg:grid-cols-3 gap-6 mt-6">
  <!-- latest proposals -->
  <section class="card lg:col-span-2 overflow-hidden">
    <div class="card-head">
      <h2 class="card-title">آخرین طرح‌ها</h2>
      <a class="link text-sm" href="<?= e(url('/admin/proposals')) ?>">مشاهده همه</a>
    </div>
    <?php if (!$latest): ?>
      <div class="card-body text-sm text-slate-500 text-center py-10">طرحی ثبت نشده است.</div>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="tbl">
          <thead><tr><th>طرح</th><th>وضعیت</th><th>داور فنی</th><th>داور کسب‌وکاری</th><th>امتیاز</th></tr></thead>
          <tbody>
          <?php foreach ($latest as $p): ?>
            <tr>
              <td class="max-w-[18rem]">
                <a href="<?= e(url('/admin/proposals/' . $p['id'])) ?>" class="font-semibold text-slate-900 hover:text-brand-800 block truncate"><?= e($p['title'] ?: 'بدون عنوان') ?></a>
                <span class="text-xs text-slate-500 block truncate"><?= e($p['company'] ?: '—') ?></span>
              </td>
              <td><?= proposal_badge($p['status']) ?></td>
              <td><?= assignment_badge($p['tech']['status'] ?? null) ?></td>
              <td><?= assignment_badge($p['biz']['status'] ?? null) ?></td>
              <td class="num font-bold"><?= $p['total_score'] !== null ? nf($p['total_score'], 1) : '<span class="text-slate-300">—</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <div class="space-y-6">
    <!-- outcome summary -->
    <section class="card">
      <div class="card-head"><h2 class="card-title flex items-center gap-2"><?= icon('chart', 'w-5 h-5 text-brand-700') ?> نتایج داوری</h2></div>
      <div class="card-body space-y-4">
        <?php if ($decided === 0): ?>
          <p class="text-sm text-slate-500">پس از تکمیل نخستین داوری، خلاصه نتایج این‌جا نمایش داده می‌شود.</p>
        <?php else: ?>
          <?php
          $rows = [['approve', 'تأیید', 'bg-emerald-500'], ['conditional', 'تأیید مشروط', 'bg-amber-400'], ['reject', 'رد', 'bg-red-500']];
          foreach ($rows as $r): $n = $summary['decisions'][$r[0]]; ?>
            <div>
              <div class="flex justify-between text-xs mb-1"><span class="font-semibold text-slate-700"><?= e($r[1]) ?></span><span class="num text-slate-500"><?= fa((string) $n) ?> طرح</span></div>
              <div class="bar"><span class="<?= e($r[2]) ?>" style="width:<?= (int) round($n / max(1, $decided) * 100) ?>%"></span></div>
            </div>
          <?php endforeach; ?>
          <div class="grid grid-cols-2 gap-3 pt-2">
            <div class="rounded-xl bg-slate-50 p-3 text-center">
              <div class="text-lg font-extrabold num text-slate-900"><?= nf($summary['avg_score'], 1) ?></div>
              <div class="text-[11px] text-slate-500">میانگین امتیاز</div>
            </div>
            <div class="rounded-xl bg-slate-50 p-3 text-center">
              <div class="text-lg font-extrabold num text-slate-900"><?= nf($summary['final_amount']) ?></div>
              <div class="text-[11px] text-slate-500">حمایت نهایی‌شده (میلیارد ریال)</div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <!-- recent activity -->
    <section class="card">
      <div class="card-head">
        <h2 class="card-title">آخرین رویدادها</h2>
        <a class="link text-sm" href="<?= e(url('/admin/activity')) ?>">همه</a>
      </div>
      <?php if (!$activity): ?>
        <div class="card-body text-sm text-slate-500">رویدادی ثبت نشده است.</div>
      <?php else: ?>
        <ul class="divide-y divide-slate-100 text-sm">
          <?php foreach ($activity as $ev): ?>
            <li class="px-5 py-2.5">
              <div class="text-slate-700 leading-6"><?= e(str_limit($ev['message'], 110)) ?></div>
              <div class="text-[11px] text-slate-400 flex items-center gap-1.5 flex-wrap">
                <span><?= e(ago($ev['created_at'])) ?></span>
                <?php if ($ev['proposal_id'] && $ev['proposal_code']): ?>
                  <span>•</span><a class="hover:text-brand-700 num ltr" href="<?= e(url('/admin/proposals/' . $ev['proposal_id'])) ?>"><?= e($ev['proposal_code']) ?></a>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </div>
</div>

<section id="upload" class="mt-6 scroll-mt-6">
  <h2 class="card-title mb-3">بارگذاری طرح جدید</h2>
  <?= View::partial('partials/upload', ['compact' => false]) ?>
  <p class="text-xs text-slate-500 mt-2 leading-6">پس از بارگذاری، سامانه نام شرکت، عنوان طرح، خروجی مورد انتظار و سایر اطلاعات را از «کاربرگ درخواست اعتبار حمایتی» استخراج می‌کند و شما پیش از ارسال برای داوران آن را بررسی و تأیید می‌کنید.</p>
</section>
