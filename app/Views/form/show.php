<?php
/**
 * The judge's assessment form («کاربرگ ارزش‌گذاری استراتژیک طرح»), locked to one role.
 * Layout and wording follow the form designed by the park; assets/js/form.js drives it.
 *
 * @var array $p
 * @var array $fields
 * @var string $role        tech | biz
 * @var array $def
 * @var array $cfg
 * @var array $client
 * @var array $answers
 * @var float|null $fixedAmount
 * @var bool $preview
 * @var array|null $urls
 * @var string|null $fileUrl
 * @var string|null $docHtml
 * @var string|null $savedAt
 * @var bool $reopened
 * @var string $support
 * @var string $storageKey
 * @var string $pageTitle
 * @var string $org
 */
$isTech = $role === 'tech';
$roleDef = $def['roles'][$role];
$tone = [
    'blue' => ['border-blue-200', 'bg-blue-50', 'text-blue-900', 'bg-blue-600', 'bg-blue-100 text-blue-800', 'text-blue-700'],
    'emerald' => ['border-emerald-200', 'bg-emerald-50', 'text-emerald-900', 'bg-emerald-600', 'bg-emerald-100 text-emerald-800', 'text-emerald-700'],
    'purple' => ['border-purple-200', 'bg-purple-50', 'text-purple-900', 'bg-purple-600', 'bg-purple-100 text-purple-800', 'text-purple-700'],
    'amber' => ['border-amber-200', 'bg-amber-50', 'text-amber-900', 'bg-amber-600', 'bg-amber-100 text-amber-800', 'text-amber-700'],
];
$shareMax = 0.0;
foreach ($roleDef['dims'] as $dim) {
    $shareMax += (float) $cfg['weights'][$dim] * 100;
}
$coeffMin = (float) $cfg['risk']['base'];
$coeffMax = $coeffMin + 4 * (float) $cfg['risk']['step'];
$field = 'w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none text-sm bg-white';
$amountValue = $answers['requested_amount'] !== null ? rtrim(rtrim(number_format((float) $answers['requested_amount'], 3, '.', ''), '0'), '.') : '';
$boot = [
    'cfg' => $client,
    'answers' => $answers,
    'fixedAmount' => $fixedAmount,
    'preview' => $preview,
    'urls' => $urls,
    'storageKey' => $storageKey,
    'savedAt' => $savedAt ? jdate($savedAt, 'H:i') : null,
    'rev' => (string) ($savedAt ?? ''),
    'shareMax' => $shareMax,
];
$scoreDigits = ['', '۱', '۲', '۳', '۴', '۵'];
?>
<?php if ($preview): ?>
<div class="bg-amber-400 text-amber-950 text-sm font-semibold text-center px-4 py-2 no-print">
  پیش‌نمایش مدیر — این همان فرمی است که <?= e(role_label($role)) ?> می‌بیند. در این حالت چیزی ذخیره یا ثبت نمی‌شود.
  <button type="button" class="underline ms-2" onclick="window.close()">بستن</button>
</div>
<?php endif; ?>

<!-- Header -->
<header class="bg-gradient-to-l from-blue-900 to-blue-700 text-white py-5 px-4 shadow-lg no-print">
  <div class="max-w-6xl mx-auto">
    <div class="flex items-center justify-between gap-4 flex-wrap">
      <div>
        <h1 class="text-lg md:text-xl font-bold leading-relaxed"><?= e($pageTitle) ?></h1>
        <p class="mt-1 text-blue-100 text-xs md:text-sm"><?= e($org) ?> | نقش داور: تحلیلگر ارزش‌گذاری</p>
      </div>
      <div class="inline-flex items-center gap-2 bg-blue-950/40 rounded-xl px-3 py-2 text-sm">
        <span class="text-blue-200 text-xs">فرم ویژه</span>
        <span class="bg-white text-blue-800 font-bold rounded-lg px-3 py-1"><?= e(role_label($role)) ?></span>
      </div>
    </div>

    <div class="mt-4 flex items-center gap-2 text-xs overflow-x-auto pb-1" id="stepper">
      <?php foreach ([1 => ['طبقه‌بندی', '#layer-1'], 2 => ['امتیازدهی', '#layer-2'], 3 => ['ریسک', '#layer-3'], 4 => ['جمع‌بندی', '#summary']] as $n => $st): ?>
        <?php if ($n > 1): ?><div class="h-px bg-blue-400/40 w-6 shrink-0"></div><?php endif; ?>
        <a href="<?= e($st[1]) ?>" class="flex items-center gap-2 shrink-0" data-step="<?= $n ?>"><span class="fstep-dot todo"><?= fa((string) $n) ?></span><span class="text-blue-100"><?= e($st[0]) ?></span></a>
      <?php endforeach; ?>
    </div>
  </div>
