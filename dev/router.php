<?php
/**
 * Development only – router for PHP's built-in web server.
 *
 *   php -S 127.0.0.1:8080 dev/router.php        (run from the project root)
 *
 * It imitates the rules of .htaccess: private folders are closed, existing asset
 * files are served as they are and everything else goes to index.php.
 * Never use the built-in server for a real installation.
 */
$root = dirname(__DIR__);
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

if (preg_match('#^/(app|config|storage|dev|tests|docs)(/|$)#i', $path)
    || preg_match('#(^|/)\.(?!well-known)#', $path)
    || preg_match('#\.(md|log|sql|sh|py|ini|dist|bak|tmp|lock)$#i', $path)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "403 Forbidden\n";
    return true;
}

$file = realpath($root . $path);
if ($path !== '/' && $file !== false && is_file($file) && strncmp($file, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) === 0
    && strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php') {
    return false; // a real asset file
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
require $root . '/index.php';
