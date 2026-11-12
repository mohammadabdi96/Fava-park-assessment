<?php
/**
 * One proposal: review of extracted data, judges and links, result, final decision, history.
 *
 * @var array $p
 * @var array $fields
 * @var array $warnings
 * @var array $as        ['tech' => ?assignment, 'biz' => ?assignment]
 * @var array $links
 * @var array $answers
 * @var array $cfg
 * @var array $def
 * @var array|null $result
 * @var array $judges
 * @var array $activity
 * @var bool $mailReady
 * @var array $mails
 * @var string $docHtml
 * @var bool $configChanged
 */
use Fava\Core\View;
use Fava\Domain\Activity;
use Fava\Domain\Labels;

$id = (int) $p['id'];
$status = (string) $p['status'];
$stepIndex = Labels::stepIndex($status);
$reviewed = !empty($p['reviewed_at']);
$finalized = !empty($p['finalized_at']);

// what should the admin do next?
$missing = [];
foreach (['tech', 'biz'] as $role) {
    if (!$as[$role] || $as[$role]['status'] !== 'submitted') {
        $missing[] = role_label($role);
    }
}
if ($status === 'review') {
    $next = ['اطلاعات استخراج‌شده از فایل را بررسی کنید، در صورت نیاز اصلاح کنید و سپس «تأیید اطلاعات» را بزنید.', '#info'];
} elseif ($status === 'ready') {
    $next = [(!$as['tech'] || !$as['biz']) ? 'داور فنی و داور کسب‌وکاری را تعیین کنید و پیوند اختصاصی هر کدام را بفرستید.' : 'پیوند اختصاصی را برای داوران ایمیل کنید یا آن را کپی و خودتان بفرستید.', '#judges'];
} elseif ($status === 'judging') {
    $next = ['در انتظار ثبت فرم: ' . implode(' و ', $missing) . '.' . ((!$as['tech'] || !$as['biz']) ? ' هنوز برای یکی از نقش‌ها داور تعیین نشده است.' : ''), '#judges'];
} elseif ($status === 'completed') {
    $next = $result && $result['needs_resolution']
        ? ['نظر دو داور درباره سطح طرح یا مبلغ درخواستی یکسان نیست. آن را مشخص کنید تا مبلغ قطعی محاسبه شود.', '#resolve']
        : ['هر دو داوری کامل است. نتیجه را بررسی و تصمیم نهایی را ثبت کنید.', '#final'];
} else {
    $next = ['تصمیم نهایی این طرح ثبت شده است.', '#final'];
}
$actors = ['admin' => 'مدیر', 'judge' => 'داور', 'system' => 'سامانه'];
?>
<nav class="text-xs text-slate-500 mb-3 no-print">
  <a class="hover:text-brand-700" href="<?= e(url('/admin/proposals')) ?>">طرح‌ها</a>
  <span class="mx-1">/</span><span class="num ltr"><?= e($p['code']) ?></span>
</nav>

<!-- header -->
<header class="card p-5 mb-6">
  <div class="flex items-start justify-between gap-4 flex-wrap">
    <div class="min-w-0 flex-1">
      <div class="flex items-center gap-2 flex-wrap mb-2">
        <span class="badge badge-gray num ltr"><?= e($p['code']) ?></span>
        <?= proposal_badge($status) ?>
        <?php if ($p['extract_status'] !== 'ok'): ?><?= badge(Labels::EXTRACT[$p['extract_status']][0] ?? '', Labels::EXTRACT[$p['extract_status']][1] ?? 'gray') ?><?php endif; ?>
      </div>
      <h1 class="text-xl font-extrabold text-slate-900 leading-9" dir="auto"><?= e($p['title'] ?: 'بدون عنوان') ?></h1>
      <p class="text-sm text-slate-600 mt-1" dir="auto"><?= e($p['company'] ?: 'نام شرکت مشخص نیست') ?></p>
    </div>
    <div class="flex items-center gap-2 flex-wrap no-print">
      <a class="btn btn-secondary btn-sm" href="<?= e(url('/admin/proposals/' . $id . '/report')) ?>"><?= icon('print', 'w-4 h-4') ?> گزارش قابل چاپ</a>
      <a class="btn btn-secondary btn-sm" href="<?= e(url('/admin/proposals/' . $id . '/file')) ?>"><?= icon('download', 'w-4 h-4') ?> فایل Word</a>
      <form method="post" action="<?= e(url('/admin/proposals/' . $id . '/delete')) ?>" data-confirm="این طرح به همراه فایل، پیوند داوران و فرم‌های ثبت‌شده برای همیشه حذف می‌شود. ادامه می‌دهید؟">
        <?= csrf_field() ?>
        <button class="btn btn-danger btn-sm" type="submit"><?= icon('trash', 'w-4 h-4') ?> حذف</button>
      </form>
    </div>
  </div>

  <div class="steps mt-6" aria-label="مراحل فرایند">
    <?php $i = 0; foreach (Labels::PROPOSAL as $key => $st):
        $cls = $i < $stepIndex || $status === 'finalized' ? 'done' : ($i === $stepIndex ? 'current' : ''); ?>
      <div class="step <?= e($cls) ?>">
        <span class="step-dot"><?= $cls === 'done' ? icon('check', 'w-4 h-4') : fa((string) ($i + 1)) ?></span>
        <span class="step-label"><?= e($st[0]) ?></span>
      </div>
    <?php $i++; endforeach; ?>
  </div>

  <a href="<?= e($next[1]) ?>" class="mt-5 flex items-center gap-3 rounded-xl px-4 py-3 text-sm <?= $finalized ? 'bg-emerald-50 text-emerald-900 border border-emerald-200' : 'bg-brand-50 text-brand-900 border border-brand-100 hover:bg-brand-100' ?> no-print">
    <span class="shrink-0"><?= icon($finalized ? 'check-circle' : 'flag') ?></span>
    <span><b><?= $finalized ? 'پایان فرایند:' : 'گام بعدی:' ?></b> <?= e($next[0]) ?></span>
  </a>
</header>

<?= View::partial('admin/proposal_info', compact('p', 'fields', 'warnings', 'docHtml', 'reviewed', 'finalized', 'id', 'status')) ?>

<?= View::partial('admin/proposal_judges', compact('p', 'as', 'links', 'judges', 'mailReady', 'mails', 'reviewed', 'finalized', 'id')) ?>

<?= View::partial('admin/proposal_result', compact('p', 'as', 'answers', 'cfg', 'def', 'result', 'finalized', 'id', 'missing', 'configChanged')) ?>

<!-- history -->
<section id="log" class="card mt-6 scroll-mt-6">
  <div class="card-head"><h2 class="card-title flex items-center gap-2"><?= icon('clock', 'w-5 h-5 text-brand-700') ?> تاریخچه این طرح</h2></div>
  <?php if (!$activity): ?>
    <div class="card-body text-sm text-slate-500">رویدادی ثبت نشده است.</div>
  <?php else: ?>
    <ol class="card-body tl">
      <?php foreach ($activity as $ev): ?>
        <li class="tl-item on">
          <div class="text-sm text-slate-700 leading-6"><?= e($ev['message']) ?></div>
          <div class="text-[11px] text-slate-400 mt-0.5">
            <span class="num"><?= e(jdate($ev['created_at'], 'Y/m/d H:i')) ?></span>
            <span class="mx-1">•</span><?= e(Activity::label($ev['action'])) ?>
            <span class="mx-1">•</span><?= e($actors[$ev['actor_type']] ?? $ev['actor_type']) ?><?= $ev['actor_name'] ? ' (' . e($ev['actor_name']) . ')' : '' ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>
</section>