</header>

<!-- Sticky -->
<div id="sticky-summary" class="sticky top-0 z-40 bg-white/95 backdrop-blur border-b border-gray-200 shadow-sm no-print">
  <div class="max-w-6xl mx-auto px-4 py-2 flex items-center justify-between gap-3 flex-wrap text-xs">
    <div class="flex items-center gap-4 flex-wrap">
      <div class="flex items-center gap-1.5"><span class="text-gray-500">نقش:</span><span id="sum-role" class="font-bold text-blue-700"><?= e(role_label($role)) ?></span></div>
      <div class="flex items-center gap-1.5"><span class="text-gray-500">سطح:</span><span id="sum-tier" class="font-bold text-blue-700">—</span></div>
      <div class="flex items-center gap-1.5"><span class="text-gray-500">سهم از امتیاز:</span><span id="sum-share" class="font-bold text-blue-700">—</span></div>
    </div>
    <div class="flex items-center gap-3">
      <span id="save-status" class="text-gray-400" aria-live="polite"><?= $preview ? 'پیش‌نمایش' : ($savedAt ? 'آخرین ذخیره: ' . e(jdate($savedAt, 'H:i')) : 'پاسخ‌ها خودکار ذخیره می‌شود') ?></span>
      <div class="flex items-center gap-2">
        <div class="w-24 sm:w-32 bg-gray-200 rounded-full h-2 overflow-hidden"><div id="progress-fill" class="h-2 rounded-full bg-red-400" style="width:0%"></div></div>
        <span id="progress-text" class="font-bold text-gray-500">۰٪</span>
      </div>
      <?php if ($docHtml !== null): ?>
        <button type="button" class="btn btn-soft btn-sm" data-drawer-open><?= icon('doc', 'w-4 h-4') ?> متن کاربرگ طرح</button>
      <?php endif; ?>
    </div>
  </div>
</div>

