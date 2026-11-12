<?php /** @var string $support */ ?>
<div class="card w-full max-w-md p-8 text-center">
  <div class="mx-auto w-14 h-14 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center mb-4"><?= icon('link', 'w-8 h-8') ?></div>
  <h1 class="text-lg font-extrabold text-slate-900">این پیوند معتبر نیست</h1>
  <p class="text-sm text-slate-600 mt-3 leading-7">ممکن است پیوند ناقص کپی شده باشد یا مدیر سامانه پیوند تازه‌ای برای شما ساخته باشد. لطفاً آخرین ایمیل دعوت را باز کنید یا از دبیرخانه بخواهید پیوند را دوباره بفرستد.</p>
  <?php if ($support !== ''): ?>
    <p class="text-sm text-slate-700 mt-4 bg-slate-50 rounded-lg p-3">راه ارتباط با دبیرخانه: <span class="font-semibold" dir="auto"><?= e($support) ?></span></p>
  <?php endif; ?>
</div>
