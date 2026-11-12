<?php
/**
 * @var string $tab
 * @var array $s            all settings
 * @var array $mail         mail settings without the password
 * @var bool $hasPassword
 * @var bool $mailReady
 * @var array|null $mailTest
 * @var array $mailLog
 * @var array $scoring      scoring parameters in force
 * @var array $scoringDefaults
 * @var bool $scoringCustom
 * @var array $def
 * @var array $placeholders
 * @var array $admin
 * @var array $system
 * @var string $storageProbe
 * @var string $detectedUrl
 */
$tabs = [
    'general' => ['عمومی', 'cog'],
    'scoring' => ['امتیازدهی', 'scale'],
    'mail' => ['ایمیل', 'mail'],
    'account' => ['حساب مدیر', 'lock'],
    'system' => ['وضعیت سامانه', 'info'],
];
$o = function (string $key, $current) {
    $old = old($key, null);
    return $old !== null ? $old : $current;
};
$n = function ($v) {
    return rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
};
?>
<div class="mb-5">
  <h1 class="text-2xl font-extrabold text-slate-900">تنظیمات</h1>
  <p class="text-sm text-slate-500 mt-1">مشخصات سامانه، قواعد امتیازدهی و ارسال ایمیل</p>
</div>

<div class="flex items-center gap-1 overflow-x-auto border-b border-slate-200 mb-6 text-sm no-print">
  <?php foreach ($tabs as $key => $t): ?>
    <a href="<?= e(url('/admin/settings', ['tab' => $key])) ?>" class="px-4 py-2.5 border-b-2 -mb-px whitespace-nowrap font-medium inline-flex items-center gap-2 <?= $tab === $key ? 'border-brand-700 text-brand-800' : 'border-transparent text-slate-500 hover:text-slate-800' ?>">
      <?= icon($t[1], 'w-4 h-4') ?> <?= e($t[0]) ?>
      <?php if ($key === 'mail' && !$mailReady): ?><span class="dot dot-amber"></span><?php endif; ?>
    </a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'general'): ?>
