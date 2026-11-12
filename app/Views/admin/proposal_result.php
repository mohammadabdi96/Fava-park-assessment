<?php
/**
 * Steps 3 and 4 – the combined result of the two forms and the admin's final decision.
 *
 * @var array $p
 * @var array $as
 * @var array $answers
 * @var array $cfg
 * @var array $def
 * @var array|null $result
 * @var bool $finalized
 * @var int $id
 * @var bool $configChanged
 */
use Fava\Core\View;
use Fava\Domain\FormDef;
use Fava\Domain\Labels;

$base = '/admin/proposals/' . $id;
$th = $cfg['thresholds'];
$tierName = function (?string $t) use ($cfg) {
    return $t && isset($cfg['tiers'][$t]) ? 'سطح ' . ($cfg['tiers'][$t]['short'] ?? $t) : '—';
};
$barTone = ['blue' => 'bg-blue-500', 'emerald' => 'bg-emerald-500', 'purple' => 'bg-purple-500', 'amber' => 'bg-amber-500'];
$decisionTone = [
    'approve' => 'bg-emerald-50 border-emerald-300 text-emerald-900',
    'conditional' => 'bg-amber-50 border-amber-300 text-amber-900',
    'reject' => 'bg-red-50 border-red-300 text-red-900',
];
$num = function ($v) {
    return rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
};
?>
<section id="result" class="card mt-6 scroll-mt-6">
  <div class="card-head">
    <h2 class="card-title flex items-center gap-2">
      <span class="w-7 h-7 rounded-lg bg-brand-800 text-white text-sm font-bold inline-flex items-center justify-center">۳</span>
      نتیجه تجمیع دو داوری
    </h2>
    <?php if ($result): ?><span class="text-xs text-slate-500">محاسبه: <span class="num"><?= e(jdate($p['computed_at'])) ?></span></span><?php endif; ?>
  </div>

  <?php if (!$result): ?>
    <div class="card-body">
      <div class="flex items-center gap-4 flex-wrap">
        <?php foreach (['tech', 'biz'] as $role): $done = $as[$role] && $as[$role]['status'] === 'submitted'; ?>
          <div class="flex items-center gap-2 rounded-xl border px-4 py-3 text-sm <?= $done ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-slate-200 bg-slate-50 text-slate-500' ?>">
            <?= icon($done ? 'check-circle' : 'clock', 'w-5 h-5') ?>
            <span>فرم <?= e(role_label($role)) ?>: <b><?= $done ? 'ثبت شده' : 'در انتظار' ?></b></span>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="text-sm text-slate-500 mt-4 leading-7">به‌محض اینکه هر دو داور فرم خود را ثبت کنند، سامانه دو فرم را تجمیع می‌کند و امتیاز کل، سطح، ضریب ریسک، مبلغ حمایتی و پیشنهاد تصمیم را همین‌جا نشان می‌دهد.</p>
      <?php foreach (['tech', 'biz'] as $role):
          if ($as[$role] && $as[$role]['status'] === 'submitted'): ?>
        <details class="mt-4 rounded-xl border border-slate-200" id="form-<?= e($role) ?>">
          <summary class="px-4 py-3 text-sm font-semibold text-slate-800 flex items-center justify-between hover:bg-slate-50 rounded-xl">
            <span>فرم ثبت‌شده <?= e(role_label($role)) ?> — <?= e($as[$role]['judge_name']) ?></span><?= icon('chevron', 'w-4 h-4 rot transition') ?>
          </summary>
          <div class="p-4 border-t border-slate-200">
            <?= View::partial('partials/answers', ['role' => $role, 'answers' => $answers[$role], 'def' => $def, 'cfg' => $cfg, 'metrics' => json_in($as[$role]['metrics_json'] ?? '', [])]) ?>
          </div>
        </details>
      <?php endif; endforeach; ?>
    </div>
  <?php else:
      $d = FormDef::decision($result['decision']);
      $total = (float) $result['total']; ?>
    <div class="card-body space-y-6">

      <!-- score -->
      <div class="grid lg:grid-cols-3 gap-5">
        <div class="rounded-2xl border-2 p-5 text-center <?= e($decisionTone[$result['decision']]) ?>">
          <div class="text-xs font-semibold opacity-80">امتیاز کل از ۱۰۰</div>
          <div class="text-5xl font-black num mt-1"><?= nf($total, 1) ?></div>
          <div class="mt-2 font-bold"><?= e($d['title']) ?></div>
          <div class="text-xs mt-1 opacity-80">پیشنهاد سامانه: <?= e($d['label']) ?></div>
        </div>
        <div class="lg:col-span-2">
          <!-- 0..100 scale with the decision bands -->
          <div class="relative pt-7 pb-6" dir="ltr">
            <div class="flex h-4 rounded-full overflow-hidden">
              <span class="bg-red-200" style="width:<?= (float) $th['reject_below'] ?>%"></span>
              <span class="bg-amber-200" style="width:<?= (float) $th['conditional_below'] - (float) $th['reject_below'] ?>%"></span>
              <span class="bg-emerald-200 flex-1"></span>
            </div>
            <div class="absolute top-0 -translate-x-1/2 flex flex-col items-center" style="left:<?= max(0, min(100, $total)) ?>%">
              <span class="text-xs font-bold num text-slate-900 bg-white px-1.5 rounded shadow-sm border border-slate-200" dir="rtl"><?= nf($total, 1) ?></span>
              <span class="w-0.5 h-7 bg-slate-900"></span>
            </div>
            <div class="absolute bottom-0 text-[11px] text-slate-500 num -translate-x-1/2" style="left:0%">۰</div>
            <div class="absolute bottom-0 text-[11px] text-slate-500 num -translate-x-1/2" style="left:<?= (float) $th['reject_below'] ?>%"><?= nf($th['reject_below']) ?></div>
            <div class="absolute bottom-0 text-[11px] text-slate-500 num -translate-x-1/2" style="left:<?= (float) $th['conditional_below'] ?>%"><?= nf($th['conditional_below']) ?></div>
            <div class="absolute bottom-0 text-[11px] text-slate-500 num -translate-x-1/2" style="left:100%">۱۰۰</div>
          </div>
          <div class="flex items-center gap-4 text-[11px] text-slate-500 flex-wrap">
            <span class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-red-200"></span> کمتر از <?= nf($th['reject_below']) ?>: رد</span>
            <span class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-amber-200"></span> <?= nf($th['reject_below']) ?> تا کمتر از <?= nf($th['conditional_below']) ?>: تأیید مشروط</span>
            <span class="flex items-center gap-1"><span class="w-3 h-3 rounded bg-emerald-200"></span> <?= nf($th['conditional_below']) ?> و بالاتر: تأیید</span>
          </div>
          <div class="grid grid-cols-2 gap-3 mt-4 text-sm">
            <div class="rounded-xl bg-blue-50 border border-blue-100 p-3 flex items-center justify-between"><span class="text-slate-600">سهم داور فنی</span><b class="num text-blue-900"><?= nf($result['tech_share'], 1) ?> <span class="font-normal text-xs text-slate-500">از <?= nf($result['tech']['share_max']) ?></span></b></div>
            <div class="rounded-xl bg-amber-50 border border-amber-100 p-3 flex items-center justify-between"><span class="text-slate-600">سهم داور کسب‌وکاری</span><b class="num text-amber-900"><?= nf($result['biz_share'], 1) ?> <span class="font-normal text-xs text-slate-500">از <?= nf($result['biz']['share_max']) ?></span></b></div>
          </div>
        </div>
      </div>

      <?php if ($result['divergence']): ?>
        <div class="alert alert-warning"><span class="shrink-0"><?= icon('warn') ?></span><div>ارزیابی دو داور فاصله زیادی دارد: برآورد داور فنی «<?= e(FormDef::decision($result['est_tech'])['label']) ?>» و برآورد داور کسب‌وکاری «<?= e(FormDef::decision($result['est_biz'])['label']) ?>» است. پیش از تصمیم نهایی، توضیحات هر دو را مرور کنید.</div></div>
      <?php endif; ?>

      <!-- dimensions -->
      <div>
        <h3 class="text-sm font-bold text-slate-800 mb-3">امتیاز ابعاد چهارگانه</h3>
        <div class="grid md:grid-cols-2 gap-x-8 gap-y-4">
          <?php foreach ($def['dimensions'] as $dim => $dd): $m = $result['dims'][$dim]; ?>
            <div>
              <div class="flex items-center justify-between gap-2 text-sm mb-1">
                <span class="font-semibold text-slate-800"><?= e($dd['label']) ?> <span class="text-xs font-normal text-slate-400">(وزن <?= nf($m['weight'] * 100) ?>٪ — <?= e(role_short($dd['role'])) ?>)</span></span>
                <span class="num text-slate-700"><b><?= nf($m['points'], 1) ?></b> <span class="text-xs text-slate-400">از <?= nf($m['max']) ?></span></span>
              </div>
              <div class="bar"><span class="<?= e($barTone[$dd['color']] ?? 'bg-brand-500') ?>" style="width:<?= max(0, min(100, $m['avg'] / 5 * 100)) ?>%"></span></div>
              <div class="text-[11px] text-slate-400 mt-1">میانگین پنج معیار: <span class="num"><?= nf($m['avg'], 2) ?></span> از ۵</div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- tier, risk, amount -->
      <div class="grid lg:grid-cols-3 gap-5">
        <div class="rounded-2xl border border-slate-200 p-4">
          <h3 class="text-sm font-bold text-slate-800 mb-2">سطح طرح</h3>
          <table class="kv text-sm">
            <tr><th>نظر داور فنی</th><td><?= e($tierName($result['tier_tech'])) ?></td></tr>
            <tr><th>نظر داور کسب‌وکاری</th><td><?= e($tierName($result['tier_biz'])) ?></td></tr>
            <tr><th>سطح مبنا</th><td>
              <b class="text-brand-800"><?= e($tierName($result['tier'])) ?></b>
              <?php if ($result['tier_source'] === 'agreed'): ?><span class="badge badge-emerald ms-1">توافق داوران</span>
              <?php elseif ($result['tier_source'] === 'admin'): ?><span class="badge badge-blue ms-1">انتخاب مدیر</span>
              <?php else: ?><span class="badge badge-red ms-1">موقت — نیازمند انتخاب</span><?php endif; ?>
            </td></tr>
            <tr><th>سقف حمایتی</th><td class="num"><?= e(money($result['ceiling'])) ?></td></tr>
          </table>
        </div>
        <div class="rounded-2xl border border-slate-200 p-4">
          <h3 class="text-sm font-bold text-slate-800 mb-2">تعدیل ریسک</h3>
          <table class="kv text-sm">
            <tr><th>ریسک فناوری</th><td>TRL <?= fa((string) $result['trl']) ?> — امتیاز <b class="num"><?= fa((string) $result['trl_risk']) ?></b></td></tr>
            <tr><th>ریسک اجرا</th><td>امتیاز <b class="num"><?= fa((string) $result['risk_exec']) ?></b></td></tr>
            <tr><th>ریسک بازار</th><td>امتیاز <b class="num"><?= fa((string) $result['risk_market']) ?></b></td></tr>
            <tr><th>میانگین</th><td class="num"><?= nf($result['risk_avg'], 2) ?> از ۵</td></tr>
            <tr><th>ضریب ریسک</th><td><b class="num text-brand-800"><?= nf($result['coeff'], 3) ?></b> <span class="text-[11px] text-slate-400 num ltr inline-block">= <?= nf($cfg['risk']['base']) ?> + (<?= nf($result['risk_avg'], 2) ?> − ۱) × <?= nf($cfg['risk']['step']) ?></span></td></tr>
          </table>
          <p class="text-[11px] text-slate-400 mt-2">امتیاز بالاتر یعنی ریسک کمتر؛ ضریب بین <?= nf($cfg['risk']['base']) ?> و <?= nf($cfg['risk']['base'] + 4 * $cfg['risk']['step']) ?> است.</p>
        </div>
        <div class="rounded-2xl border border-slate-200 p-4">
          <h3 class="text-sm font-bold text-slate-800 mb-2">مبلغ حمایتی محاسبه‌شده</h3>
          <div class="text-2xl font-black num <?= $result['decision'] === 'reject' ? 'text-red-700' : 'text-emerald-700' ?>"><?= e(money($result['amount'])) ?></div>
          <?php if ($result['amount'] > 0): ?><div class="text-xs text-slate-500">برابر با <?= e(toman_hint($result['amount'])) ?></div><?php endif; ?>
          <table class="kv text-xs mt-2">
            <tr><th>مبلغ درخواستی</th><td class="num"><?= e(money($result['requested'])) ?>
              <?php if ($result['requested_source'] === 'admin'): ?><span class="text-slate-400">(ثبت مدیر)</span>
              <?php elseif ($result['requested_source'] === 'agreed'): ?><span class="text-slate-400">(اعلام هر دو داور)</span>
              <?php else: ?><span class="text-red-600">(موقت — رقم کمتر)</span><?php endif; ?></td></tr>
            <tr><th>مبنای محاسبه</th><td class="num"><?= e(money($result['base'])) ?> <span class="text-slate-400">(کمترِ درخواستی و سقف)</span></td></tr>
          </table>
          <?php if ($result['decision'] === 'reject'): ?>
            <p class="text-[11px] text-red-600 mt-2 leading-5">امتیاز کل کمتر از <?= nf($th['reject_below']) ?> است؛ بنابراین مبلغ حمایتی صفر است.</p>
          <?php else: ?>
            <p class="text-[11px] text-slate-500 mt-2 leading-5 num"><span class="ltr inline-block"><?= nf($result['base']) ?> × (<?= nf($total, 2) ?> ÷ ۱۰۰) × <?= nf($result['coeff'], 3) ?> = <?= nf($result['amount_raw'], 3) ?></span><?= $result['capped'] ? ' — محدود به ' . nf($result['base']) . ' میلیارد ریال' : '' ?></p>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($result['amount'] > 0): ?>
      <div>
        <h3 class="text-sm font-bold text-slate-800 mb-2">ساختار پرداخت مرحله‌ای پیشنهادی</h3>
        <div class="overflow-x-auto rounded-xl border border-slate-200">
          <table class="tbl">
            <thead><tr><th>مرحله</th><th>درصد</th><th>مبلغ</th><th>شرط آزادسازی</th></tr></thead>
            <tbody>
            <?php $stagePayments = \Fava\Domain\Scoring::payments((float) $result['amount'], $cfg['payment']); ?>
            <?php foreach ($def['milestones'] as $i => $ms): $pay = $stagePayments[$i] ?? null; ?>
              <tr>
                <td class="font-semibold whitespace-nowrap"><?= e($ms[0]) ?></td>
                <td class="num"><?= $pay ? nf($pay['pct']) . '٪' : '—' ?></td>
                <td class="num whitespace-nowrap"><?= $pay ? e(money($pay['amount'])) : '—' ?></td>
                <td class="text-slate-600"><?= e($ms[1]) ?><?php if ($i === 1 && !empty($answers['tech']['milestone2'])): ?><span class="block text-xs text-slate-800 mt-1" dir="auto">تعریف داور فنی: <?= e($answers['tech']['milestone2']) ?></span><?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <!-- differences the admin has to settle -->
      <?php if (($result['tier_conflict'] || $result['amount_conflict']) && !$finalized): ?>
      <div id="resolve" class="rounded-2xl border-2 <?= $result['needs_resolution'] ? 'border-red-300 bg-red-50/60' : 'border-slate-200 bg-slate-50' ?> p-5 scroll-mt-6 no-print">
        <h3 class="font-bold text-slate-900 flex items-center gap-2"><?= icon('scale', 'w-5 h-5') ?> <?= $result['needs_resolution'] ? 'نظر دو داور یکسان نیست — انتخاب با شماست' : 'موارد اختلاف (مشخص‌شده توسط شما)' ?></h3>
        <form method="post" action="<?= e(url($base . '/resolve')) ?>" class="mt-4 space-y-5">
          <?= csrf_field() ?>
          <?php if ($result['tier_conflict']): $chosen = $p['tier_override'] ?: $result['tier']; ?>
            <div>
              <div class="text-sm font-semibold text-slate-800 mb-2">سطح طرح <span class="font-normal text-slate-500">— داور فنی: <?= e($tierName($result['tier_tech'])) ?>، داور کسب‌وکاری: <?= e($tierName($result['tier_biz'])) ?></span></div>
              <div class="grid sm:grid-cols-3 gap-2">
                <?php foreach ($cfg['tiers'] as $key => $t):
                    $by = [];
                    if ($result['tier_tech'] === $key) { $by[] = 'داور فنی'; }
                    if ($result['tier_biz'] === $key) { $by[] = 'داور کسب‌وکاری'; } ?>
                  <label class="radio-card">
                    <input type="radio" name="tier" value="<?= e($key) ?>" class="w-4 h-4 mt-1" <?= $chosen === $key ? 'checked' : '' ?>>
                    <span class="text-sm">
                      <span class="block font-semibold text-slate-800"><?= e($t['label']) ?></span>
                      <span class="block text-xs text-slate-500 mt-0.5 num">سقف <?= e(money($t['ceiling'])) ?></span>
                      <?php if ($by): ?><span class="badge badge-blue mt-1">نظر <?= e(implode(' و ', $by)) ?></span><?php endif; ?>
                    </span>
                  </label>
                <?php endforeach; ?>
              </div>
              <p class="hint">به‌صورت پیش‌فرض سطح پایین‌تر انتخاب شده است.</p>
            </div>
          <?php endif; ?>
          <?php if ($result['amount_conflict']): ?>
            <div class="sm:w-1/2">
              <label class="label" for="r-amount">مبلغ درخواستی شرکت <span class="font-normal text-slate-500">(میلیارد ریال)</span></label>
              <input class="input ltr num" id="r-amount" name="requested_amount" inputmode="decimal" value="<?= $result['requested'] > 0 ? e($num($result['requested'])) : '' ?>" required>
              <p class="hint">داور فنی: <b class="num"><?= $result['requested_tech'] !== null ? e(money($result['requested_tech'])) : 'وارد نکرده' ?></b> — داور کسب‌وکاری: <b class="num"><?= $result['requested_biz'] !== null ? e(money($result['requested_biz'])) : 'وارد نکرده' ?></b></p>
            </div>
          <?php endif; ?>
          <button class="btn btn-primary" type="submit">ثبت و محاسبه دوباره</button>
        </form>
      </div>
      <?php endif; ?>

      <!-- the two forms -->
      <div>
        <h3 class="text-sm font-bold text-slate-800 mb-3">فرم‌های ثبت‌شده داوران</h3>
        <div class="grid xl:grid-cols-2 gap-5">
          <?php foreach (['tech', 'biz'] as $role): ?>
            <div class="rounded-2xl border border-slate-200 overflow-hidden scroll-mt-6" id="form-<?= e($role) ?>">
              <div class="px-4 py-3 border-b border-slate-200 bg-slate-50 flex items-center justify-between gap-2">
                <span class="font-bold text-slate-900"><?= e(role_label($role)) ?> — <?= e($as[$role]['judge_name']) ?></span>
                <span class="text-xs text-slate-500 num">ثبت: <?= e(jdate($as[$role]['submitted_at'])) ?></span>
              </div>
              <div class="p-4">
                <?= View::partial('partials/answers', ['role' => $role, 'answers' => $answers[$role], 'def' => $def, 'cfg' => $cfg, 'metrics' => $result[$role]]) ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if ($configChanged && !$finalized): ?>
        <div class="alert alert-info no-print">
          <span class="shrink-0"><?= icon('info') ?></span>
          <div class="flex-1">پارامترهای امتیازدهی سامانه (وزن‌ها، سقف‌ها یا آستانه‌ها) پس از شروع داوری این طرح تغییر کرده است. این طرح هنوز با پارامترهای زمان شروع محاسبه می‌شود.</div>
          <form method="post" action="<?= e(url($base . '/refresh-config')) ?>" data-confirm="نتیجه این طرح با پارامترهای فعلی سامانه دوباره محاسبه می‌شود. ادامه می‌دهید؟">
            <?= csrf_field() ?>
            <button class="btn btn-secondary btn-sm" type="submit">محاسبه با پارامترهای جدید</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>

