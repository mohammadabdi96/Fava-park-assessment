<?php
/**
 * Drag & drop upload of proposal files.
 * @var bool $compact
 */
$__max = \Fava\Domain\Settings::maxUploadBytes();
?>
<form method="post" action="<?= e(url('/admin/proposals/upload')) ?>" enctype="multipart/form-data" class="dropzone <?= !empty($compact) ? 'py-5' : 'py-10' ?>" data-dropzone data-max="<?= (int) $__max ?>" tabindex="0" role="button" aria-label="بارگذاری فایل طرح">
  <?= csrf_field() ?>
  <input type="file" name="files[]" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" multiple hidden>
  <div data-idle>
    <div class="mx-auto w-12 h-12 rounded-2xl bg-brand-50 text-brand-700 flex items-center justify-center mb-3"><?= icon('upload', 'w-6 h-6') ?></div>
    <div class="font-bold text-slate-800">فایل Word کاربرگ طرح را این‌جا رها کنید</div>
    <div class="text-sm text-slate-500 mt-1">یا برای انتخاب فایل کلیک کنید — می‌توانید چند فایل را با هم بارگذاری کنید.</div>
    <div class="text-xs text-slate-400 mt-2">فقط قالب <span class="ltr">.docx</span> — حداکثر <?= e(filesize_h($__max)) ?> برای هر فایل</div>
  </div>
  <div data-busy hidden>
    <div class="mx-auto w-10 h-10 rounded-full border-4 border-brand-200 border-t-brand-700 animate-spin mb-3"></div>
    <div class="font-bold text-slate-800">در حال بارگذاری و خواندن فایل…</div>
    <div class="text-sm text-slate-500 mt-1" data-names></div>
  </div>
</form>