<form method="post" action="<?= e(url('/admin/settings/general')) ?>" class="card max-w-3xl">
  <?= csrf_field() ?>
  <div class="card-body space-y-5">
    <div class="grid sm:grid-cols-2 gap-4">
      <div>
        <label class="label" for="org_name">نام سازمان <span class="req-star">*</span></label>
        <input class="input" id="org_name" name="org_name" value="<?= e($o('org_name', $s['org_name'])) ?>" required>
      </div>
      <div>
        <label class="label" for="system_title">عنوان سامانه</label>
        <input class="input" id="system_title" name="system_title" value="<?= e($o('system_title', $s['system_title'])) ?>">
      </div>
      <div class="sm:col-span-2">
        <label class="label" for="form_title">عنوان فرم داوران</label>
        <input class="input" id="form_title" name="form_title" value="<?= e($o('form_title', $s['form_title'])) ?>">
      </div>
      <div class="sm:col-span-2">
        <label class="label" for="app_url">نشانی سامانه</label>
        <input class="input ltr" id="app_url" name="app_url" value="<?= e($o('app_url', $s['app_url'])) ?>" placeholder="<?= e($detectedUrl) ?>">
        <p class="hint">پیوند اختصاصی داوران با این نشانی ساخته می‌شود. اگر خالی باشد، نشانی همین صفحه به‌کار می‌رود (<span class="ltr"><?= e($detectedUrl) ?></span>). اگر دامنه یا پوشه نصب را عوض کردید، این‌جا را هم اصلاح کنید.</p>
      </div>
      <div class="sm:col-span-2">
        <label class="label" for="support_contact">راه ارتباط با دبیرخانه <span class="font-normal text-slate-500">(برای داوران)</span></label>
        <input class="input" id="support_contact" name="support_contact" value="<?= e($o('support_contact', $s['support_contact'])) ?>" placeholder="مثلاً: دبیرخانه ارزیابی — ۰۲۱-۰۰۰۰۰۰۰۰ — داخلی ۱۲">
        <p class="hint">پایین فرم داوران و در صفحه «پیوند نامعتبر» نمایش داده می‌شود.</p>
      </div>
    </div>

    <div class="border-t border-slate-100 pt-5">
      <h2 class="section-title mb-3">اعلان به مدیر</h2>
      <div class="grid sm:grid-cols-2 gap-4">
        <div>
          <label class="label" for="notify_email">ایمیل دریافت اعلان‌ها</label>
          <input class="input ltr" id="notify_email" name="notify_email" type="email" value="<?= e($o('notify_email', $s['notify_email'])) ?>" placeholder="admin@example.ir">
        </div>
        <div class="flex items-end">
          <label class="check"><input type="checkbox" name="notify_on_submit" value="1" <?= $s['notify_on_submit'] ? 'checked' : '' ?>><span>هر بار که داوری فرم خود را ثبت می‌کند، به من ایمیل بزن</span></label>
        </div>
      </div>
    </div>

    <div class="border-t border-slate-100 pt-5">
      <h2 class="section-title mb-3">طرح‌های جدید</h2>
      <div class="space-y-2">
        <label class="check"><input type="checkbox" name="judge_show_doc" value="1" <?= $s['judge_show_doc'] ? 'checked' : '' ?>><span>متن کاربرگ در صفحه فرم برای داوران نمایش داده شود</span></label>
        <label class="check"><input type="checkbox" name="judge_allow_download" value="1" <?= $s['judge_allow_download'] ? 'checked' : '' ?>><span>داوران بتوانند فایل Word اصلی را دانلود کنند</span></label>
      </div>
      <p class="hint">این دو گزینه پیش‌فرض طرح‌های تازه است و برای هر طرح جداگانه قابل تغییر است.</p>
      <div class="mt-4 sm:w-1/3">
        <label class="label" for="max_upload_mb">حداکثر حجم هر فایل (مگابایت)</label>
        <input class="input ltr num" id="max_upload_mb" name="max_upload_mb" type="number" min="1" max="200" value="<?= e((string) $o('max_upload_mb', $s['max_upload_mb'])) ?>">
      </div>
    </div>
  </div>
  <div class="px-5 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl"><button class="btn btn-primary" type="submit">ذخیره تنظیمات</button></div>
</form>

