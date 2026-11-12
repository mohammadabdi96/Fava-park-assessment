<?php
/**
 * @var array $checks  [label, ok, required, detail]
 * @var array $old
 * @var array $errors
 * @var bool $hasMysql
 * @var bool $hasSqlite
 * @var string $rewriteProbe
 * @var string $storageProbe
 */
$blocked = false;
foreach ($checks as $c) {
    if ($c[2] && !$c[1]) {
        $blocked = true;
    }
}
$err = function (string $key) use ($errors) {
    return isset($errors[$key]) ? '<p class="text-xs text-red-600 mt-1 font-medium">' . e($errors[$key]) . '</p>' : '';
};
?>
<div class="card w-full max-w-3xl overflow-hidden">
  <div class="px-6 py-5 border-b border-slate-100">
    <h1 class="text-xl font-extrabold text-slate-900">نصب سامانه ارزش‌گذاری طرح‌ها</h1>
    <p class="text-sm text-slate-500 mt-1">این صفحه فقط یک بار نمایش داده می‌شود. پس از نصب، با نام کاربری و رمزی که این‌جا تعیین می‌کنید وارد پنل مدیریت می‌شوید.</p>
  </div>

  <form method="post" action="<?= e(url('/install')) ?>" class="p-6 space-y-8" autocomplete="off" id="install-form">
    <input type="hidden" name="pretty_urls" id="pretty_urls" value="0" hidden>

    <?php if (!empty($errors['db']) || !empty($errors['checks'])): ?>
      <div class="alert alert-error"><span class="shrink-0"><?= icon('warn') ?></span><div><?= e($errors['db'] ?? $errors['checks']) ?></div></div>
    <?php endif; ?>

    <!-- 1 -->
    <section>
      <h2 class="section-title mb-3">۱. بررسی سرور</h2>
      <ul class="grid sm:grid-cols-2 gap-x-6 gap-y-1.5 text-sm">
        <?php foreach ($checks as $c): ?>
          <li class="flex items-start gap-2">
            <?php if ($c[1]): ?>
              <span class="text-emerald-600 mt-0.5"><?= icon('check-circle', 'w-4 h-4') ?></span>
            <?php elseif ($c[2]): ?>
              <span class="text-red-600 mt-0.5"><?= icon('x', 'w-4 h-4') ?></span>
            <?php else: ?>
              <span class="text-amber-500 mt-0.5"><?= icon('warn', 'w-4 h-4') ?></span>
            <?php endif; ?>
            <span class="<?= $c[1] ? 'text-slate-700' : ($c[2] ? 'text-red-700 font-semibold' : 'text-amber-700') ?>">
              <?= e($c[0]) ?>
              <?php if ($c[3] !== ''): ?><span class="block text-xs text-slate-400 font-normal" dir="auto"><?= e($c[3]) ?></span><?php endif; ?>
            </span>
          </li>
        <?php endforeach; ?>
        <li class="flex items-start gap-2" id="rewrite-check">
          <span class="text-slate-400 mt-0.5" data-icon><?= icon('info', 'w-4 h-4') ?></span>
          <span class="text-slate-700">نشانی‌های خوانا (mod_rewrite)<span class="block text-xs text-slate-400" data-text>در حال بررسی…</span></span>
        </li>
        <li class="flex items-start gap-2" id="storage-check">
          <span class="text-slate-400 mt-0.5" data-icon><?= icon('info', 'w-4 h-4') ?></span>
          <span class="text-slate-700">محافظت از پوشه‌های خصوصی<span class="block text-xs text-slate-400" data-text>در حال بررسی…</span></span>
        </li>
      </ul>
      <?php if ($blocked): ?>
        <div class="alert alert-error mt-4"><span class="shrink-0"><?= icon('warn') ?></span><div>موارد قرمز باید پیش از نصب برطرف شوند. در cPanel از «Select PHP Version» نسخه و افزونه‌های PHP را تنظیم کنید و سطح دسترسی پوشه‌های <span class="ltr">config</span> و <span class="ltr">storage</span> را روی 755 بگذارید؛ سپس این صفحه را تازه کنید.</div></div>
      <?php endif; ?>
    </section>

    <!-- 2 -->
    <section>
      <h2 class="section-title mb-3">۲. پایگاه‌داده</h2>
      <div class="grid sm:grid-cols-2 gap-3 mb-4">
        <label class="radio-card <?= $hasMysql ? '' : 'opacity-50' ?>">
          <input type="radio" name="driver" value="mysql" class="w-4 h-4 mt-1" <?= $old['driver'] === 'mysql' ? 'checked' : '' ?> <?= $hasMysql ? '' : 'disabled' ?>>
          <span class="text-sm"><span class="block font-semibold text-slate-800">MySQL / MariaDB <span class="badge badge-emerald ms-1">پیشنهادی</span></span><span class="block text-xs text-slate-500 mt-0.5">پایگاه‌داده‌ای که در cPanel می‌سازید.</span></span>
        </label>
        <label class="radio-card <?= $hasSqlite ? '' : 'opacity-50' ?>">
          <input type="radio" name="driver" value="sqlite" class="w-4 h-4 mt-1" <?= $old['driver'] === 'sqlite' ? 'checked' : '' ?> <?= $hasSqlite ? '' : 'disabled' ?>>
          <span class="text-sm"><span class="block font-semibold text-slate-800">SQLite</span><span class="block text-xs text-slate-500 mt-0.5">بدون نیاز به ساخت پایگاه‌داده؛ یک فایل در پوشه storage.</span></span>
        </label>
      </div>
      <div id="mysql-fields" class="grid sm:grid-cols-2 gap-4">
        <div>
          <label class="label" for="db_name">نام پایگاه‌داده <span class="req-star">*</span></label>
          <input class="input ltr" id="db_name" name="db_name" value="<?= e($old['db_name']) ?>" placeholder="cpuser_fava">
          <?= $err('db_name') ?>
        </div>
        <div>
          <label class="label" for="db_user">نام کاربری پایگاه‌داده <span class="req-star">*</span></label>
          <input class="input ltr" id="db_user" name="db_user" value="<?= e($old['db_user']) ?>" placeholder="cpuser_favauser">
          <?= $err('db_user') ?>
        </div>
        <div>
          <label class="label" for="db_pass">رمز عبور پایگاه‌داده</label>
          <input class="input ltr" id="db_pass" name="db_pass" type="password" autocomplete="new-password">
        </div>
        <div>
          <label class="label" for="db_host">میزبان</label>
          <input class="input ltr" id="db_host" name="db_host" value="<?= e($old['db_host']) ?>">
          <p class="hint">در بیشتر هاست‌های cPanel همان <span class="ltr">localhost</span> است.</p>
        </div>
      </div>
      <div class="mt-4 sm:w-1/2 sm:pe-2">
        <label class="label" for="db_prefix">پیشوند جدول‌ها</label>
        <input class="input ltr" id="db_prefix" name="db_prefix" value="<?= e($old['db_prefix']) ?>">
        <?= $err('db_prefix') ?>
      </div>
      <p class="hint mt-3" id="mysql-help">در cPanel از بخش «MySQL® Databases» یک پایگاه‌داده و یک کاربر بسازید و کاربر را با گزینه <span class="ltr">ALL PRIVILEGES</span> به پایگاه‌داده اضافه کنید.</p>
    </section>

    <!-- 3 -->
    <section>
      <h2 class="section-title mb-3">۳. مشخصات سامانه</h2>
      <div class="grid sm:grid-cols-2 gap-4">
        <div>
          <label class="label" for="org_name">نام سازمان</label>
          <input class="input" id="org_name" name="org_name" value="<?= e($old['org_name']) ?>">
        </div>
        <div>
          <label class="label" for="app_url">نشانی سامانه</label>
          <input class="input ltr" id="app_url" name="app_url" value="<?= e($old['app_url']) ?>" placeholder="https://example.ir/assessment">
          <p class="hint">پیوندهایی که برای داوران ایمیل می‌شود با این نشانی ساخته می‌شود.</p>
          <?= $err('app_url') ?>
        </div>
      </div>
    </section>

    <!-- 4 -->
    <section>
      <h2 class="section-title mb-3">۴. حساب مدیر سامانه</h2>
      <div class="grid sm:grid-cols-2 gap-4">
        <div>
          <label class="label" for="admin_name">نام و نام خانوادگی <span class="req-star">*</span></label>
          <input class="input" id="admin_name" name="admin_name" value="<?= e($old['admin_name']) ?>" required>
          <?= $err('admin_name') ?>
        </div>
        <div>
          <label class="label" for="admin_email">ایمیل</label>
          <input class="input ltr" id="admin_email" name="admin_email" type="email" value="<?= e($old['admin_email']) ?>" placeholder="admin@example.ir">
          <p class="hint">اعلان ثبت فرم داوران به این نشانی فرستاده می‌شود.</p>
          <?= $err('admin_email') ?>
        </div>
        <div>
          <label class="label" for="admin_user">نام کاربری <span class="req-star">*</span></label>
          <input class="input ltr" id="admin_user" name="admin_user" value="<?= e($old['admin_user']) ?>" required autocomplete="username">
          <?= $err('admin_user') ?>
        </div>
        <div></div>
        <div>
          <label class="label" for="admin_pass">رمز عبور <span class="req-star">*</span></label>
          <input class="input ltr" id="admin_pass" name="admin_pass" type="password" required minlength="8" autocomplete="new-password">
          <p class="hint">حداقل ۸ نویسه.</p>
          <?= $err('admin_pass') ?>
        </div>
        <div>
          <label class="label" for="admin_pass2">تکرار رمز عبور <span class="req-star">*</span></label>
          <input class="input ltr" id="admin_pass2" name="admin_pass2" type="password" required minlength="8" autocomplete="new-password">
          <?= $err('admin_pass2') ?>
        </div>
      </div>
    </section>

    <div class="flex items-center justify-between gap-4 pt-2 border-t border-slate-100">
      <p class="text-xs text-slate-500">با نصب، جدول‌های سامانه ساخته و فایل <span class="ltr">config/config.php</span> نوشته می‌شود.</p>
      <button type="submit" class="btn btn-primary btn-lg" <?= $blocked ? 'disabled' : '' ?>>نصب سامانه</button>
    </div>
  </form>