<main class="max-w-6xl mx-auto px-4 py-6 space-y-5 pb-24" id="form-container">
  <noscript><div class="alert alert-error">برای تکمیل این فرم باید جاوااسکریپت در مرورگر شما فعال باشد.</div></noscript>

  <?php if ($reopened): ?>
    <div class="alert alert-warning"><span class="shrink-0"><?= icon('refresh') ?></span><div>دبیرخانه این فرم را برای <b>اصلاح</b> دوباره باز کرده است. پاسخ‌های قبلی شما در فرم قرار دارد؛ پس از اصلاح، دوباره «ثبت نهایی» را بزنید.</div></div>
  <?php endif; ?>
  <?php if (!empty($p['due_at'])): ?>
    <div class="alert alert-info"><span class="shrink-0"><?= icon('clock') ?></span><div>مهلت تکمیل این فرم: <b class="num"><?= e(jdate($p['due_at'], 'Y/m/d')) ?></b></div></div>
  <?php endif; ?>

  <!-- مشخصات طرح -->
  <section class="form-card bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <h2 class="section-title text-base font-bold text-blue-900">مشخصات طرح <span class="text-xs font-normal text-gray-400 num ltr ms-1"><?= e($p['code']) ?></span></h2>
      <span class="text-xs bg-green-50 text-green-700 border border-green-200 rounded-full px-3 py-1 inline-flex items-center gap-1"><?= icon('check', 'w-3.5 h-3.5') ?> طرح پیش از این از پیش‌ارزیابی صلاحیت عبور کرده است</span>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
      <div class="bg-gray-50 rounded-lg p-3 border border-gray-100"><div class="text-xs text-gray-500 mb-1">عنوان کامل طرح</div><div id="disp-title" class="font-semibold text-gray-800 leading-7" dir="auto"><?= e($p['title'] ?: '—') ?></div></div>
      <div class="bg-gray-50 rounded-lg p-3 border border-gray-100"><div class="text-xs text-gray-500 mb-1">نام شرکت/تیم</div><div id="disp-company" class="font-semibold text-gray-800 leading-7" dir="auto"><?= e($p['company'] ?: '—') ?></div></div>
      <div class="bg-gray-50 rounded-lg p-3 border border-gray-100 md:col-span-2"><div class="text-xs text-gray-500 mb-1">خروجی اصلی مورد انتظار</div><div id="disp-output" class="font-semibold text-gray-800 leading-7" dir="auto"><?= $p['expected_output'] ? nl2p($p['expected_output']) : '—' ?></div></div>

      <?php if ($fixedAmount !== null): ?>
        <div class="bg-blue-50 rounded-lg p-3 border border-blue-100 md:col-span-2 flex items-center justify-between gap-3 flex-wrap">
          <div>
            <div class="text-xs text-gray-500 mb-1">مبلغ درخواستی شرکت</div>
            <div class="font-bold text-blue-900 text-base num"><?= e(money($fixedAmount)) ?> <span class="text-xs font-normal text-gray-500">(برابر با <?= e(toman_hint($fixedAmount)) ?>)</span></div>
          </div>
          <span class="text-xs text-gray-500">این مبلغ را دبیرخانه ثبت کرده است.</span>
        </div>
      <?php else: ?>
        <div class="md:col-span-2" data-field="requested_amount">
          <label class="block text-sm font-semibold text-gray-700 mb-1" for="requested-amount">مبلغ درخواستی شرکت (میلیارد ریال) <span class="req-star">*</span></label>
          <input id="requested-amount" type="text" inputmode="decimal" autocomplete="off" class="w-full md:w-1/2 px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none text-sm ltr" placeholder="مثال: 25" value="<?= e($amountValue) ?>">
          <p class="text-xs text-gray-500 mt-1.5 bg-blue-50 rounded-md p-2 border border-blue-100">
            <b>راهنمای ورود عدد:</b> برای مبلغ ۵ میلیارد تومان، عدد <b>۵۰</b> وارد کنید. برای ۲.۵ میلیارد تومان، <b>۲۵</b>. (واحد: میلیارد ریال)
            <span id="amount-toman" class="font-semibold text-blue-800"></span>
          </p>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($docHtml !== null || $fileUrl): ?>
      <div class="mt-4 flex items-center gap-2 flex-wrap no-print">
        <?php if ($docHtml !== null): ?>
          <button type="button" class="btn btn-primary btn-sm" data-drawer-open><?= icon('doc', 'w-4 h-4') ?> مشاهده متن کاربرگ طرح</button>
        <?php endif; ?>
        <?php if ($fileUrl): ?>
          <a class="btn btn-secondary btn-sm" href="<?= e($fileUrl) ?>"><?= icon('download', 'w-4 h-4') ?> دانلود فایل Word کاربرگ</a>
        <?php endif; ?>
        <span class="text-xs text-gray-500">کاربرگ تکمیل‌شده شرکت را کنار فرم باز کنید و بر اساس آن ارزیابی کنید.</span>
      </div>
    <?php endif; ?>
  </section>

  <!-- لایه ۱: طبقه‌بندی — مشترک -->
  <section class="form-card layer-card bg-white rounded-2xl p-5 shadow-sm border border-gray-100 scroll-mt-16" id="layer-1">
    <div class="flex items-start gap-3 mb-4">
      <span class="layer-num">۱</span>
      <div><h2 class="text-base font-bold text-blue-900">طبقه‌بندی استراتژیک طرح</h2><p class="text-xs text-gray-500 mt-1">هر دو داور این بخش را به‌صورت مستقل تکمیل می‌کنند؛ ادمین هر دو نظر را برای تصمیم نهایی می‌بیند.</p></div>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div class="border border-gray-200 rounded-xl p-3 bg-gray-50/50" data-field="scalability">
        <h3 class="font-bold text-blue-800 mb-2 text-sm">معیار اول: مقیاس‌پذیری <span class="req-star">*</span></h3>
        <div class="radio-group space-y-2">
          <?php foreach ($def['scalability'] as $key => $opt): ?>
            <label class="radio-card"><input type="radio" name="scalability" value="<?= e($key) ?>" class="w-4 h-4 mt-0.5" <?= $answers['scalability'] === $key ? 'checked' : '' ?>><div class="text-xs"><div class="font-semibold text-gray-800"><?= e($opt[0]) ?></div><div class="text-gray-500"><?= e($opt[1]) ?></div></div></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="border border-gray-200 rounded-xl p-3 bg-gray-50/50" data-field="priority">
        <h3 class="font-bold text-blue-800 mb-2 text-sm">معیار دوم: اولویت استراتژیک <span class="req-star">*</span></h3>
        <div class="radio-group space-y-2">
          <?php foreach ($def['priority'] as $key => $opt): ?>
            <label class="radio-card"><input type="radio" name="priority" value="<?= e($key) ?>" class="w-4 h-4 mt-0.5" <?= $answers['priority'] === $key ? 'checked' : '' ?>><div class="text-xs"><div class="font-semibold text-gray-800"><?= e($opt[0]) ?></div><div class="text-gray-500"><?= e($opt[1]) ?></div></div></label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <div id="tier-result" class="mt-4 rounded-xl border-2 border-dashed border-gray-300 bg-gray-50 p-4 hidden">
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div><div class="text-xs text-gray-500">سطح تعیین‌شده</div><div id="tier-label" class="text-lg font-bold text-blue-800">—</div></div>
        <div class="text-left"><div class="text-xs text-gray-500">سقف حمایتی این سطح</div><div id="tier-ceiling" class="text-lg font-bold text-green-700">—</div></div>
      </div>
    </div>
    <div class="mt-3"><label class="block text-xs font-semibold text-gray-500 mb-1" for="d-tier">برای ارائه توضیحات تکمیلی در این خصوص اینجا ذکر کنید:</label><textarea id="d-tier" class="<?= $field ?>" rows="2" placeholder="دلیل انتخاب سطح..."><?= e($answers['tier_notes']) ?></textarea></div>
  </section>

  <!-- لایه ۲: امتیازدهی — تفکیک‌شده -->
  <section class="form-card layer-card bg-white rounded-2xl p-5 shadow-sm border border-gray-100 scroll-mt-16" id="layer-2">
    <div class="flex items-start gap-3 mb-4">
      <span class="layer-num">۲</span>
      <div><h2 class="text-base font-bold text-blue-900">امتیازدهی چندبُعدی وزنی</h2><p class="text-xs text-gray-500 mt-1" id="layer-2-desc"><?= $isTech ? 'شما فقط ابعاد فنی و تیم را ارزیابی می‌کنید.' : 'شما فقط ابعاد بازار و مالی را ارزیابی می‌کنید.' ?> به هر معیار از ۱ (ضعیف) تا ۵ (عالی) امتیاز دهید.</p></div>
    </div>

    <div class="space-y-4">
      <?php foreach ($roleDef['dims'] as $dim):
          $d = $def['dimensions'][$dim];
          $t = $tone[$d['color']] ?? $tone['blue']; ?>
        <div class="border <?= $t[0] ?> rounded-xl overflow-hidden" data-dim="<?= e($dim) ?>">
          <div class="<?= $t[1] ?> px-3 py-2 flex items-center justify-between">
            <div class="flex items-center gap-2"><span class="text-sm font-bold <?= $t[2] ?>"><?= e($d['title']) ?></span><span class="text-xs <?= $t[3] ?> text-white rounded-full px-2 py-0.5">وزن <?= nf((float) $cfg['weights'][$dim] * 100) ?>٪</span></div>
            <div class="flex items-center gap-2"><span class="text-xs text-gray-500">میانگین:</span><span class="inline-flex items-center justify-center min-w-[2rem] h-8 px-2 rounded-lg <?= $t[4] ?> font-bold text-sm" data-dim-avg="<?= e($dim) ?>">—</span></div>
          </div>
          <div class="p-3 space-y-3 bg-white">
            <?php foreach ($d['criteria'] as $id => $label): $v = (int) ($answers['scores'][$id] ?? 0); ?>
              <div class="crit-row" data-crit="<?= e($id) ?>">
                <div class="flex justify-between items-center mb-1.5"><span class="text-xs font-semibold text-gray-700" id="lbl-<?= e($id) ?>"><?= e($label) ?></span><span class="text-xs text-gray-400">انتخاب: <span class="font-bold <?= $t[5] ?>" data-val><?= $v ? $scoreDigits[$v] : '—' ?></span></span></div>
                <div class="score-btns flex gap-2 flex-wrap" role="group" aria-labelledby="lbl-<?= e($id) ?>">
                  <?php for ($i = 1; $i <= 5; $i++): ?><button type="button" class="score-btn<?= $v === $i ? ' selected' : '' ?>" data-v="<?= $i ?>" aria-pressed="<?= $v === $i ? 'true' : 'false' ?>"><?= $scoreDigits[$i] ?></button><?php endfor; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- سهم داور از امتیاز -->
    <div class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-3">
      <div class="bg-blue-50 rounded-xl p-3 border border-blue-200 text-center">
        <div class="text-xs text-gray-600">سهم این داور از امتیاز کل</div>
        <div id="share-value" class="text-xl font-bold text-blue-800 mt-1">—</div>
        <div class="text-[10px] text-gray-500 mt-1">حداکثر <?= nf($shareMax) ?> از ۱۰۰</div>
      </div>
      <div id="share-box" class="bg-gray-50 rounded-xl p-3 border border-gray-200 text-center">
        <div class="text-xs text-gray-600">ارزیابی سیستم (بر اساس سهم فعلی)</div>
        <div id="share-interp" class="text-sm font-bold text-gray-700 mt-1">—</div>
        <div id="share-decision" class="text-[11px] text-gray-500 mt-1">تصمیم نهایی پس از تجمیع دو داور</div>
      </div>
    </div>

    <div class="mt-3"><label class="block text-xs font-semibold text-gray-500 mb-1" for="d-score">برای ارائه توضیحات تکمیلی در این خصوص اینجا ذکر کنید:</label><textarea id="d-score" class="<?= $field ?>" rows="2" placeholder="نکات کلیدی هر بُعد..."><?= e($answers['score_notes']) ?></textarea></div>
  </section>

  <!-- لایه ۳: ریسک — تفکیک‌شده -->
  <section class="form-card layer-card bg-white rounded-2xl p-5 shadow-sm border border-gray-100 scroll-mt-16" id="layer-3">
    <div class="flex items-start gap-3 mb-4"><span class="layer-num">۳</span><div><h2 class="text-base font-bold text-blue-900">تعدیل ریسک</h2><p class="text-xs text-gray-500 mt-1" id="layer-3-desc"><?= $isTech ? 'شما فقط ریسک تکنولوژیک (TRL) و ریسک اجرا را ارزیابی می‌کنید.' : 'شما فقط ریسک بازار را ارزیابی می‌کنید.' ?></p></div></div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <?php if ($isTech): ?>
        <!-- ریسک تکنولوژیک (فنی) — ۹ سطح TRL -->
        <div class="border border-rose-200 rounded-xl p-3 bg-rose-50/30 md:col-span-2" data-field="trl">
          <h3 class="font-bold text-rose-800 mb-2 text-sm">سطح آمادگی فناوری (TRL) <span class="req-star">*</span></h3>
          <p class="text-xs text-gray-500 mb-2">سطح TRL طرح را انتخاب کنید. سیستم به‌صورت خودکار امتیاز ریسک را تعیین می‌کند.
            <?php if (!empty($fields['trl_declared'])): ?><span class="inline-block bg-white border border-rose-200 text-rose-800 rounded-md px-2 py-0.5 ms-1">شرکت در کاربرگ خود، TRL را «سطح <?= fa((string) $fields['trl_declared']) ?>» اعلام کرده است.</span><?php endif; ?></p>
          <div class="radio-group grid grid-cols-1 sm:grid-cols-3 gap-1.5">
            <?php foreach ($def['trl'] as $n => $label): ?>
              <label class="radio-card items-center text-xs !p-2"><input type="radio" name="trl" value="<?= (int) $n ?>" class="w-4 h-4" <?= (int) ($answers['trl'] ?? 0) === (int) $n ? 'checked' : '' ?>><span><?= fa((string) $n) ?> — <?= e($label) ?></span></label>
            <?php endforeach; ?>
          </div>
          <div class="mt-2 text-xs text-gray-500">انتخاب: <span data-trl-display class="font-bold text-rose-700">—</span> | امتیاز ریسک خودکار: <span data-trl-score class="font-bold text-rose-700">—</span> <span class="text-gray-400">(سطح ۱ تا ۳: امتیاز ۱ — سطح ۴ تا ۶: امتیاز ۳ — سطح ۷ تا ۹: امتیاز ۵)</span></div>
        </div>

        <!-- ریسک اجرا (فنی) -->
        <div class="border border-yellow-200 rounded-xl p-3 bg-yellow-50/30" data-field="risk_exec">
          <h3 class="font-bold text-yellow-800 mb-2 text-sm">ریسک اجرا (فنی) <span class="req-star">*</span></h3>
          <div class="radio-group space-y-1.5">
            <?php foreach ($def['risk_exec'] as $n => $label): ?>
              <label class="radio-card items-center text-xs !p-2"><input type="radio" name="risk_exec" value="<?= (int) $n ?>" class="w-4 h-4" <?= (int) ($answers['risk_exec'] ?? 0) === (int) $n ? 'checked' : '' ?>><span><?= e($label) ?></span></label>
            <?php endforeach; ?>
          </div>
          <div class="mt-2 text-xs text-gray-500">انتخاب: <span data-risk-val="risk_exec" class="font-bold text-yellow-700">—</span></div>
        </div>
      <?php else: ?>
        <!-- ریسک بازار (کسب‌وکاری) -->
        <div class="border border-orange-200 rounded-xl p-3 bg-orange-50/30 md:col-span-3" data-field="risk_market">
          <h3 class="font-bold text-orange-800 mb-2 text-sm">ریسک بازار <span class="req-star">*</span></h3>
          <div class="radio-group grid grid-cols-1 sm:grid-cols-3 gap-1.5">
            <?php foreach ($def['risk_market'] as $n => $label): ?>
              <label class="radio-card items-center text-xs !p-2"><input type="radio" name="risk_market" value="<?= (int) $n ?>" class="w-4 h-4" <?= (int) ($answers['risk_market'] ?? 0) === (int) $n ? 'checked' : '' ?>><span><?= e($label) ?></span></label>
            <?php endforeach; ?>
          </div>
          <div class="mt-2 text-xs text-gray-500">انتخاب: <span data-risk-val="risk_market" class="font-bold text-orange-700">—</span></div>
        </div>
      <?php endif; ?>
    </div>

    <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3">
      <div class="bg-rose-50 rounded-xl p-3 border border-rose-200 text-center"><div class="text-xs text-gray-600">میانگین ریسک این داور</div><div id="r-avg" class="text-xl font-bold text-rose-800 mt-1">—</div><div class="text-[10px] text-gray-500 mt-1">بازه ۱ تا ۵ (عدد بالاتر یعنی ریسک کمتر)</div></div>
      <div class="bg-amber-50 rounded-xl p-3 border border-amber-200 text-center"><div class="text-xs text-gray-600">ضریب ریسک این داور</div><div id="coeff" class="text-xl font-bold text-amber-800 mt-1">—</div><div class="text-[10px] text-gray-500 mt-1">بازه <?= nf($coeffMin) ?> تا <?= nf($coeffMax) ?> | نهایی پس از تجمیع</div></div>
    </div>
  </section>

  <!-- لایه ۴: مبلغ و پرداخت — برآورد اولیه -->
  <section class="form-card layer-card bg-white rounded-2xl p-5 shadow-sm border border-gray-100 scroll-mt-16" id="layer-4">
    <div class="flex items-start gap-3 mb-4"><span class="layer-num">۴</span><div><h2 class="text-base font-bold text-blue-900">برآورد اولیه مبلغ حمایتی</h2><p class="text-xs text-gray-500 mt-1">این عدد فقط بر اساس سهم این داور محاسبه می‌شود و نهایی نیست. مبلغ نهایی پس از تجمیع دو داور در پنل ادمین تعیین می‌شود.</p></div></div>

    <div id="estimate-card" class="rounded-xl p-4 border-2 bg-gray-50 border-gray-200">
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
          <div class="text-xs text-gray-600">برآورد اولیه (بر مبنای این داور)</div>
          <div id="calc-estimate" class="text-2xl font-bold text-gray-700">—</div>
          <div class="text-xs text-gray-500 mt-1" id="estimate-subtext">در انتظار تکمیل داده‌ها...</div>
        </div>
        <div class="text-left">
          <div class="text-xs text-gray-500">سهم امتیازی این داور</div>
          <div id="estimate-share" class="text-sm font-bold text-blue-700">—</div>
        </div>
      </div>
    </div>

    <?php if ($isTech): ?>
    <!-- ساختار پرداخت — فقط فنی -->
    <div id="milestone-section" class="mt-5">
      <h3 class="text-sm font-bold text-blue-900 mb-2">ساختار پرداخت مرحله‌ای پیشنهادی</h3>
      <div class="overflow-x-auto">
        <table class="w-full text-xs border-collapse border border-gray-200">
          <thead class="bg-blue-50"><tr><th class="border border-gray-200 p-2 text-right">مرحله</th><th class="border border-gray-200 p-2 text-right">درصد</th><th class="border border-gray-200 p-2 text-right">شرط آزادسازی</th></tr></thead>
          <tbody>
            <?php foreach ($def['milestones'] as $i => $ms): ?>
              <tr><td class="border border-gray-200 p-2 whitespace-nowrap"><?= e($ms[0]) ?></td><td class="border border-gray-200 p-2"><?= nf($cfg['payment'][$i] ?? 0) ?>٪</td><td class="border border-gray-200 p-2"><?= e($ms[1]) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="mt-3" data-field="milestone2">
        <label class="block text-xs font-semibold text-gray-500 mb-1" for="milestone-2-def">تعریف مایلستون فنی مرحله ۲ <span class="req-star" id="milestone-req-star">*</span></label>
        <textarea id="milestone-2-def" class="<?= $field ?>" rows="2" placeholder="شاخص عینی اثبات پیشرفت را تعریف کنید..."><?= e($answers['milestone2'] ?? '') ?></textarea>
        <p class="text-xs text-gray-400 mt-1" id="milestone-hint">برای طرح‌های تأییدشده یا مشروط، الزامی است.</p>
      </div>
    </div>
    <?php endif; ?>
  </section>

  <!-- نظر تحلیلی -->
  <section class="form-card bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
    <h2 class="section-title text-base font-bold text-blue-900 mb-1">نظر تحلیلی داور</h2>
    <p class="text-xs text-gray-500 mb-4">این بخش اختیاری است. برای ارائه توضیحات تکمیلی در هر مورد، از کادر مربوطه استفاده کنید.</p>
    <div class="space-y-4">
      <?php foreach ($roleDef['questions'] as $q): ?>
        <div><label class="block text-sm font-semibold text-gray-700 mb-2 leading-relaxed" for="<?= e($q) ?>"><?= e($def['questions'][$q]) ?></label><textarea id="<?= e($q) ?>" data-q="<?= e($q) ?>" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none text-sm" rows="3" placeholder="برای ارائه توضیحات تکمیلی در این خصوص اینجا ذکر کنید..."><?= e($answers['q'][$q] ?? '') ?></textarea></div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- جمع‌بندی -->
  <section class="form-card bg-white rounded-2xl p-5 shadow-sm border border-gray-100 scroll-mt-16" id="summary" data-field="reasons">
    <h2 class="section-title text-base font-bold text-blue-900 mb-1">جمع‌بندی داور <span class="req-star">*</span></h2>
    <p id="reasons-guide" class="text-xs text-gray-500 mb-3">دلایل قطعی و مشخص خود را به‌صورت بولت‌پوینت ذکر کنید. حداقل یک بولت با متن الزامی است.</p>
    <div id="reasons-list" class="space-y-2"></div>
    <button type="button" id="add-reason" class="mt-3 inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-50 hover:bg-blue-100 text-blue-700 rounded-lg text-xs font-semibold border border-blue-200 transition-all"><span class="text-base">+</span> افزودن بولت</button>
    <div id="reasons-error" class="hidden mt-2 text-xs text-red-600 font-semibold">حداقل یک بولت با متن لازم است.</div>
  </section>

  <!-- تعهد -->
  <section class="form-card bg-gradient-to-br from-blue-50 to-indigo-50 rounded-2xl p-5 shadow-sm border border-blue-100" data-field="commitment">
    <div class="flex items-start gap-3">
      <input type="checkbox" id="commitment" class="w-5 h-5 mt-0.5 accent-blue-600 shrink-0" <?= $answers['commitment'] ? 'checked' : '' ?>>
      <label for="commitment" class="text-sm text-gray-700 leading-relaxed cursor-pointer"><?= e($def['commitment']) ?> <span class="req-star">*</span></label>
    </div>
  </section>

  <!-- مشخصات داور -->
  <section class="form-card bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
    <h2 class="section-title text-base font-bold text-blue-900 mb-1">مشخصات داور</h2>
    <p class="text-xs text-gray-500 mb-4">این فرم با پیوند اختصاصی شما و برای نقش «<?= e(role_label($role)) ?>» باز شده است.</p>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div data-field="name">
        <label class="block text-sm font-semibold text-gray-700 mb-1" for="assessor-name">نام و نام خانوادگی داور <span id="role-label-name" class="text-blue-700"><?= e($roleDef['short']) ?></span> <span class="req-star">*</span></label>
        <input id="assessor-name" type="text" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none text-sm" placeholder="نام کامل..." maxlength="150" value="<?= e($answers['name']) ?>">
      </div>
      <div data-field="date">
        <label class="block text-sm font-semibold text-gray-700 mb-1" for="assessor-date">تاریخ تکمیل فرم <span class="req-star">*</span></label>
        <input id="assessor-date" type="text" inputmode="numeric" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none text-sm ltr text-right" placeholder="۱۴۰۵/۰۸/۲۰" autocomplete="off" data-jdate value="<?= e(fa($answers['date'])) ?>">
      </div>
    </div>
    <div class="mt-3 p-2 bg-blue-50 rounded-md border border-blue-100 text-xs text-blue-800 leading-6 flex items-start gap-2">
      <span class="shrink-0 mt-0.5"><?= icon('info', 'w-4 h-4') ?></span><span>پاسخ‌های شما هنگام تکمیل، خودکار ذخیره می‌شود و می‌توانید بعداً با همین پیوند ادامه دهید. پس از «ثبت نهایی»، فرم مستقیماً به دبیرخانه می‌رسد و با نظر داور دیگر تجمیع می‌شود.</span>
    </div>
  </section>

  <!-- Submit -->
  <section class="form-card bg-white rounded-2xl p-5 shadow-sm border border-gray-100 no-print">
    <div class="space-y-3">
      <div class="flex justify-between items-center text-sm gap-3 flex-wrap">
        <span class="font-semibold text-gray-600">وضعیت تکمیل:</span>
        <span class="flex items-center gap-3">
          <span id="progress-hint" class="text-xs text-gray-400">برای فعال شدن دکمه، همه فیلدهای الزامی را تکمیل کنید.</span>
          <button type="button" id="show-missing" class="text-xs text-blue-700 font-semibold underline hidden">نمایش موارد باقی‌مانده</button>
        </span>
      </div>
      <div id="submit-error" class="alert alert-error hidden" role="alert"></div>
      <button id="submit-btn" type="button" disabled class="w-full py-4 rounded-xl text-white font-bold text-base transition-all flex items-center justify-center gap-3">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
        <span id="submit-btn-text"><?= $preview ? 'ثبت نهایی (در پیش‌نمایش غیرفعال است)' : 'ثبت نهایی و ارسال به دبیرخانه' ?></span>
      </button>
    </div>
  </section>

  <footer class="text-center text-gray-400 text-xs py-4 no-print">
    <?= e($pageTitle) ?> — <?= e($org) ?>
    <?php if ($support !== ''): ?><div class="mt-1">راه ارتباط با دبیرخانه: <span dir="auto"><?= e($support) ?></span></div><?php endif; ?>
  </footer>