<?php elseif ($tab === 'scoring'): ?>
<form method="post" action="<?= e(url('/admin/settings/scoring')) ?>" class="card max-w-4xl">
  <?= csrf_field() ?>
  <div class="card-body space-y-6">
    <div class="alert alert-info">
      <span class="shrink-0"><?= icon('info') ?></span>
      <div>این اعداد قواعد محاسبه را تعیین می‌کنند. تغییر آن‌ها روی طرح‌هایی اثر می‌گذارد که هنوز داوری برایشان تعیین نشده است؛ طرح‌های در حال داوری با همان قواعد زمان شروع محاسبه می‌شوند (در صفحه هر طرح می‌توانید آن را به‌روز کنید).
        <?= $scoringCustom ? '' : '<b>اکنون مقادیر پیش‌فرض فرم در حال استفاده است.</b>' ?></div>
    </div>

    <div>
      <h2 class="section-title mb-3">وزن ابعاد امتیازدهی (درصد)</h2>
      <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <?php foreach (['tech', 'team', 'market', 'finance'] as $dim): $d = $def['dimensions'][$dim]; ?>
          <div>
            <label class="label" for="w_<?= e($dim) ?>"><?= e($d['label']) ?></label>
            <input class="input ltr num" id="w_<?= e($dim) ?>" name="w_<?= e($dim) ?>" inputmode="decimal" value="<?= e((string) $o('w_' . $dim, $n($scoring['weights'][$dim] * 100))) ?>" data-weight>
            <p class="hint"><?= e(role_label($d['role'])) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="hint">مجموع چهار وزن باید ۱۰۰ باشد. <span data-weight-sum class="font-semibold"></span></p>
    </div>

    <div class="border-t border-slate-100 pt-5">
      <h2 class="section-title mb-3">آستانه‌های تصمیم (امتیاز کل از ۱۰۰)</h2>
      <div class="grid sm:grid-cols-2 gap-4">
        <div>
          <label class="label" for="reject_below">کمتر از این امتیاز: رد</label>
          <input class="input ltr num" id="reject_below" name="reject_below" inputmode="decimal" value="<?= e((string) $o('reject_below', $n($scoring['thresholds']['reject_below']))) ?>">
        </div>
        <div>
          <label class="label" for="conditional_below">از این امتیاز به بالا: تأیید</label>
          <input class="input ltr num" id="conditional_below" name="conditional_below" inputmode="decimal" value="<?= e((string) $o('conditional_below', $n($scoring['thresholds']['conditional_below']))) ?>">
          <p class="hint">امتیازهای میان این دو عدد «تأیید مشروط» می‌شوند.</p>
        </div>
      </div>
    </div>

    <div class="border-t border-slate-100 pt-5">
      <h2 class="section-title mb-3">سقف حمایتی هر سطح (میلیارد ریال)</h2>
      <div class="grid sm:grid-cols-3 gap-4">
        <?php foreach ($scoring['tiers'] as $key => $t): ?>
          <div>
            <label class="label" for="ceiling_<?= e($key) ?>"><?= e($t['label']) ?></label>
            <input class="input ltr num" id="ceiling_<?= e($key) ?>" name="ceiling_<?= e($key) ?>" inputmode="decimal" value="<?= e((string) $o('ceiling_' . $key, $n($t['ceiling']))) ?>">
          </div>
        <?php endforeach; ?>
      </div>
      <label class="check mt-3"><input type="checkbox" name="cap_amount" value="1" <?= $scoring['cap_amount'] ? 'checked' : '' ?>><span>مبلغ حمایتی هرگز از «کمترِ مبلغ درخواستی و سقف سطح» بیشتر نشود (حتی اگر ضریب ریسک بزرگ‌تر از ۱ باشد)</span></label>
    </div>

    <div class="border-t border-slate-100 pt-5">
      <h2 class="section-title mb-3">ضریب ریسک</h2>
      <div class="grid sm:grid-cols-2 gap-4">
        <div>
          <label class="label" for="risk_base">ضریب پایه (برای پرریسک‌ترین حالت)</label>
          <input class="input ltr num" id="risk_base" name="risk_base" inputmode="decimal" value="<?= e((string) $o('risk_base', $n($scoring['risk']['base']))) ?>">
        </div>
        <div>
          <label class="label" for="risk_step">افزایش به‌ازای هر واحد امتیاز ریسک</label>
          <input class="input ltr num" id="risk_step" name="risk_step" inputmode="decimal" value="<?= e((string) $o('risk_step', $n($scoring['risk']['step']))) ?>">
        </div>
      </div>
      <p class="hint">ضریب = پایه + (میانگین سه ریسک − ۱) × گام. با مقادیر فعلی، ضریب بین <b class="num"><?= nf($scoring['risk']['base']) ?></b> و <b class="num"><?= nf($scoring['risk']['base'] + 4 * $scoring['risk']['step']) ?></b> خواهد بود.</p>
    </div>

    <div class="border-t border-slate-100 pt-5">
      <h2 class="section-title mb-3">پرداخت مرحله‌ای (درصد)</h2>
      <div class="grid sm:grid-cols-3 gap-4">
        <?php foreach ($def['milestones'] as $i => $ms): ?>
          <div>
            <label class="label" for="pay_<?= $i + 1 ?>"><?= e($ms[0]) ?></label>
            <input class="input ltr num" id="pay_<?= $i + 1 ?>" name="pay_<?= $i + 1 ?>" inputmode="decimal" value="<?= e((string) $o('pay_' . ($i + 1), $n($scoring['payment'][$i]))) ?>">
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="px-5 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl flex items-center justify-between gap-3 flex-wrap">
    <button class="btn btn-primary" type="submit" name="action" value="save">ذخیره پارامترها</button>
    <?php if ($scoringCustom): ?>
      <button class="btn btn-ghost btn-sm" type="submit" name="action" value="reset" formnovalidate onclick="return confirm('همه پارامترها به مقادیر پیش‌فرض فرم برگردد؟')">بازگشت به مقادیر پیش‌فرض</button>
    <?php endif; ?>
  </div>
