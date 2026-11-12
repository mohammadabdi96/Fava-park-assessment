<?php
/**
 * Printable assessment report of one proposal (A4).
 *
 * @var array $p
 * @var array $fields
 * @var array $as
 * @var array $answers
 * @var array $cfg
 * @var array $def
 * @var array|null $result
 * @var string $org
 */
use Fava\Core\View;
use Fava\Domain\FormDef;
use Fava\Domain\Labels;

$finalized = !empty($p['finalized_at']);
$status = Labels::proposal((string) $p['status']);
$tierName = function (?string $t) use ($cfg) {
    return $t && isset($cfg['tiers'][$t]) ? $cfg['tiers'][$t]['label'] : '—';
};
$tone = [
    'approve' => 'border-emerald-400 bg-emerald-50 text-emerald-900',
    'conditional' => 'border-amber-400 bg-amber-50 text-amber-900',
    'reject' => 'border-red-400 bg-red-50 text-red-900',
];
$facts = $fields['company_facts'] ?? [];
?>
<header class="flex items-start justify-between gap-4 border-b-2 border-brand-800 pb-4">
  <div class="flex items-center gap-3">
    <span class="text-brand-800"><?= View::partial('partials/logo', ['class' => 'w-12 h-12']) ?></span>
    <div>
      <div class="text-lg font-extrabold text-brand-900"><?= e($org) ?></div>
      <div class="text-sm text-slate-600">گزارش ارزش‌گذاری استراتژیک طرح</div>
    </div>
  </div>
  <div class="text-xs text-slate-600 text-end leading-6">
    <div>کد پیگیری: <b class="num ltr"><?= e($p['code']) ?></b></div>
    <div>تاریخ گزارش: <b class="num"><?= e(jdate(time(), 'Y/m/d')) ?></b></div>
    <div>وضعیت: <b><?= e($status[0]) ?></b></div>
  </div>
</header>

<section class="mt-5">
  <h2 class="section-title mb-2">مشخصات طرح</h2>
  <table class="kv">
    <tr><th>عنوان طرح</th><td class="font-bold" dir="auto"><?= e($p['title'] ?: '—') ?></td></tr>
    <tr><th>شرکت / تیم</th><td dir="auto"><?= e($p['company'] ?: '—') ?></td></tr>
    <tr><th>خروجی اصلی مورد انتظار</th><td dir="auto"><?= $p['expected_output'] ? nl2p($p['expected_output']) : '—' ?></td></tr>
    <?php if (!empty($fields['ceo_name'])): ?><tr><th>مدیرعامل</th><td><?= e($fields['ceo_name']) ?></td></tr><?php endif; ?>
    <?php foreach ($facts as $fact): if (!in_array($fact['key'], ['national_id', 'kb_status', 'tech_center'], true)) { continue; } ?>
      <tr><th><?= e($fact['label']) ?></th><td dir="auto"><?= e(fa((string) $fact['value'])) ?></td></tr>
    <?php endforeach; ?>
    <tr><th>مبلغ درخواستی شرکت</th><td class="num"><?= $result ? e(money($result['requested'])) : ($p['requested_amount'] !== null ? e(money($p['requested_amount'])) : '—') ?></td></tr>
    <tr><th>داور فنی</th><td><?= e($as['tech']['judge_name'] ?? '—') ?><?php if ($as['tech'] && $as['tech']['submitted_at']): ?> <span class="text-slate-500 text-xs num">— ثبت فرم: <?= e(jdate($as['tech']['submitted_at'])) ?></span><?php endif; ?></td></tr>
    <tr><th>داور کسب‌وکاری</th><td><?= e($as['biz']['judge_name'] ?? '—') ?><?php if ($as['biz'] && $as['biz']['submitted_at']): ?> <span class="text-slate-500 text-xs num">— ثبت فرم: <?= e(jdate($as['biz']['submitted_at'])) ?></span><?php endif; ?></td></tr>
  </table>
</section>

<?php if (!$result): ?>
  <section class="mt-6">
    <div class="alert alert-warning">
      <div>داوری این طرح هنوز کامل نشده است؛ نتیجه پس از ثبت فرم هر دو داور محاسبه می‌شود.
        <?php foreach (['tech', 'biz'] as $role): ?>
          <div class="mt-1"><?= e(role_label($role)) ?>: <b><?= e(Labels::assignment($as[$role]['status'] ?? null)[0]) ?></b></div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
