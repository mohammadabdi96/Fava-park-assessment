<?php
/**
 * Read-only view of one judge's submitted form.
 * Used on the admin's proposal page, on the judge's «sent» page and in the printable report.
 *
 * @var string $role
 * @var array $answers   normalised answers
 * @var array $def       form definition
 * @var array $cfg       scoring parameters of the proposal
 * @var array|null $metrics
 */
use Fava\Domain\Scoring;

$metrics = !empty($metrics) ? $metrics : Scoring::judge($role, $answers, $cfg);
$chip = [
    1 => 'bg-red-500 text-white', 2 => 'bg-orange-500 text-white', 3 => 'bg-amber-500 text-white',
    4 => 'bg-emerald-500 text-white', 5 => 'bg-emerald-600 text-white',
];
$dimTone = [
    'blue' => ['bg-blue-50', 'text-blue-900', 'border-blue-200'],
    'emerald' => ['bg-emerald-50', 'text-emerald-900', 'border-emerald-200'],
    'purple' => ['bg-purple-50', 'text-purple-900', 'border-purple-200'],
    'amber' => ['bg-amber-50', 'text-amber-900', 'border-amber-200'],
];
$tier = $metrics['tier'] ?? null;
$note = function (string $label, $text) {
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }
    return '<div class="mt-2 rounded-lg bg-slate-50 border border-slate-100 p-3"><div class="text-[11px] font-semibold text-slate-500 mb-1">' . e($label)
        . '</div><div class="text-sm text-slate-800 leading-7" dir="auto">' . nl2p($text) . '</div></div>';
};
?>
<div class="space-y-5 text-sm">

  <!-- layer 1 -->
  <div>
    <h4 class="text-xs font-bold text-slate-500 mb-2">۱. طبقه‌بندی استراتژیک</h4>
    <table class="kv">
      <tr><th>مقیاس‌پذیری</th><td><?= e($def['scalability'][$answers['scalability'] ?? '']['0'] ?? '—') ?></td></tr>
      <tr><th>اولویت استراتژیک</th><td><?= e($def['priority'][$answers['priority'] ?? '']['0'] ?? '—') ?></td></tr>
      <tr><th>سطح</th><td class="font-bold text-brand-800"><?= e($tier ? $cfg['tiers'][$tier]['label'] : '—') ?><?php if ($tier): ?> <span class="font-normal text-slate-500">(سقف <?= e(money($cfg['tiers'][$tier]['ceiling'])) ?>)</span><?php endif; ?></td></tr>
    </table>
    <?= $note('توضیح داور درباره سطح', $answers['tier_notes'] ?? '') ?>
  </div>

  <!-- layer 2 -->
  <div>
    <h4 class="text-xs font-bold text-slate-500 mb-2">۲. امتیازدهی</h4>
    <div class="space-y-3">
      <?php foreach ($def['roles'][$role]['dims'] as $dim):
          $d = $def['dimensions'][$dim];
          $tone = $dimTone[$d['color']] ?? $dimTone['blue'];
          $m = $metrics['dims'][$dim] ?? null; ?>
        <div class="rounded-xl border <?= e($tone[2]) ?> overflow-hidden break-inside-avoid">
          <div class="<?= e($tone[0]) ?> px-3 py-2 flex items-center justify-between gap-2">
            <span class="font-bold <?= e($tone[1]) ?>"><?= e($d['title']) ?> <span class="font-normal text-xs opacity-75">(وزن <?= nf(($m['weight'] ?? $cfg['weights'][$dim]) * 100) ?>٪)</span></span>
            <span class="text-xs <?= e($tone[1]) ?>">میانگین <b class="num"><?= $m ? nf($m['avg'], 2) : '—' ?></b> از ۵ — <b class="num"><?= $m ? nf($m['points'], 1) : '—' ?></b> از <?= $m ? nf($m['max']) : '—' ?> امتیاز</span>
          </div>
          <ul class="divide-y divide-slate-100 bg-white">
            <?php foreach ($d['criteria'] as $id => $label): $v = (int) ($answers['scores'][$id] ?? 0); ?>
              <li class="px-3 py-1.5 flex items-center justify-between gap-3">
                <span class="text-slate-700"><?= e($label) ?></span>
                <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg text-sm font-bold num <?= e($chip[$v] ?? 'bg-slate-100 text-slate-400') ?>"><?= $v ? fa((string) $v) : '—' ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="mt-2 text-xs text-slate-600">سهم این داور از امتیاز کل: <b class="num text-brand-800 text-sm"><?= nf($metrics['share'] ?? null, 1) ?></b> از <?= nf($metrics['share_max'] ?? 50) ?></div>
    <?= $note('توضیح داور درباره امتیازها', $answers['score_notes'] ?? '') ?>
  </div>

  <!-- layer 3 -->
  <div>
    <h4 class="text-xs font-bold text-slate-500 mb-2">۳. ریسک</h4>
    <table class="kv">
      <?php if ($role === 'tech'): $trl = $answers['trl'] ?? null; ?>
        <tr><th>سطح آمادگی فناوری</th><td><?= $trl ? 'TRL ' . fa((string) $trl) . ' — ' . e($def['trl'][$trl] ?? '') : '—' ?> <span class="text-slate-500">(امتیاز ریسک <?= fa((string) ($metrics['trl_risk'] ?? '—')) ?>)</span></td></tr>
        <tr><th>ریسک اجرا</th><td><?= e($def['risk_exec'][$answers['risk_exec'] ?? 0] ?? '—') ?> <span class="text-slate-500">(امتیاز <?= fa((string) ($answers['risk_exec'] ?? '—')) ?>)</span></td></tr>
      <?php else: ?>
        <tr><th>ریسک بازار</th><td><?= e($def['risk_market'][$answers['risk_market'] ?? 0] ?? '—') ?> <span class="text-slate-500">(امتیاز <?= fa((string) ($answers['risk_market'] ?? '—')) ?>)</span></td></tr>
      <?php endif; ?>
      <tr><th>ضریب ریسک این داور</th><td class="num"><?= isset($metrics['coeff']) ? nf($metrics['coeff'], 3) : '—' ?></td></tr>
      <?php if (!empty($answers['requested_amount'])): ?>
        <tr><th>مبلغ درخواستی (به گفته داور)</th><td><?= e(money($answers['requested_amount'])) ?></td></tr>
      <?php endif; ?>
    </table>
    <?php if ($role === 'tech'): ?><?= $note('تعریف مایلستون فنی مرحله ۲', $answers['milestone2'] ?? '') ?><?php endif; ?>
  </div>

  <!-- analytical opinion -->
  <?php
  $qs = '';
  foreach ($def['roles'][$role]['questions'] as $q) {
      $qs .= $note($def['questions'][$q], $answers['q'][$q] ?? '');
  }
  if ($qs !== ''): ?>
    <div>
      <h4 class="text-xs font-bold text-slate-500 mb-1">نظر تحلیلی داور</h4>
      <?= $qs ?>
    </div>
  <?php endif; ?>

  <!-- summary -->
  <div class="break-inside-avoid">
    <h4 class="text-xs font-bold text-slate-500 mb-2">جمع‌بندی داور</h4>
    <?php if (!empty($answers['reasons'])): ?>
      <ul class="space-y-1.5">
        <?php foreach ($answers['reasons'] as $r): ?>
          <li class="flex items-start gap-2"><span class="mt-2.5 w-1.5 h-1.5 rounded-full bg-brand-600 shrink-0"></span><span class="leading-7 text-slate-800" dir="auto"><?= nl2p($r) ?></span></li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="text-slate-400">—</p>
    <?php endif; ?>
  </div>

  <div class="text-xs text-slate-500 border-t border-slate-100 pt-3 flex items-center justify-between gap-2 flex-wrap">
    <span><?= e(role_label($role)) ?>: <b class="text-slate-800"><?= e($answers['name'] ?? '—') ?></b></span>
    <span>تاریخ تکمیل فرم: <b class="text-slate-800 num"><?= e(fa((string) ($answers['date'] ?? '—'))) ?></b></span>
    <?php if (!empty($answers['commitment'])): ?><span class="text-emerald-700 inline-flex items-center gap-1"><?= icon('check', 'w-3.5 h-3.5') ?> تعهدنامه تأیید شده است</span><?php endif; ?>
  </div>
</div>
