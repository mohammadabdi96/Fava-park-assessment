<?php
/**
 * @var array $flashes
 * @var string|null $notice
 * @var string $username
 */
use Fava\Core\View;
?>
<div class="card w-full max-w-sm p-6">
  <h1 class="text-lg font-extrabold text-slate-900 mb-1">ورود به پنل مدیریت</h1>
  <p class="text-xs text-slate-500 mb-5">این بخش فقط برای مدیر سامانه است. داوران از پیوند اختصاصی خود استفاده می‌کنند.</p>

  <?php if ($notice): ?>
    <div class="alert alert-info mb-4"><span class="shrink-0"><?= icon('info') ?></span><div><?= e($notice) ?></div></div>
  <?php endif; ?>
  <?= View::partial('partials/flashes', ['flashes' => $flashes]) ?>

  <form method="post" action="<?= e(url('/admin/login')) ?>" class="space-y-4">
    <?= csrf_field() ?>
    <div>
      <label class="label" for="username">نام کاربری</label>
      <input class="input ltr" id="username" name="username" value="<?= e($username) ?>" required autofocus autocomplete="username">
    </div>
    <div>
      <label class="label" for="password">رمز عبور</label>
      <input class="input ltr" id="password" name="password" type="password" required autocomplete="current-password">
    </div>
    <button type="submit" class="btn btn-primary w-full"><?= icon('lock', 'w-4 h-4') ?> ورود</button>
  </form>
  <details class="mt-5 text-xs text-slate-500">
    <summary class="link">رمز عبور را فراموش کرده‌اید؟</summary>
    <p class="mt-2 leading-6">در File Manager هاست، فایلی به نام <span class="ltr font-mono">reset-admin.txt</span> در پوشه <span class="ltr font-mono">storage</span> بسازید و رمز جدید (حداقل ۸ نویسه) را در سطر اول آن بنویسید. سپس همین صفحه را تازه کنید؛ رمز مدیر تغییر می‌کند و فایل خودکار حذف می‌شود.</p>
  </details>
</div>
