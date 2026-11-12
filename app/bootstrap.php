<?php
/**
 * Bootstrap: paths, autoloader, helpers.
 */

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

const APP_VERSION = '1.0.0';

// Optional hardening: a paths.php next to index.php may move the private
// directories outside the web root:
//   <?php return ['config' => '/home/USER/fava-private/config', 'storage' => '/home/USER/fava-private/storage'];
$__paths = is_file(APP_ROOT . '/paths.php') ? (array) require APP_ROOT . '/paths.php' : [];

define('CONFIG_DIR', rtrim(getenv('FAVA_CONFIG_DIR') ?: ($__paths['config'] ?? APP_ROOT . '/config'), '/\\'));
define('STORAGE_DIR', rtrim(getenv('FAVA_STORAGE_DIR') ?: ($__paths['storage'] ?? APP_ROOT . '/storage'), '/\\'));
unset($__paths);

mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC'); // everything is stored in UTC; display conversion happens in Jalali::format()

spl_autoload_register(function ($class) {
    $prefix = 'Fava\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = APP_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require APP_ROOT . '/app/helpers.php';