</main>

<?php if ($docHtml !== null): ?>
<!-- the proposal worksheet, readable next to the form -->
<div class="drawer-backdrop no-print" id="doc-backdrop" data-drawer-close></div>
<aside class="drawer no-print" id="doc-drawer" aria-label="متن کاربرگ طرح" aria-hidden="true">
  <div class="flex items-center justify-between gap-3 px-4 py-3 bg-blue-900 text-white">
    <div class="min-w-0">
      <div class="font-bold text-sm">متن کاربرگ طرح</div>
      <div class="text-xs text-blue-200 truncate" dir="auto"><?= e($p['company']) ?></div>
    </div>
    <div class="flex items-center gap-2 shrink-0">
      <?php if ($fileUrl): ?><a class="text-xs underline text-blue-100" href="<?= e($fileUrl) ?>">دانلود Word</a><?php endif; ?>
      <button type="button" class="w-9 h-9 rounded-lg hover:bg-white/10 inline-flex items-center justify-center" data-drawer-close aria-label="بستن"><?= icon('x') ?></button>
    </div>
  </div>
  <div class="flex-1 overflow-y-auto p-4"><?= $docHtml ?></div>
</aside>
<?php endif; ?>

<!-- confirmation before the final submission -->
<div id="confirm-modal" class="modal-backdrop no-print" role="dialog" aria-modal="true" aria-labelledby="confirm-title">
  <div class="bg-white rounded-2xl max-w-lg w-full mx-4 shadow-2xl overflow-hidden">
    <div class="bg-blue-900 text-white px-5 py-3"><h3 id="confirm-title" class="font-bold">ثبت نهایی فرم</h3></div>
    <div class="p-5 text-sm leading-7 text-gray-700">
      <p>پس از ثبت نهایی، فرم برای دبیرخانه ارسال می‌شود و <b>دیگر قابل ویرایش نیست</b> (مگر اینکه دبیرخانه آن را دوباره باز کند).</p>
      <div class="mt-3 rounded-xl bg-gray-50 border border-gray-200 p-3 space-y-1 text-xs">
        <div>سطح طرح: <b id="cf-tier" class="text-blue-800">—</b></div>
        <div>سهم امتیاز شما: <b id="cf-share" class="text-blue-800">—</b></div>
        <div>ارزیابی تخمینی سیستم: <b id="cf-decision" class="text-blue-800">—</b></div>
      </div>
    </div>
    <div class="bg-gray-50 px-5 py-3 flex gap-2 justify-end border-t">
      <button type="button" class="btn btn-secondary" id="confirm-cancel">بازگشت و بازبینی</button>
      <button type="button" class="btn btn-success" id="confirm-ok">ثبت نهایی</button>
    </div>
  </div>
</div>

<script type="application/json" id="form-boot"><?= json_out($boot) ?></script>
<script src="<?= e(asset('js/scoring.js')) ?>"></script>
<script src="<?= e(asset('js/jalali.js')) ?>"></script>
<script src="<?= e(asset('js/form.js')) ?>"></script>
