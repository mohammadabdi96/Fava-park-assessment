<?php
/** @var string $pageTitle */
/** @var string $org */
$__title = trim(($pageTitle ?? '') . ' | ' . ($org ?? 'پارک فاوا'), ' |');
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="same-origin">
<title><?= e($__title) ?></title>
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="preload" href="<?= e(\Fava\Core\Request::basePath() . '/assets/fonts/Vazirmatn-Variable.woff2') ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