</form>

<?php elseif ($tab === 'mail'): ?>
<div class="grid xl:grid-cols-2 gap-6 items-start">
  <div class="space-y-6">
    <form method="post" action="<?= e(url('/admin/settings/mail')) ?>" class="card">
      <?= csrf_field() ?>
      <input type="hidden" name="section" value="smtp">
      <div class="card-head">
        <h2 class="card-title">صندوق ایمیل فرستنده</h2>
        <?= $mailReady ? badge('پیکربندی شده', 'emerald') : badge('پیکربندی نشده', 'amber') ?>
      </div>
      <div class="card-body space-y-4">
        <p class="text-xs text-slate-500 leading-6">در cPanel از بخش «Email Accounts» یک صندوق (مثلاً <span class="ltr">assessment@example.ir</span>) بسازید و مشخصات آن را این‌جا وارد کنید. اطلاعات اتصال را در همان صفحه، زیر «Connect Devices» می‌بینید.</p>
        <div>
          <label class="label" for="transport">روش ارسال</label>
          <select class="input" id="transport" name="transport" data-transport>
            <option value="smtp" <?= $mail['transport'] === 'smtp' ? 'selected' : '' ?>>SMTP — صندوق ایمیل هاست (پیشنهادی)</option>
            <option value="mail" <?= $mail['transport'] === 'mail' ? 'selected' : '' ?>>تابع mail()‎ در PHP</option>
            <option value="none" <?= $mail['transport'] === 'none' ? 'selected' : '' ?>>غیرفعال — فقط کپی پیوند</option>
          </select>
        </div>
        <div class="grid sm:grid-cols-2 gap-4" data-when-transport="smtp mail">
          <div>
            <label class="label" for="from_email">ایمیل فرستنده <span class="req-star">*</span></label>
            <input class="input ltr" id="from_email" name="from_email" type="email" value="<?= e((string) $o('from_email', $mail['from_email'])) ?>" placeholder="assessment@example.ir">
          </div>
          <div>
            <label class="label" for="from_name">نام فرستنده</label>
            <input class="input" id="from_name" name="from_name" value="<?= e((string) $o('from_name', $mail['from_name'])) ?>" placeholder="<?= e($s['org_name']) ?>">
          </div>
        </div>
        <div class="space-y-4" data-when-transport="smtp">
          <div class="grid sm:grid-cols-3 gap-4">
            <div class="sm:col-span-2">
              <label class="label" for="host">سرور SMTP <span class="req-star">*</span></label>
              <input class="input ltr" id="host" name="host" value="<?= e((string) $o('host', $mail['host'])) ?>" placeholder="mail.example.ir">
            </div>
            <div>
              <label class="label" for="port">درگاه</label>
              <input class="input ltr num" id="port" name="port" type="number" value="<?= e((string) $o('port', $mail['port'])) ?>">
            </div>
          </div>
          <div>
            <label class="label" for="encryption">رمزنگاری</label>
            <select class="input" id="encryption" name="encryption" data-encryption>
              <option value="ssl" data-port="465" <?= $mail['encryption'] === 'ssl' ? 'selected' : '' ?>>SSL/TLS — درگاه ۴۶۵ (رایج در cPanel)</option>
              <option value="tls" data-port="587" <?= $mail['encryption'] === 'tls' ? 'selected' : '' ?>>STARTTLS — درگاه ۵۸۷</option>
              <option value="none" data-port="25" <?= $mail['encryption'] === 'none' ? 'selected' : '' ?>>بدون رمزنگاری — درگاه ۲۵</option>
            </select>
          </div>
          <div class="grid sm:grid-cols-2 gap-4">
            <div>
              <label class="label" for="username">نام کاربری</label>
              <input class="input ltr" id="username" name="username" value="<?= e((string) $o('username', $mail['username'])) ?>" placeholder="assessment@example.ir" autocomplete="off">
              <p class="hint">معمولاً همان نشانی کامل ایمیل است.</p>
            </div>
            <div>
              <label class="label" for="password">رمز عبور صندوق</label>
              <input class="input ltr" id="password" name="password" type="password" autocomplete="new-password" placeholder="<?= $hasPassword ? '•••••••• (ذخیره شده)' : '' ?>">
              <p class="hint"><?= $hasPassword ? 'برای نگه داشتن رمز فعلی، خالی بگذارید.' : 'رمز به‌صورت رمزنگاری‌شده ذخیره می‌شود.' ?></p>
            </div>
          </div>
          <label class="check"><input type="checkbox" name="verify_peer" value="1" <?= $mail['verify_peer'] ? 'checked' : '' ?>><span>گواهی امنیتی سرور ایمیل بررسی شود <span class="text-slate-500">(اگر با خطای گواهی روبه‌رو شدید و سرور ایمیل روی همین هاست است، خاموش کنید)</span></span></label>
        </div>
      </div>
      <div class="px-5 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl"><button class="btn btn-primary" type="submit">ذخیره تنظیمات ایمیل</button></div>
    </form>

    <form method="post" action="<?= e(url('/admin/settings/test-mail')) ?>" class="card">
      <?= csrf_field() ?>
      <div class="card-head"><h2 class="card-title">ارسال ایمیل آزمایشی</h2></div>
      <div class="card-body space-y-3">
        <div class="flex items-end gap-2 flex-wrap">
          <div class="flex-1 min-w-[14rem]">
            <label class="label" for="test-to">گیرنده آزمایشی</label>
            <input class="input ltr" id="test-to" name="to" type="email" required value="<?= e((string) ($mailTest['to'] ?? ($admin['email'] ?? ''))) ?>" placeholder="you@example.ir">
          </div>
          <button class="btn btn-secondary" type="submit" <?= $mailReady ? '' : 'disabled' ?>><?= icon('send', 'w-4 h-4') ?> ارسال</button>
        </div>
        <?php if (!$mailReady): ?><p class="hint">ابتدا تنظیمات بالا را ذخیره کنید.</p><?php endif; ?>
        <?php if ($mailTest): ?>
          <div class="alert <?= $mailTest['ok'] ? 'alert-success' : 'alert-error' ?>">
            <div class="min-w-0 flex-1">
              <b><?= $mailTest['ok'] ? 'سرور ایمیل پیام را پذیرفت.' : 'ارسال ناموفق بود.' ?></b>
              <?php if (!$mailTest['ok']): ?><div class="mt-1"><?= e($mailTest['error']) ?></div><?php endif; ?>
              <?php if (!empty($mailTest['log'])): ?>
                <details class="mt-2"><summary class="text-xs underline">جزئیات گفت‌وگو با سرور</summary>
                  <pre class="ltr text-[11px] leading-5 bg-white/70 rounded-lg p-2 mt-1 overflow-x-auto"><?= e(implode("\n", $mailTest['log'])) ?></pre>
                </details>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="space-y-6">
    <form method="post" action="<?= e(url('/admin/settings/mail')) ?>" class="card">
      <?= csrf_field() ?>
      <input type="hidden" name="section" value="templates">
      <div class="card-head"><h2 class="card-title">متن ایمیل‌ها</h2></div>
      <div class="card-body space-y-4">
        <div class="rounded-xl bg-slate-50 border border-slate-100 p-3 text-xs text-slate-600 leading-7">
          در متن می‌توانید از این نشانه‌ها استفاده کنید؛ هنگام ارسال با مقدار واقعی جایگزین می‌شوند:
          <div class="flex flex-wrap gap-x-4 gap-y-1 mt-1">
            <?php foreach ($placeholders as $key => $label): ?>
              <span><code class="ltr font-mono text-brand-800 bg-white border border-slate-200 rounded px-1"><?= e($key) ?></code> <?= e($label) ?></span>
            <?php endforeach; ?>
          </div>
          <div class="mt-1">دکمه و نشانی پیوند فرم همیشه خودکار به پایین ایمیل اضافه می‌شود.</div>
        </div>
        <div>
          <label class="label" for="mail_invite_subject">موضوع دعوت‌نامه</label>
          <input class="input" id="mail_invite_subject" name="mail_invite_subject" value="<?= e($s['mail_invite_subject']) ?>">
        </div>
        <div>
          <label class="label" for="mail_invite_body">متن دعوت‌نامه</label>
          <textarea class="input leading-7" id="mail_invite_body" name="mail_invite_body" rows="9"><?= e($s['mail_invite_body']) ?></textarea>
        </div>
        <div>
          <label class="label" for="mail_reminder_subject">موضوع یادآوری</label>
          <input class="input" id="mail_reminder_subject" name="mail_reminder_subject" value="<?= e($s['mail_reminder_subject']) ?>">
        </div>
        <div>
          <label class="label" for="mail_reminder_body">متن یادآوری</label>
          <textarea class="input leading-7" id="mail_reminder_body" name="mail_reminder_body" rows="6"><?= e($s['mail_reminder_body']) ?></textarea>
        </div>
        <p class="hint">برای برگرداندن هر متن به حالت اولیه، آن را خالی کنید و ذخیره را بزنید.</p>
      </div>
      <div class="px-5 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl"><button class="btn btn-primary" type="submit">ذخیره متن‌ها</button></div>
    </form>

    <?php if ($mailLog): ?>
    <section class="card overflow-hidden">
      <div class="card-head"><h2 class="card-title">آخرین ایمیل‌ها</h2></div>
      <div class="overflow-x-auto">
        <table class="tbl text-xs">
          <thead><tr><th>زمان</th><th>گیرنده</th><th>نوع</th><th>نتیجه</th></tr></thead>
          <tbody>
          <?php $kinds = ['invite' => 'دعوت‌نامه', 'reminder' => 'یادآوری', 'admin_notice' => 'اعلان مدیر', 'test' => 'آزمایشی'];
          foreach ($mailLog as $m): ?>
            <tr>
              <td class="num whitespace-nowrap"><?= e(jdate($m['created_at'])) ?></td>
              <td class="ltr text-end"><?= e($m['to_email']) ?></td>
              <td><?= e($kinds[$m['kind']] ?? (string) $m['kind']) ?></td>
              <td><?= $m['status'] === 'sent' ? badge('موفق', 'emerald') : badge('ناموفق', 'red') ?><?php if ($m['error']): ?><span class="block text-red-600 mt-1"><?= e(str_limit($m['error'], 120)) ?></span><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
    <?php endif; ?>
  </div>