<?php else:
    $sys = FormDef::decision($result['decision']);
    $shownDecision = $finalized ? (string) $p['final_decision'] : (string) $result['decision'];
    $shownAmount = $finalized ? (float) $p['final_amount'] : (float) $result['amount'];
    $dd = FormDef::decision($shownDecision); ?>

  <section class="mt-6 break-inside-avoid">
    <h2 class="section-title mb-3"><?= $finalized ? 'تصمیم نهایی' : 'نتیجه محاسبه سامانه (تصمیم نهایی هنوز ثبت نشده است)' ?></h2>
    <div class="rounded-2xl border-2 p-4 <?= e($tone[$shownDecision] ?? 'border-slate-300') ?>">
      <div class="grid grid-cols-3 gap-4 text-center">
        <div>
          <div class="text-xs opacity-80">امتیاز کل از ۱۰۰</div>
          <div class="text-3xl font-black num mt-1"><?= nf($result['total'], 1) ?></div>
        </div>
        <div>
          <div class="text-xs opacity-80"><?= $finalized ? 'تصمیم نهایی' : 'پیشنهاد سامانه' ?></div>
          <div class="text-2xl font-black mt-1"><?= e($dd['label']) ?></div>
        </div>
        <div>
          <div class="text-xs opacity-80"><?= $finalized ? 'مبلغ حمایتی مصوب' : 'مبلغ حمایتی محاسبه‌شده' ?></div>
          <div class="text-2xl font-black num mt-1"><?= e(money($shownAmount)) ?></div>
          <?php if ($shownAmount > 0): ?><div class="text-xs opacity-80"><?= e(toman_hint($shownAmount)) ?></div><?php endif; ?>
        </div>
      </div>
      <?php if ($finalized && !empty($p['final_note'])): ?>
        <div class="mt-3 pt-3 border-t border-black/10 text-sm leading-7" dir="auto"><b>توضیح مدیر:</b> <?= nl2p($p['final_note']) ?></div>
      <?php endif; ?>
      <?php if ($finalized): ?>
        <div class="mt-2 text-xs opacity-80">ثبت تصمیم: <span class="num"><?= e(jdate($p['finalized_at'])) ?></span> — محاسبه سامانه: <?= e($sys['label']) ?>، <span class="num"><?= e(money($result['amount'])) ?></span></div>
      <?php endif; ?>
    </div>
    <?php if ($result['needs_resolution'] && !$finalized): ?>
      <p class="text-xs text-red-700 mt-2">توجه: نظر دو داور درباره سطح طرح یا مبلغ درخواستی یکسان نیست و هنوز توسط مدیر مشخص نشده است؛ مبلغ بالا موقت است.</p>
    <?php endif; ?>
  </section>

  <section class="mt-6 break-inside-avoid">
    <h2 class="section-title mb-3">امتیاز ابعاد</h2>
    <table class="tbl border border-slate-200">
      <thead><tr><th>بُعد</th><th>داور</th><th>وزن</th><th>میانگین معیارها (از ۵)</th><th>امتیاز</th><th>حداکثر</th></tr></thead>
      <tbody>
        <?php foreach ($def['dimensions'] as $dim => $d): $m = $result['dims'][$dim]; ?>
          <tr>
            <td class="font-semibold"><?= e($d['label']) ?></td>
            <td><?= e(role_short($d['role'])) ?></td>
            <td class="num"><?= nf($m['weight'] * 100) ?>٪</td>
            <td class="num"><?= nf($m['avg'], 2) ?></td>
            <td class="num font-bold"><?= nf($m['points'], 1) ?></td>
            <td class="num text-slate-500"><?= nf($m['max']) ?></td>
          </tr>
        <?php endforeach; ?>
        <tr class="bg-slate-50">
          <td class="font-bold" colspan="4">امتیاز کل</td>
          <td class="num font-black text-brand-800"><?= nf($result['total'], 1) ?></td>
          <td class="num text-slate-500">۱۰۰</td>
        </tr>
      </tbody>
    </table>
    <p class="text-xs text-slate-500 mt-2">آستانه‌ها: کمتر از <?= nf($cfg['thresholds']['reject_below']) ?> رد، از <?= nf($cfg['thresholds']['reject_below']) ?> تا کمتر از <?= nf($cfg['thresholds']['conditional_below']) ?> تأیید مشروط، <?= nf($cfg['thresholds']['conditional_below']) ?> و بالاتر تأیید.</p>
  </section>

  <section class="mt-6 grid grid-cols-2 gap-5 break-inside-avoid">
    <div>
      <h2 class="section-title mb-2">سطح طرح و مبلغ</h2>
      <table class="kv text-sm">
        <tr><th>نظر داور فنی</th><td><?= e($tierName($result['tier_tech'])) ?></td></tr>
        <tr><th>نظر داور کسب‌وکاری</th><td><?= e($tierName($result['tier_biz'])) ?></td></tr>
        <tr><th>سطح مبنا</th><td class="font-bold"><?= e($tierName($result['tier'])) ?><?= $result['tier_source'] === 'admin' ? ' (انتخاب مدیر)' : ($result['tier_source'] === 'provisional' ? ' (موقت)' : '') ?></td></tr>
        <tr><th>سقف حمایتی سطح</th><td class="num"><?= e(money($result['ceiling'])) ?></td></tr>
        <tr><th>مبلغ درخواستی</th><td class="num"><?= e(money($result['requested'])) ?></td></tr>
        <tr><th>مبنای محاسبه</th><td class="num"><?= e(money($result['base'])) ?></td></tr>
      </table>
    </div>
    <div>
      <h2 class="section-title mb-2">تعدیل ریسک</h2>
      <table class="kv text-sm">
        <tr><th>ریسک فناوری</th><td>TRL <?= fa((string) $result['trl']) ?> — امتیاز <?= fa((string) $result['trl_risk']) ?></td></tr>
        <tr><th>ریسک اجرا</th><td>امتیاز <?= fa((string) $result['risk_exec']) ?></td></tr>
        <tr><th>ریسک بازار</th><td>امتیاز <?= fa((string) $result['risk_market']) ?></td></tr>
        <tr><th>میانگین ریسک</th><td class="num"><?= nf($result['risk_avg'], 2) ?> از ۵</td></tr>
        <tr><th>ضریب ریسک</th><td class="num font-bold"><?= nf($result['coeff'], 3) ?></td></tr>
      </table>
    </div>
  </section>

  <section class="mt-4 break-inside-avoid">
    <div class="rounded-xl bg-slate-50 border border-slate-200 p-3 text-xs text-slate-700 leading-6">
      <b>روش محاسبه:</b> مبلغ حمایتی برابر است با کمترِ «مبلغ درخواستی» و «سقف سطح»، ضرب در (امتیاز کل تقسیم بر ۱۰۰)، ضرب در ضریب ریسک.
      <?php if ($result['decision'] === 'reject'): ?>
        چون امتیاز کل کمتر از <?= nf($cfg['thresholds']['reject_below']) ?> است، مبلغ محاسبه‌شده صفر است.
      <?php else: ?>
        برای این طرح: <span class="num ltr inline-block mx-1"><?= nf($result['base']) ?> × (<?= nf($result['total'], 2) ?> ÷ ۱۰۰) × <?= nf($result['coeff'], 3) ?> = <b><?= nf($result['amount_raw'], 3) ?></b></span> میلیارد ریال<?= $result['capped'] ? '؛ چون از مبنای محاسبه بیشتر است، به ' . nf($result['base']) . ' میلیارد ریال محدود شد.' : '.' ?>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($shownAmount > 0 && $shownDecision !== 'reject'): ?>
  <section class="mt-6 break-inside-avoid">
    <h2 class="section-title mb-3">ساختار پرداخت مرحله‌ای</h2>
    <table class="tbl border border-slate-200">
      <thead><tr><th>مرحله</th><th>درصد</th><th>مبلغ</th><th>شرط آزادسازی</th></tr></thead>
      <tbody>
        <?php foreach ($def['milestones'] as $i => $ms): $pct = (float) ($cfg['payment'][$i] ?? 0); ?>
          <tr>
            <td class="font-semibold whitespace-nowrap"><?= e($ms[0]) ?></td>
            <td class="num"><?= nf($pct) ?>٪</td>
            <td class="num whitespace-nowrap"><?= e(money(round($shownAmount * $pct / 100, 3))) ?></td>
            <td><?= e($ms[1]) ?><?php if ($i === 1 && !empty($answers['tech']['milestone2'])): ?><div class="text-xs text-slate-700 mt-1" dir="auto"><b>مایلستون فنی تعریف‌شده:</b> <?= nl2p($answers['tech']['milestone2']) ?></div><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
  <?php endif; ?>
