<?php
/**
 * Printable report (A4). «Save as PDF» in the browser's print dialog gives a PDF with real text.
 * @var string $content
 */
use Fava\Core\View;
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<?= View::partial('partials/head', ['pageTitle' => $pageTitle ?? '', 'org' => $org ?? 'پارک فاوا']) ?>
</head>
<body class="bg-slate-100 print:bg-white">
  <div class="no-print sticky top-0 z-10 bg-white border-b border-slate-200">
    <div class="max-w-4xl mx-auto px-4 py-3 flex items-center justify-between gap-3">
      <button type="button" class="btn btn-secondary btn-sm" onclick="history.length > 1 ? history.back() : window.close()"><?= icon('chevron', 'w-4 h-4 rotate-180') ?> بازگشت</button>
      <div class="flex items-center gap-2">
        <span class="text-xs text-slate-500 hidden sm:inline">برای ذخیره PDF، در پنجره چاپ گزینه «Save as PDF» را انتخاب کنید.</span>
        <button type="button" class="btn btn-primary btn-sm" onclick="window.print()"><?= icon('print', 'w-4 h-4') ?> چاپ / ذخیره PDF</button>
      </div>
    </div>
  </div>
  <div class="max-w-4xl mx-auto bg-white my-6 print:my-0 p-8 print:p-0 shadow-soft print:shadow-none rounded-2xl print:rounded-none">
    <?= $content ?>
  </div>
</body>
</html>