</div>

<?php elseif ($tab === 'account'): ?>
<form method="post" action="<?= e(url('/admin/settings/password')) ?>" class="card max-w-xl" autocomplete="off">
  <?= csrf_field() ?>
  <div class="card-body space-y-4">
    <div class="grid sm:grid-cols-2 gap-4">
      <div>
        <label class="label" for="a-name">نام و نام خانوادگی</label>
        <input class="input" id="a-name" name="name" value="<?= e((string) $o('name', $admin['name'])) ?>" required>
      </div>
      <div>
        <label class="label">نام کاربری</label>
        <input class="input ltr" value="<?= e($admin['username']) ?>" disabled>
      </div>
    </div>
    <div>
      <label class="label" for="a-email">ایمیل</label>
      <input class="input ltr" id="a-email" name="email" type="email" value="<?= e((string) $o('email', $admin['email'])) ?>">
    </div>
    <div class="border-t border-slate-100 pt-4">
      <h2 class="section-title mb-3">تغییر رمز عبور</h2>
      <p class="hint mb-3">اگر نمی‌خواهید رمز را عوض کنید، این سه کادر را خالی بگذارید.</p>
      <div class="space-y-3">
        <div>
          <label class="label" for="a-current">رمز عبور فعلی</label>
          <input class="input ltr" id="a-current" name="current_password" type="password" autocomplete="current-password">
        </div>
        <div class="grid sm:grid-cols-2 gap-4">
          <div>
            <label class="label" for="a-new">رمز عبور جدید</label>
            <input class="input ltr" id="a-new" name="password" type="password" minlength="8" autocomplete="new-password">
          </div>
          <div>
            <label class="label" for="a-confirm">تکرار رمز جدید</label>
            <input class="input ltr" id="a-confirm" name="password_confirm" type="password" minlength="8" autocomplete="new-password">
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="px-5 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl"><button class="btn btn-primary" type="submit">ذخیره</button></div>
</form>

