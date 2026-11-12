<?php
/**
 * Test runner.
 *
 *   php tests/run.php                 all tests
 *   php tests/run.php scoring         only tests whose group/name contains «scoring»
 *   php tests/run.php --no-http       skip the end-to-end tests (they start PHP's built-in server)
 *
 * Optional: run the database tests on MySQL/MariaDB as well
 *   FAVA_TEST_MYSQL='{"host":"127.0.0.1","port":3306,"name":"fava_test","user":"root","pass":""}' php tests/run.php
 *
 * The tests never touch config/ or storage/ of this checkout: everything lives in a
 * temporary folder that is removed at the end.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

define('APP_ROOT', dirname(__DIR__));
define('APP_START', microtime(true));
require __DIR__ . '/support/lib.php';

$args = array_slice($argv, 1);
$noHttp = in_array('--no-http', $args, true);
foreach ($args as $a) {
    if (strncmp($a, '--', 2) !== 0) {
        TestState::$filter = $a;
    }
}

$TEST_HOME = tmp_dir('fava-tests-');
mkdir($TEST_HOME . '/config');
mkdir($TEST_HOME . '/storage');
putenv('FAVA_CONFIG_DIR=' . $TEST_HOME . '/config');
putenv('FAVA_STORAGE_DIR=' . $TEST_HOME . '/storage');

require APP_ROOT . '/app/bootstrap.php';

echo 'PHP ' . PHP_VERSION . ' — ' . PHP_OS . "\n";

foreach (glob(__DIR__ . '/unit/*Test.php') as $file) {
    require $file;
}
if (!$noHttp) {
    require __DIR__ . '/http/FlowTest.php';
}

echo "\n";
if (TestState::$failed > 0) {
    echo "\033[31mFailures:\033[0m\n";
    foreach (TestState::$failures as $f) {
        echo '  • ' . $f . "\n";
    }
    echo "\n";
}
printf(
    "%s%d passed, %d failed, %d skipped\033[0m — %d assertions, %.1f s\n",
    TestState::$failed > 0 ? "\033[31m" : "\033[32m",
    TestState::$passed,
    TestState::$failed,
    TestState::$skipped,
    TestState::$assertions,
    microtime(true) - APP_START
);
exit(TestState::$failed > 0 ? 1 : 0);