</div>

<script>
(function () {
  var ok = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
  function mark(id, good, text, tone) {
    var li = document.getElementById(id);
    li.querySelector('[data-icon]').className = (good ? 'text-emerald-600' : (tone === 'red' ? 'text-red-600' : 'text-amber-500')) + ' mt-0.5';
    if (good) li.querySelector('[data-icon]').innerHTML = ok;
    var t = li.querySelector('[data-text]');
    t.textContent = text;
    t.className = 'block text-xs ' + (good ? 'text-slate-400' : (tone === 'red' ? 'text-red-600 font-semibold' : 'text-amber-700'));
  }
  // 1) does URL rewriting work?
  fetch(<?= json_out($rewriteProbe) ?>, { headers: { 'Accept': 'application/json' }, cache: 'no-store' })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (d) {
      var good = !!(d && d.rewrite === 'fava');
      document.getElementById('pretty_urls').value = good ? '1' : '0';
      mark('rewrite-check', good, good ? 'فعال است؛ نشانی‌ها کوتاه و خوانا خواهند بود.' : 'در دسترس نیست؛ سامانه با نشانی‌های index.php?r=… کار می‌کند (مشکلی نیست).');
    })
    .catch(function () {
      mark('rewrite-check', false, 'در دسترس نیست؛ سامانه با نشانی‌های index.php?r=… کار می‌کند (مشکلی نیست).');
    });
  // 2) are the private folders closed to the web?
  fetch(<?= json_out($storageProbe) ?>, { cache: 'no-store' })
    .then(function (r) { return r.ok ? r.text() : ''; })
    .then(function (t) {
      var open = t.indexOf('fava-storage-probe') !== -1;
      mark('storage-check', !open, open
        ? 'پوشه storage از اینترنت قابل مشاهده است! فایل ‎.htaccess را هم بارگذاری کنید یا طبق راهنما پوشه‌های config و storage را بیرون از public_html ببرید.'
        : 'پوشه‌های config و storage از اینترنت بسته‌اند.', 'red');
    })
    .catch(function () { mark('storage-check', true, 'پوشه‌های config و storage از اینترنت بسته‌اند.'); });

  // show MySQL fields only when MySQL is chosen
  function sync() {
    var mysql = (document.querySelector('input[name="driver"]:checked') || {}).value !== 'sqlite';
    document.getElementById('mysql-fields').hidden = !mysql;
    document.getElementById('mysql-help').hidden = !mysql;
  }
  document.querySelectorAll('input[name="driver"]').forEach(function (el) { el.addEventListener('change', sync); });
  sync();
  document.getElementById('install-form').addEventListener('submit', function (e) {
    var b = e.target.querySelector('button[type="submit"]');
    b.disabled = true; b.textContent = 'در حال نصب…';
  });
})();
</script>