<?php else: ?>
<div class="grid lg:grid-cols-2 gap-6 items-start">
  <section class="card">
    <div class="card-head"><h2 class="card-title">وضعیت سامانه</h2></div>
    <div class="card-body">
      <table class="kv">
        <?php foreach ($system as $label => $value): ?>
          <tr><th><?= e($label) ?></th><td dir="auto" class="break-all"><?= e($value) ?></td></tr>
        <?php endforeach; ?>
      </table>
    </div>
  </section>
  <section class="card">
    <div class="card-head"><h2 class="card-title">بررسی امنیت و نگهداری</h2></div>
    <div class="card-body space-y-4 text-sm leading-7 text-slate-700">
      <div class="alert alert-info" id="storage-status"><span class="shrink-0"><?= icon('lock') ?></span><div data-text>در حال بررسی دسترسی اینترنتی به پوشه‌های خصوصی…</div></div>
      <div>
        <h3 class="font-bold text-slate-900">پشتیبان‌گیری</h3>
        <p>برای پشتیبان کامل، این دو مورد را نگه دارید: پایگاه‌داده (از phpMyAdmin یا ابزار Backup در cPanel) و پوشه <span class="ltr font-mono">storage/uploads</span> که فایل‌های Word طرح‌ها در آن است. فایل <span class="ltr font-mono">config/config.php</span> را هم همراه آن‌ها نگه دارید.</p>
      </div>
      <div>
        <h3 class="font-bold text-slate-900">فراموشی رمز مدیر</h3>
        <p>فایلی به نام <span class="ltr font-mono">reset-admin.txt</span> با رمز جدید در پوشه <span class="ltr font-mono">storage</span> بسازید و صفحه ورود را باز کنید.</p>
      </div>
      <div>
        <h3 class="font-bold text-slate-900">گزارش خطاها</h3>
        <p>خطاهای سامانه در <span class="ltr font-mono">storage/logs</span> ثبت می‌شود. اگر صفحه‌ای با خطا روبه‌رو شد، آخرین سطرهای فایل همان ماه را برای پشتیبان فنی بفرستید.</p>
      </div>
    </div>
  </section>
</div>
<script>
fetch(<?= json_out($storageProbe) ?>, { cache: 'no-store' }).then(function (r) { return r.ok ? r.text() : ''; }).then(function (t) {
  var box = document.getElementById('storage-status'), open = t.indexOf('fava-storage-probe') !== -1;
  box.className = 'alert ' + (open ? 'alert-error' : 'alert-success');
  box.querySelector('[data-text]').innerHTML = open
    ? '<b>هشدار:</b> پوشه storage از اینترنت قابل مشاهده است. مطمئن شوید فایل‌های ‎.htaccess بارگذاری شده‌اند، یا طبق راهنمای نصب پوشه‌های config و storage را به بیرون از public_html منتقل کنید.'
    : 'پوشه‌های خصوصی (config و storage) از اینترنت قابل مشاهده نیستند.';
}).catch(function () {});
</script>
<?php endif; ?>
