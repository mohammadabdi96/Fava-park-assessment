<?php
/**
 * A very small test harness (the project has no Composer dependencies, so no PHPUnit).
 *
 *   test('name', function () { eq(4, 2 + 2); });
 */

final class TestState
{
    public static $passed = 0;
    public static $failed = 0;
    public static $skipped = 0;
    public static $assertions = 0;
    public static $failures = [];
    public static $filter = '';
    public static $group = '';
}

final class TestFailure extends \Exception
{
}

final class TestSkipped extends \Exception
{
}

function group(string $name): void
{
    TestState::$group = $name;
    echo "\n\033[1m" . $name . "\033[0m\n";
}

function test(string $name, callable $fn): void
{
    $full = TestState::$group . ' › ' . $name;
    if (TestState::$filter !== '' && stripos($full, TestState::$filter) === false) {
        return;
    }
    $start = microtime(true);
    try {
        $fn();
        TestState::$passed++;
        printf("  \033[32m✔\033[0m %s \033[2m(%d ms)\033[0m\n", $name, (microtime(true) - $start) * 1000);
    } catch (TestSkipped $e) {
        TestState::$skipped++;
        printf("  \033[33m–\033[0m %s \033[2m(skipped: %s)\033[0m\n", $name, $e->getMessage());
    } catch (\Throwable $e) {
        TestState::$failed++;
        $where = '';
        foreach ($e->getTrace() as $frame) {
            if (isset($frame['file']) && strpos($frame['file'], 'support' . DIRECTORY_SEPARATOR . 'lib.php') === false) {
                $where = basename($frame['file']) . ':' . $frame['line'];
                break;
            }
        }
        if ($where === '') {
            $where = basename($e->getFile()) . ':' . $e->getLine();
        }
        $msg = ($e instanceof TestFailure ? '' : get_class($e) . ': ') . $e->getMessage();
        TestState::$failures[] = $full . "\n      " . str_replace("\n", "\n      ", $msg) . "\n      at " . $where;
        printf("  \033[31m✘ %s\033[0m\n      %s\n      at %s\n", $name, str_replace("\n", "\n      ", $msg), $where);
    }
}

function skip(string $why): void
{
    throw new TestSkipped($why);
}

function show($v): string
{
    if (is_string($v)) {
        return "'" . (mb_strlen($v) > 300 ? mb_substr($v, 0, 300) . '…' : $v) . "'";
    }
    return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
}

function ok($cond, string $msg = 'expected a true value'): void
{
    TestState::$assertions++;
    if (!$cond) {
        throw new TestFailure($msg);
    }
}

function eq($expected, $actual, string $msg = ''): void
{
    TestState::$assertions++;
    if ($expected !== $actual) {
        throw new TestFailure(($msg !== '' ? $msg . "\n" : '') . 'expected: ' . show($expected) . "\nactual:   " . show($actual));
    }
}

function near(float $expected, $actual, string $msg = '', float $eps = 1e-6): void
{
    TestState::$assertions++;
    if (!is_numeric($actual) || abs($expected - (float) $actual) > $eps) {
        throw new TestFailure(($msg !== '' ? $msg . "\n" : '') . 'expected: ' . $expected . "\nactual:   " . show($actual));
    }
}

function contains(string $needle, $haystack, string $msg = ''): void
{
    TestState::$assertions++;
    if (!is_string($haystack) || strpos($haystack, $needle) === false) {
        throw new TestFailure(($msg !== '' ? $msg . "\n" : '') . 'expected to contain: ' . show($needle) . "\nin: " . show($haystack));
    }
}

function lacks(string $needle, $haystack, string $msg = ''): void
{
    TestState::$assertions++;
    if (is_string($haystack) && strpos($haystack, $needle) !== false) {
        throw new TestFailure(($msg !== '' ? $msg . "\n" : '') . 'expected NOT to contain: ' . show($needle));
    }
}

function throws(callable $fn, string $class = \Throwable::class, string $msg = ''): \Throwable
{
    TestState::$assertions++;
    try {
        $fn();
    } catch (\Throwable $e) {
        if ($e instanceof $class) {
            return $e;
        }
        throw new TestFailure(($msg !== '' ? $msg . "\n" : '') . 'expected ' . $class . ', got ' . get_class($e) . ': ' . $e->getMessage());
    }
    throw new TestFailure(($msg !== '' ? $msg . "\n" : '') . 'expected ' . $class . ' to be thrown');
}

/** A fresh temporary directory that is removed when the test run ends. */
function tmp_dir(string $prefix = 'fava-test-'): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(5));
    mkdir($dir, 0700, true);
    register_shutdown_function('rm_rf', $dir);
    return $dir;
}

function rm_rf(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            rm_rf($path . DIRECTORY_SEPARATOR . $entry);
        }
    }
    @rmdir($path);
}

