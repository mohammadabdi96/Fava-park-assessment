<?php
/**
 * Admin panel shell.
 * @var string $content
 * @var string $nav
 * @var string $adminName
 * @var string $org
 * @var string $systemTitle
 * @var array $flashes
 */
use Fava\Core\View;

$__menu = [
    'dashboard' => ['/admin', 'داشبورد', 'home'],
    'proposals' => ['/admin/proposals', 'طرح‌ها', 'doc'],
    'judges' => ['/admin/judges', 'داوران', 'users'],
    'activity' => ['/admin/activity', 'گزارش فعالیت‌ها', 'clock'],
    'settings' => ['/admin/settings', 'تنظیمات', 'cog'],
];
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<?= View::partial('partials/head', ['pageTitle' => $pageTitle ?? '', 'org' => $org]) ?>
</head>
<body class="min-h-screen">
<div class="lg:flex min-h-screen">

  <!-- mobile top bar -->
  <div class="lg:hidden no-print sticky top-0 z-40 flex items-center justify-between bg-brand-900 text-white px-4 py-3">
    <div class="flex items-center gap-2 font-bold text-sm">
      <?= View::partial('partials/logo', ['class' => 'w-8 h-8']) ?>
      <span><?= e($org) ?></span>
    </div>
    <button type="button" class="p-2 rounded-lg hover:bg-white/10" data-toggle="#sidebar" aria-label="منو"><?= icon('menu', 'w-6 h-6') ?></button>
  </div>

  <aside id="sidebar" class="no-print hidden lg:flex lg:flex-col lg:w-64 lg:shrink-0 lg:sticky lg:top-0 lg:h-screen bg-gradient-to-b from-brand-900 to-brand-800 text-white p-4">
    <a href="<?= e(url('/admin')) ?>" class="hidden lg:flex items-center gap-3 px-2 py-3 mb-4">
      <?= View::partial('partials/logo', ['class' => 'w-11 h-11']) ?>
      <span>
        <span class="block font-extrabold leading-tight"><?= e($org) ?></span>
        <span class="block text-xs text-brand-200 mt-0.5"><?= e($systemTitle) ?></span>
      </span>
    </a>
    <nav class="space-y-1 flex-1">
      <?php foreach ($__menu as $key => $m): ?>
        <a href="<?= e(url($m[0])) ?>" class="nav-link <?= $nav === $key ? 'active' : '' ?>"><?= icon($m[2]) ?><span><?= e($m[1]) ?></span></a>
      <?php endforeach; ?>
    </nav>
    <div class="mt-4 pt-4 border-t border-white/10">
      <div class="px-2 text-xs text-brand-200">مدیر سامانه</div>
      <div class="px-2 text-sm font-semibold truncate"><?= e($adminName) ?></div>
      <form method="post" action="<?= e(url('/admin/logout')) ?>" class="mt-2">
        <?= csrf_field() ?>
        <button type="submit" class="nav-link w-full"><?= icon('logout') ?><span>خروج</span></button>
      </form>
    </div>
  </aside>

  <div class="flex-1 min-w-0">
    <main class="px-4 py-6 lg:px-8 lg:py-8 max-w-7xl mx-auto">
      <?= View::partial('partials/flashes', ['flashes' => $flashes]) ?>
      <?= $content ?>
    </main>
  </div>
</div>
<div id="toast" class="toast" role="status" aria-live="polite"></div>
<script src="<?= e(asset('js/jalali.js')) ?>"></script>
<script src="<?= e(asset('js/admin.js')) ?>"></script>
</body>
</html>