<?php endif; ?>

<?php foreach (['tech', 'biz'] as $role):
    if (!$as[$role] || $as[$role]['status'] !== 'submitted') { continue; } ?>
  <section class="mt-8 print-break">
    <div class="flex items-center justify-between gap-3 border-b border-slate-300 pb-2 mb-4">
      <h2 class="text-base font-extrabold text-brand-900">فرم <?= e(role_label($role)) ?> — <?= e($as[$role]['judge_name']) ?></h2>
      <span class="text-xs text-slate-500 num">ثبت: <?= e(jdate($as[$role]['submitted_at'])) ?> | <span class="ltr"><?= e($p['code']) ?></span></span>
    </div>
    <?= View::partial('partials/answers', ['role' => $role, 'answers' => $answers[$role], 'def' => $def, 'cfg' => $cfg, 'metrics' => $result ? $result[$role] : json_in($as[$role]['metrics_json'] ?? '', [])]) ?>
  </section>
<?php endforeach; ?>

<section class="mt-10 break-inside-avoid grid grid-cols-2 gap-8 text-sm text-slate-700">
  <div class="border-t border-slate-300 pt-2">تهیه‌کننده گزارش:</div>
  <div class="border-t border-slate-300 pt-2">تأیید و امضا:</div>
</section>
<p class="mt-6 text-[10px] text-slate-400 text-center">این گزارش به‌صورت خودکار از «سامانه ارزش‌گذاری طرح‌ها» تهیه شده است — <?= e($org) ?></p>
