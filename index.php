<?php
/**
 * Fava Park – Proposal Assessment System
 * Front controller: every request is routed through this file.
 */

define('APP_ROOT', __DIR__);
define('APP_START', microtime(true));

// Fail early, in plain language, if the hosting cannot run the application at all.
$__missing = [];
if (PHP_VERSION_ID < 70400) {
    $__missing[] = 'PHP 7.4 یا بالاتر (نسخه فعلی: ' . PHP_VERSION . ')';
}
foreach (['pdo' => 'PDO', 'mbstring' => 'mbstring', 'json' => 'json', 'dom' => 'dom (XML)', 'zlib' => 'zlib', 'session' => 'session', 'filter' => 'filter'] as $__ext => $__label) {
    if (!extension_loaded($__ext)) {
        $__missing[] = 'افزونه ' . $__label;
    }
}
if ($__missing) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>پیش‌نیازها</title>'
        . '<body style="font-family:Tahoma,sans-serif;padding:2rem;line-height:2">'
        . '<h3>پیش‌نیازهای سرور برای اجرای سامانه فراهم نیست</h3><ul><li>'
        . implode('</li><li>', array_map('htmlspecialchars', $__missing))
        . '</li></ul><p>در cPanel از بخش‌های «MultiPHP Manager» و «Select PHP Version / PHP Extensions» این موارد را فعال کنید.</p></body></html>';
    exit;
}
unset($__missing, $__ext, $__label);

require APP_ROOT . '/app/bootstrap.php';

\Fava\Core\App::run();
