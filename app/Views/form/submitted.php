<?php
/**
 * What the judge sees after the final submission (and whenever the link is opened again).
 *
 * @var array $p
 * @var array $a
 * @var string $role
 * @var array $def
 * @var array $cfg
 * @var array $answers
 * @var array $metrics
 * @var float|null $fixedAmount
 * @var bool $justSent
 * @var string $support
 * @var string $org
 */
use Fava\Core\View;
use Fava\Domain\FormDef;

$est = $metrics['estimated_decision'] ?? null;
?>
<header class="bg-gradient-to-l from-blue-900 to-blue-700 text-white py-5 px-4 shadow-lg no-print">
  <div class="max-w-4xl mx-auto flex items-center justify-between gap-4 flex-wrap">
    <div>
      <h1 class="text-lg md:text-xl font-bold leading-relaxed">کاربرگ ارزش‌گذاری استراتژیک طرح</h1>
      <p class="mt-1 text-blue-100 text-xs md:text-sm"><?= e($org) ?> | <?= e(role_label($role)) ?></p>
    </div>
    <button type="button" class="btn bg-white/10 hover:bg-white/20 text-white border border-white/20" onclick="window.print()"><?= icon('print', 'w-4 h-4') ?> چاپ / ذخیره PDF</button>
  </div>
</header>

<main class="max-w-4xl mx-auto px-4 py-6 space-y-5">
  <section class="bg-white rounded-2xl p-6 shadow-sm border border-emerald-200 text-center no-print">
    <div class="mx-auto w-16 h-16 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center mb-3"><?= icon('check', 'w-9 h-9') ?></div>
    <h2 class="text-xl font-extrabold text-emerald-800"><?= $justSent ? 'فرم شما با موفقیت ثبت شد' : 'این فرم ثبت نهایی شده است' ?></h2>
    <p class="text-sm text-gray-600 mt-2 leading-7">
      <?= e($answers['name'] ?: $a['judge_name']) ?> گرامی، از وقتی که برای ارزیابی این طرح گذاشتید سپاسگزاریم.
      فرم شما در تاریخ <b class="num"><?= e(jdate($a['submitted_at'])) ?></b> به دبیرخانه رسید و دیگر قابل ویرایش نیست.
    </p>
    <p class="text-xs text-gray-500 mt-2 leading-6">اگر نیاز به اصلاح دارید، از دبیرخانه بخواهید فرم را برای شما دوباره باز کند؛ همین پیوند دوباره فعال می‌شود.<?php if ($support !== ''): ?><br>راه ارتباط با دبیرخانه: <b dir="auto"><?= e($support) ?></b><?php endif; ?></p>
  </section>

  <section class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
    <div class="flex items-center justify-between gap-2 flex-wrap mb-3">
      <h2 class="section-title">مشخصات طرح <span class="text-xs font-normal text-gray-400 num ltr ms-1"><?= e($p['code']) ?></span></h2>
      <span class="badge badge-emerald">ثبت‌شده توسط <?= e(role_label($role)) ?></span>
    </div>
    <table class="kv">
      <tr><th>عنوان طرح</th><td dir="auto" class="font-semibold"><?= e($p['title'] ?: '—') ?></td></tr>
      <tr><th>شرکت / تیم</th><td dir="auto"><?= e($p['company'] ?: '—') ?></td></tr>
      <?php if ($fixedAmount !== null): ?><tr><th>مبلغ درخواستی شرکت</th><td class="num"><?= e(money($fixedAmount)) ?></td></tr><?php endif; ?>
    </table>
  </section>

  <section class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
    <h2 class="section-title mb-3">خلاصه ارزیابی شما</h2>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">
      <div class="bg-blue-50 rounded-xl p-3 border border-blue-200 text-center">
        <div class="text-xs text-gray-600">سهم شما از امتیاز کل</div>
        <div class="text-xl font-bold text-blue-800 mt-1 num"><?= nf($metrics['share'] ?? null, 1) ?> <span class="text-xs font-normal text-gray-500">از <?= nf($metrics['share_max'] ?? null) ?></span></div>
      </div>
      <div class="bg-gray-50 rounded-xl p-3 border border-gray-200 text-center">
        <div class="text-xs text-gray-600">سطح طرح از نگاه شما</div>
        <div class="text-sm font-bold text-gray-800 mt-2"><?= e(!empty($metrics['tier']) ? $cfg['tiers'][$metrics['tier']]['label'] : '—') ?></div>
      </div>
      <div class="rounded-xl p-3 border text-center <?= $est === 'approve' ? 'share-card-supportive' : ($est === 'conditional' ? 'share-card-caution' : 'share-card-negative') ?>">
        <div class="text-xs text-gray-600">ارزیابی تخمینی سیستم</div>
        <div class="text-sm font-bold text-gray-800 mt-2"><?= e(FormDef::decision($est)['title']) ?></div>
      </div>
    </div>
    <p class="text-xs text-gray-500 mb-4 leading-6">تصمیم و مبلغ نهایی پس از تجمیع فرم شما با فرم داور دیگر و با نظر دبیرخانه تعیین می‌شود.</p>
    <?= View::partial('partials/answers', ['role' => $role, 'answers' => $answers, 'def' => $def, 'cfg' => $cfg, 'metrics' => $metrics]) ?>
  </section>

  <footer class="text-center text-gray-400 text-xs py-4"><?= e($org) ?> — کاربرگ ارزش‌گذاری استراتژیک</footer>
</main>
<script>
/* the copy kept in this browser is no longer needed, and «back» must not return to the form */
try {
  localStorage.removeItem(<?= json_out('fava-form-' . substr(hash('sha256', (string) $a['token']), 0, 16)) ?>);
  if (/[?&]done=1/.test(location.search)) {
    history.replaceState(null, '', location.href.replace(/([?&])done=1(&|$)/, function (m, a, b) { return b ? a : ''; }));
  }
} catch (e) {}
</script>
