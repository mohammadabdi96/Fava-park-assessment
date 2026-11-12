<?php
/**
 * Stand-alone pages: installer, login, error and invalid-link pages.
 * @var string $content
 */
use Fava\Core\View;
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<?= View::partial('partials/head', ['pageTitle' => $pageTitle ?? '', 'org' => $org ?? 'پارک فاوا']) ?>
</head>
<body class="min-h-screen bg-gradient-to-br from-brand-900 via-brand-800 to-brand-600">
  <div class="min-h-screen flex flex-col items-center justify-center px-4 py-10">
    <div class="flex items-center gap-3 text-white mb-6">
      <?= View::partial('partials/logo', ['class' => 'w-12 h-12']) ?>
      <div>
        <div class="text-lg font-extrabold leading-tight"><?= e($org ?? 'پارک فاوا') ?></div>
        <div class="text-xs text-brand-100">سامانه ارزش‌گذاری طرح‌ها</div>
      </div>
    </div>
    <?= $content ?>
    <p class="mt-6 text-xs text-brand-200">نسخه <?= fa(APP_VERSION) ?></p>
  </div>
</body>
</html>