<!-- final decision -->
<section id="final" class="card mt-6 scroll-mt-6">
  <div class="card-head">
    <h2 class="card-title flex items-center gap-2">
      <span class="w-7 h-7 rounded-lg bg-brand-800 text-white text-sm font-bold inline-flex items-center justify-center">۴</span>
      تصمیم نهایی مدیر
    </h2>
  </div>
  <div class="card-body">
    <?php if ($finalized):
        $fd = FormDef::decision($p['final_decision']);
        $changed = $result && ($p['final_decision'] !== $result['decision'] || abs((float) $p['final_amount'] - (float) $result['amount']) > 0.0005); ?>
      <div class="rounded-2xl border-2 p-5 <?= e($decisionTone[$p['final_decision']] ?? 'border-slate-200') ?>">
        <div class="flex items-center justify-between gap-4 flex-wrap">
          <div>
            <div class="text-xs opacity-80">تصمیم نهایی</div>
            <div class="text-2xl font-black"><?= e($fd['label']) ?></div>
          </div>
          <div class="text-end">
            <div class="text-xs opacity-80">مبلغ حمایتی مصوب</div>
            <div class="text-2xl font-black num"><?= e(money($p['final_amount'])) ?></div>
            <?php if ((float) $p['final_amount'] > 0): ?><div class="text-xs opacity-80">برابر با <?= e(toman_hint($p['final_amount'])) ?></div><?php endif; ?>
          </div>
        </div>
        <?php if (!empty($p['final_note'])): ?><div class="mt-3 pt-3 border-t border-black/10 text-sm leading-7" dir="auto"><b>توضیح:</b> <?= nl2p($p['final_note']) ?></div><?php endif; ?>
        <div class="mt-3 text-xs opacity-80">ثبت در <span class="num"><?= e(jdate($p['finalized_at'])) ?></span><?= $changed ? ' — با محاسبه سامانه تفاوت دارد (پیشنهاد سامانه: ' . e(FormDef::decision($result['decision'])['label']) . '، ' . e(money($result['amount'])) . ')' : ' — مطابق محاسبه سامانه' ?></div>
      </div>
      <form method="post" action="<?= e(url($base . '/unfinalize')) ?>" class="mt-4 no-print" data-confirm="تصمیم نهایی لغو می‌شود و طرح به مرحله «در انتظار تصمیم» برمی‌گردد. ادامه می‌دهید؟">
        <?= csrf_field() ?>
        <button class="btn btn-ghost btn-sm" type="submit"><?= icon('refresh', 'w-4 h-4') ?> لغو تصمیم نهایی برای اصلاح</button>
      </form>
    <?php elseif (!$result): ?>
      <p class="text-sm text-slate-500">پس از ثبت فرم هر دو داور، این‌جا می‌توانید پیشنهاد سامانه را تأیید کنید یا تصمیم و مبلغ دیگری ثبت کنید.</p>
    <?php elseif ($result['needs_resolution']): ?>
      <div class="alert alert-warning"><span class="shrink-0"><?= icon('warn') ?></span><div>ابتدا در بخش بالا <a class="link" href="#resolve">اختلاف نظر داوران</a> را مشخص کنید تا مبلغ قطعی محاسبه شود.</div></div>
    <?php else:
        $defDecision = (string) old('decision', $result['decision']);
        $defAmount = (string) old('amount', $num($result['amount'])); ?>
      <form method="post" action="<?= e(url($base . '/finalize')) ?>" class="space-y-5" data-final data-system-decision="<?= e($result['decision']) ?>" data-system-amount="<?= e($num($result['amount'])) ?>" data-base="<?= e($num($result['base'])) ?>">
        <?= csrf_field() ?>
        <p class="text-sm text-slate-600 leading-7">پیشنهاد سامانه «<b><?= e(FormDef::decision($result['decision'])['label']) ?></b>» با مبلغ <b class="num"><?= e(money($result['amount'])) ?></b> است. می‌توانید همان را تأیید کنید یا با ذکر دلیل، تصمیم یا مبلغ دیگری ثبت کنید.</p>
        <div class="grid sm:grid-cols-3 gap-3">
          <?php foreach ($def['decisions'] as $key => $dd): ?>
            <label class="radio-card">
              <input type="radio" name="decision" value="<?= e($key) ?>" class="w-4 h-4 mt-1" <?= $defDecision === $key ? 'checked' : '' ?>>
              <span class="text-sm">
                <span class="block font-bold text-slate-900"><?= e($dd['label']) ?></span>
                <span class="block text-xs text-slate-500 mt-0.5"><?= e($dd['title']) ?></span>
                <?php if ($result['decision'] === $key): ?><span class="badge badge-blue mt-1">پیشنهاد سامانه</span><?php endif; ?>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="grid sm:grid-cols-2 gap-4">
          <div>
            <label class="label" for="final-amount">مبلغ حمایتی مصوب <span class="font-normal text-slate-500">(میلیارد ریال)</span></label>
            <input class="input ltr num" id="final-amount" name="amount" inputmode="decimal" value="<?= e($defAmount) ?>" data-toman-hint="#final-amount-toman">
            <p class="hint" id="final-amount-toman">&nbsp;</p>
          </div>
          <div>
            <label class="label" for="final-note">توضیح تصمیم <span class="font-normal text-slate-500" data-note-hint>(اختیاری)</span></label>
            <textarea class="input" id="final-note" name="note" rows="2" placeholder="در صورت تفاوت با پیشنهاد سامانه، دلیل را بنویسید…"><?= e((string) old('note', '')) ?></textarea>
          </div>
        </div>
        <button class="btn btn-success" type="submit"><?= icon('check', 'w-4 h-4') ?> ثبت تصمیم نهایی</button>
      </form>
    <?php endif; ?>
  </div>
</section>
