<?php
/**
 * @var int $status
 * @var string $message
 * @var string $org
 */
$__titles = [403 => 'دسترسی مجاز نیست', 404 => 'صفحه پیدا نشد', 405 => 'عملیات مجاز نیست', 413 => 'حجم اطلاعات زیاد است', 419 => 'نشست منقضی شده است', 500 => 'خطای سامانه'];
$__title = $__titles[$status] ?? 'خطا';
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($__title) ?> | <?= e($org) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="min-h-screen bg-slate-100 flex items-center justify-center p-4">
  <div class="card max-w-lg w-full p-8 text-center">
    <div class="mx-auto w-14 h-14 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center mb-4"><?= icon('warn', 'w-8 h-8') ?></div>
    <div class="text-xs font-bold text-slate-400 num ltr"><?= e((string) $status) ?></div>
    <h1 class="text-xl font-extrabold text-slate-900 mt-1"><?= e($__title) ?></h1>
    <p class="text-sm text-slate-600 mt-3 leading-7 whitespace-pre-line text-start" dir="auto"><?= e($message) ?></p>
    <div class="mt-6 flex items-center justify-center gap-2">
      <button type="button" class="btn btn-secondary" onclick="history.back()">بازگشت</button>
      <?php if (in_array($status, [401, 419], true)): ?><a class="btn btn-primary" href="<?= e(url('/admin')) ?>">ورود دوباره</a><?php endif; ?>
    </div>
    <p class="text-xs text-slate-400 mt-6"><?= e($org) ?></p>
  </div>
</body>
</html>
