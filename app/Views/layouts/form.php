<?php
/**
 * Shell of the judge's pages (the form and the «sent» page).
 * @var string $content
 */
use Fava\Core\View;
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<?= View::partial('partials/head', ['pageTitle' => $pageTitle ?? '', 'org' => $org ?? 'پارک فاوا']) ?>
</head>
<body class="bg-gray-50 font-vazir min-h-screen">
<?= $content ?>
<div id="toast" class="toast" role="status" aria-live="polite"></div>
</body>
</html>