/** A TCP port that is free right now. */
function free_port(): int
{
    $s = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!$s) {
        throw new \RuntimeException('cannot open a local port: ' . $errstr);
    }
    $name = stream_socket_get_name($s, false);
    fclose($s);
    return (int) substr($name, strrpos($name, ':') + 1);
}

function fixture(string $name): string
{
    return __DIR__ . '/../fixtures/' . $name;
}

/**
 * Build a ZIP archive in memory (enough of the format for test documents).
 *
 * @param array $entries name => content
 * @param bool  $deflate compress the entries (needs zlib)
 */
function zip_build(array $entries, bool $deflate = false): string
{
    $out = '';
    $central = '';
    $count = 0;
    foreach ($entries as $name => $content) {
        $content = (string) $content;
        $data = $deflate ? gzdeflate($content, 6) : $content;
        $method = $deflate ? 8 : 0;
        $crc = crc32($content);
        $offset = strlen($out);
        $header = pack('vvvvvVVVvv', 20, 0x0800, $method, 0, 0x21, $crc, strlen($data), strlen($content), strlen($name), 0);
        $out .= "PK\x03\x04" . $header . $name . $data;
        $central .= "PK\x01\x02" . pack('v', 20) . $header . pack('vvvVV', 0, 0, 0, 0, $offset) . $name;
        $count++;
    }
    $cdOffset = strlen($out);
    return $out . $central . "PK\x05\x06" . pack('vvvvVVv', 0, 0, $count, $count, strlen($central), $cdOffset, 0);
}

/** A minimal .docx whose body is the given WordprocessingML fragment. Returns the file path. */
function docx_with_body(string $bodyXml, array $extraParts = [], bool $deflate = false): string
{
    $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
        . $bodyXml . '</w:body></w:document>';
    $parts = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
        'word/document.xml' => $doc,
    ] + $extraParts;
    if (isset($extraParts['word/document.xml'])) {
        $parts['word/document.xml'] = $extraParts['word/document.xml'];
    }
    $path = tmp_dir('fava-docx-') . '/test.docx';
    file_put_contents($path, zip_build($parts, $deflate));
    return $path;
}

function wp(string $text, bool $bold = false): string
{
    return '<w:p><w:r>' . ($bold ? '<w:rPr><w:b/></w:rPr>' : '') . '<w:t xml:space="preserve">'
        . htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</w:t></w:r></w:p>';
}

/** @param array $rows list of rows, each a list of cell texts */
function wtbl(array $rows): string
{
    $x = '<w:tbl>';
    foreach ($rows as $row) {
        $x .= '<w:tr>';
        foreach ($row as $cell) {
            $x .= '<w:tc>' . wp((string) $cell) . '</w:tc>';
        }
        $x .= '</w:tr>';
    }
    return $x . '</w:tbl>';
}

/** The PHP command line that runs this test, including the php.ini in use. */
function php_command(): string
{
    $cmd = escapeshellarg(PHP_BINARY);
    $ini = php_ini_loaded_file();
    if ($ini) {
        $cmd .= ' -c ' . escapeshellarg($ini);
    }
    return $cmd;
}

/**
 * Start a background process that is stopped when the test run ends.
 * @return resource
 */
function start_background(string $cmd, string $logFile, array $env = [])
{
    $windows = DIRECTORY_SEPARATOR === '\\';
    $spec = [
        0 => ['file', $windows ? 'NUL' : '/dev/null', 'r'],
        1 => ['file', $logFile, 'a'],
        2 => ['file', $logFile, 'a'],
    ];
    $fullEnv = null;
    if ($env) {
        $fullEnv = array_merge(getenv(), $env);
    }
    $proc = proc_open(($windows ? '' : 'exec ') . $cmd, $spec, $pipes, null, $fullEnv);
    if (!is_resource($proc)) {
        throw new \RuntimeException('cannot start: ' . $cmd);
    }
    register_shutdown_function('stop_background', $proc);
    return $proc;
}

function stop_background($proc): void
{
    if (!is_resource($proc)) {
        return;
    }
    $st = proc_get_status($proc);
    if ($st['running']) {
        proc_terminate($proc);
        for ($i = 0; $i < 40; $i++) {
            usleep(25000);
            $st = proc_get_status($proc);
            if (!$st['running']) {
                break;
            }
        }
        if ($st['running']) {
            proc_terminate($proc, 9);
        }
    }
    @proc_close($proc);
}

/** Wait until something listens on a local port. */
function wait_for_port(int $port, float $seconds = 10.0): bool
{
    $end = microtime(true) + $seconds;
    while (microtime(true) < $end) {
        $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
        if ($s) {
            // A local socket can connect to itself when the system happens to pick the target
            // port as its own source port, although nothing is listening there yet.
            $self = stream_socket_get_name($s, false) === stream_socket_get_name($s, true);
            fclose($s);
            if (!$self) {
                return true;
            }
        }
        usleep(50000);
    }
    return false;
}
