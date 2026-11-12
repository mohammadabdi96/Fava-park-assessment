<?php
/**
 * A small HTTP client with a cookie jar for the end-to-end tests (no curl needed),
 * and a helper that starts the application on PHP's built-in web server.
 */

final class HttpResponse
{
    /** @var int */
    public $status = 0;
    /** @var array lower-case header name => list of values */
    public $headers = [];
    /** @var string */
    public $body = '';

    public function header(string $name): string
    {
        $name = strtolower($name);
        return isset($this->headers[$name]) ? (string) end($this->headers[$name]) : '';
    }

    /** Path (and query) of the Location header, without scheme and host. */
    public function location(): string
    {
        $loc = $this->header('location');
        return (string) preg_replace('#^https?://[^/]+#', '', $loc);
    }

    public function json(): array
    {
        $d = json_decode($this->body, true);
        return is_array($d) ? $d : [];
    }
}

final class HttpClient
{
    /** @var string */
    public $base;
    /** @var array name => value */
    public $cookies = [];
    /** @var array name => raw Set-Cookie line of the last time it was set */
    public $cookieLines = [];
    /** @var array extra headers sent with every request */
    public $defaultHeaders = [];

    /** @var string scheme://host:port */
    private $origin;
    /** @var string folder of the application on the server ('' or '/assess') */
    private $prefix;

    public function __construct(string $base)
    {
        $this->base = rtrim($base, '/');
        preg_match('#^(https?://[^/]+)(.*)$#', $this->base, $m);
        $this->origin = $m[1];
        $this->prefix = $m[2];
    }

    /** Paths may be given relative to the application or as the server sent them (with the folder). */
    private function urlOf(string $path): string
    {
        if (preg_match('#^https?://#', $path)) {
            return $path;
        }
        if ($this->prefix !== '' && (strncmp($path, $this->prefix . '/', strlen($this->prefix) + 1) === 0 || $path === $this->prefix)) {
            return $this->origin . $path;
        }
        return $this->base . $path;
    }

    public function get(string $path, array $headers = []): HttpResponse
    {
        return $this->request('GET', $path, null, $headers);
    }

    /** Follow redirects (as a browser does) and return the final page. */
    public function getFollow(string $path, int $max = 5): HttpResponse
    {
        $r = $this->get($path);
        while ($max-- > 0 && in_array($r->status, [301, 302, 303, 307], true) && $r->location() !== '') {
            $r = $this->get($r->location());
        }
        return $r;
    }

    /**
     * POST a form. $files: field => [filename, content] (field may end in [] for multiple).
     */
    public function post(string $path, array $fields = [], array $files = [], array $headers = []): HttpResponse
    {
        if (!$files) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            return $this->request('POST', $path, http_build_query($fields), $headers);
        }
        $boundary = '----favatest' . bin2hex(random_bytes(8));
        $body = '';
        foreach ($fields as $k => $v) {
            $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $k . "\"\r\n\r\n" . $v . "\r\n";
        }
        foreach ($files as $field => $list) {
            if (!is_array($list[0])) {
                $list = [$list];
            }
            foreach ($list as $f) {
                $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $field . '"; filename="' . $f[0] . "\"\r\n"
                    . "Content-Type: application/octet-stream\r\n\r\n" . $f[1] . "\r\n";
            }
        }
        $body .= '--' . $boundary . "--\r\n";
        $headers[] = 'Content-Type: multipart/form-data; boundary=' . $boundary;
        return $this->request('POST', $path, $body, $headers);
    }

    public function postJson(string $path, $data, array $headers = []): HttpResponse
    {
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        return $this->request('POST', $path, is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE), $headers);
    }

    public function request(string $method, string $path, ?string $body, array $headers = []): HttpResponse
    {
        $headers = array_merge($this->defaultHeaders, $headers);
        if ($this->cookies) {
            $pairs = [];
            foreach ($this->cookies as $k => $v) {
                $pairs[] = $k . '=' . $v;
            }
            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }
        $headers[] = 'Connection: close';
        $opts = ['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'timeout' => 60,
            'protocol_version' => 1.1,
        ]];
        if ($body !== null) {
            $opts['http']['content'] = $body;
        }
        $url = $this->urlOf($path);
        $http_response_header = [];
        $content = @file_get_contents($url, false, stream_context_create($opts));
        $res = new HttpResponse();
        $res->body = $content === false ? '' : $content;
        foreach ($http_response_header as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $res->status = (int) $m[1];
                $res->headers = [];
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $pos)));
            $value = trim(substr($line, $pos + 1));
            $res->headers[$name][] = $value;
            if ($name === 'set-cookie' && preg_match('/^([^=;\s]+)=([^;]*)/', $value, $m)) {
                $this->cookieLines[$m[1]] = $value;
                if ($m[2] === '' || $m[2] === 'deleted' || stripos($value, 'max-age=0') !== false) {
                    unset($this->cookies[$m[1]]);
                } else {
                    $this->cookies[$m[1]] = $m[2];
                }
            }
        }
        return $res;
    }

    /** The CSRF token of the logged-in session, read from an admin page. */
    public function csrf(string $page = '/admin'): string
    {
        $r = $this->getFollow($page);
        if (!preg_match('/name="_csrf" value="([^"]+)"/', $r->body, $m)) {
            throw new TestFailure('no CSRF token on ' . $page . ' (status ' . $r->status . ')');
        }
        return html_entity_decode($m[1], ENT_QUOTES);
    }

    /** POST an admin form with the session's CSRF token, then follow the redirect. */
    public function submit(string $path, array $fields = [], array $files = []): HttpResponse
    {
        $r = $this->post($path, ['_csrf' => $this->csrf()] + $fields, $files);
        if (in_array($r->status, [301, 302, 303], true) && $r->location() !== '') {
            return $this->getFollow($r->location());
        }
        return $r;
    }
}

/**
 * Start the application on the built-in web server with its own empty config/storage.
 * @return array{base:string,home:string,log:string,port:int,proc:resource}
 */
function start_app(array $env = [], string $prefix = '', bool $uploaded = false): array
{
    $home = tmp_dir('fava-app-');
    mkdir($home . '/config');
    mkdir($home . '/storage');
    $port = free_port();
    $root = dirname(__DIR__, 2);
    if ($uploaded) {
        // The files as an admin uploads them to public_html: no .git, no dev tools, and the
        // private folders inside the web root, protected by nothing but the .htaccess files.
        $root = $home . '/public_html';
        copy_tree(dirname(__DIR__, 2), $root, ['.git', 'tests', 'dev', 'node_modules', 'google_drive'], ['config' => ['.htaccess'], 'storage' => ['.htaccess', 'probe.txt']]);
        $env += ['FAVA_CONFIG_DIR' => '', 'FAVA_STORAGE_DIR' => ''];
    }
    // FAVA_TEST_DISABLE_FUNCTIONS is not passed on: it becomes PHP's disable_functions for the
    // server, to imitate a shared host that has switched functions off.
    $disabled = (string) ($env['FAVA_TEST_DISABLE_FUNCTIONS'] ?? '');
    unset($env['FAVA_TEST_DISABLE_FUNCTIONS']);
    $docRoot = $root;
    if ($prefix !== '') {
        // serve the application from a sub-folder, as on «example.ir/assess»
        $docRoot = $home . '/www';
        mkdir($docRoot);
        if (!@symlink($root, $docRoot . '/' . trim($prefix, '/'))) {
            throw new TestSkipped('symlinks are not available');
        }
    }
    $apache = apache_setup();
    if ($apache !== null) {
        return start_apache_app($apache, $env, $prefix, $home, $docRoot, $root, $disabled);
    }
    $router = $home . '/router.php';
    file_put_contents($router, '<?php
$prefix = ' . var_export(rtrim($prefix, '/'), true) . ';
$root = ' . var_export($root, true) . ';
$uri = (string) parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH);
if ($prefix !== "") {
    if (strncmp($uri, $prefix . "/", strlen($prefix) + 1) !== 0 && $uri !== $prefix) { http_response_code(404); echo "outside"; return true; }
    $path = rawurldecode(substr($uri, strlen($prefix))) ?: "/";
} else {
    $path = rawurldecode($uri);
}
if (preg_match("#^/(app|config|storage|dev|tests|docs)(/|$)#i", $path) || preg_match("#(^|/)\\.(?!well-known)#", $path)
    || preg_match("#\\.(md|log|sql|sh|py|ini|dist|bak|tmp|lock)$#i", $path)) {
    http_response_code(403); header("Content-Type: text/plain"); echo "403 Forbidden\n"; return true;
}
$file = realpath($root . $path);
if ($path !== "/" && $file !== false && is_file($file) && strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== "php") {
    $types = ["css" => "text/css", "js" => "application/javascript", "svg" => "image/svg+xml", "woff2" => "font/woff2", "txt" => "text/plain"];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header("Content-Type: " . ($types[$ext] ?? "application/octet-stream"));
    readfile($file); return true;
}
$_SERVER["SCRIPT_NAME"] = $prefix . "/index.php";
$_SERVER["SCRIPT_FILENAME"] = $root . "/index.php";
$_SERVER["PHP_SELF"] = $prefix . "/index.php";
require $root . "/index.php";
');
    $log = $home . '/server.log';
    $cmd = php_command() . ' -d display_errors=0 -d log_errors=1 -d error_log=' . escapeshellarg($home . '/php-errors.log')
        . ' -d upload_max_filesize=32M -d post_max_size=40M'
        . ($disabled !== '' ? ' -d disable_functions=' . escapeshellarg($disabled) : '')
        . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($docRoot) . ' ' . escapeshellarg($router);
    $proc = start_background($cmd, $log, $env + [
        'FAVA_CONFIG_DIR' => $home . '/config',
        'FAVA_STORAGE_DIR' => $home . '/storage',
    ]);
    if (!wait_for_port($port, 15)) {
        throw new \RuntimeException('the built-in web server did not start: ' . @file_get_contents($log));
    }
    return ['base' => 'http://127.0.0.1:' . $port . rtrim($prefix, '/'), 'home' => $home, 'log' => $log, 'port' => $port, 'proc' => $proc,
        'server' => 'builtin', 'root' => $root] + app_dirs($env, $home, $root);
}

/** Where this instance keeps its configuration and data. */
function app_dirs(array $env, string $home, string $root): array
{
    $own = ($env['FAVA_CONFIG_DIR'] ?? null) === '';
    return ['config' => $own ? $root . '/config' : $home . '/config', 'storage' => $own ? $root . '/storage' : $home . '/storage'];
}

/** Copy a folder; $skip = names left out everywhere, $only = [top-level folder => the only entries copied from it]. */
function copy_tree(string $src, string $dst, array $skip = [], array $only = [], string $rel = ''): void
{
    if (!is_dir($dst)) {
        mkdir($dst, 0777, true);
    }
    foreach (scandir($src) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || in_array($entry, $skip, true)) {
            continue;
        }
        if ($rel !== '' && isset($only[$rel]) && !in_array($entry, $only[$rel], true)) {
            continue;
        }
        $from = $src . '/' . $entry;
        if (is_dir($from) && !is_link($from)) {
            copy_tree($from, $dst . '/' . $entry, $skip, $only, $rel === '' ? $entry : $rel . '/' . $entry);
        } elseif (is_file($from)) {
            copy($from, $dst . '/' . $entry);
        }
    }
}

/**
 * Optional: run the end-to-end tests through a real Apache httpd + PHP-FPM, so that the
 * project's own .htaccess and .user.ini are what answers (the built-in server needs a
 * router script that only imitates them). Enable with the environment variable
 *
 *   FAVA_TEST_APACHE='{"httpd":"/usr/sbin/apache2","fpm":"/usr/sbin/php-fpm8.3"}'
 *
 * Optional keys: "root" (ServerRoot holding modules/ and conf/mime.types), "modules"
 * (module directory), "mime" (mime.types file), "fpm_ini" (php.ini for FPM),
 * "fpm_env" / "httpd_env" (extra environment, e.g. LD_LIBRARY_PATH), "rewrite": false
 * (leave mod_rewrite out, to test the index.php?r= fallback).
 */
function apache_setup(): ?array
{
    static $cfg = false;
    if ($cfg === false) {
        $cfg = null;
        $raw = getenv('FAVA_TEST_APACHE');
        if ($raw) {
            $parsed = json_decode($raw, true);
            if (!is_array($parsed) || empty($parsed['httpd']) || empty($parsed['fpm'])) {
                fwrite(STDERR, "FAVA_TEST_APACHE must be JSON with \"httpd\" and \"fpm\"; using the built-in server.\n");
            } else {
                $cfg = $parsed;
            }
        }
    }
    return $cfg;
}

function start_apache_app(array $cfg, array $env, string $prefix, string $home, string $docRoot, string $appRoot, string $disabled = ''): array
{
    $port = free_port();
    $fpmPort = free_port();
    while ($fpmPort === $port) {
        $fpmPort = free_port();
    }
    $serverRoot = $cfg['root'] ?? dirname(dirname($cfg['httpd']));
    $modDir = $cfg['modules'] ?? $serverRoot . '/modules';
    $mime = $cfg['mime'] ?? $serverRoot . '/conf/mime.types';

    $wanted = ['mpm_prefork', 'unixd', 'authz_core', 'authz_host', 'access_compat', 'log_config', 'mime', 'dir', 'alias', 'env',
        'setenvif', 'headers', 'expires', 'negotiation', 'autoindex', 'proxy', 'proxy_fcgi'];
    if (($cfg['rewrite'] ?? true) && empty($env['FAVA_TEST_NO_REWRITE'])) {
        $wanted[] = 'rewrite';
    }
    $load = '';
    foreach ($wanted as $m) {
        if (is_file($modDir . '/mod_' . $m . '.so')) { // absent = compiled into the binary
            $load .= 'LoadModule ' . $m . '_module "' . $modDir . '/mod_' . $m . '.so"' . "\n";
        }
    }
    // Deliberately a host with unhelpful defaults: directory listings and MultiViews on,
    // index.html as the only index. The project's .htaccess has to put all of that right.
    $conf = 'ServerRoot "' . $serverRoot . '"' . "\n"
        . 'Listen 127.0.0.1:' . $port . "\n"
        . 'ServerName localhost' . "\n"
        . 'PidFile "' . $home . '/httpd.pid"' . "\n"
        . 'ErrorLog "' . $home . '/apache-error.log"' . "\n"
        . 'LogLevel warn' . "\n"
        . $load
        . '<IfModule log_config_module>' . "\n"
        . '    CustomLog "' . $home . '/apache-access.log" "%h %t \\"%r\\" %>s %b"' . "\n"
        . '</IfModule>' . "\n"
        . (is_file($mime) ? 'TypesConfig "' . $mime . '"' . "\n" : '')
        . 'DirectoryIndex index.html' . "\n"
        . 'DocumentRoot "' . $docRoot . '"' . "\n"
        . '<Directory "' . $docRoot . '">' . "\n"
        . '    Options Indexes FollowSymLinks MultiViews' . "\n"
        . '    AllowOverride ' . ($env['FAVA_TEST_ALLOW_OVERRIDE'] ?? 'All') . "\n"
        . '    Require all granted' . "\n"
        . '</Directory>' . "\n"
        . '<Files ".ht*">' . "\n" . '    Require all denied' . "\n" . '</Files>' . "\n"
        . '<FilesMatch "\\.php$">' . "\n"
        . '    SetHandler "proxy:fcgi://127.0.0.1:' . $fpmPort . '"' . "\n"
        . '</FilesMatch>' . "\n";
    file_put_contents($home . '/httpd.conf', $conf);

    file_put_contents($home . '/php-fpm.conf', "[global]\n"
        . 'error_log = ' . $home . "/fpm.log\n"
        . "daemonize = no\n"
        . "[www]\n"
        . 'listen = 127.0.0.1:' . $fpmPort . "\n"
        . "pm = static\n"
        . "pm.max_children = 4\n"
        . "clear_env = no\n"               // the workers see FAVA_CONFIG_DIR / FAVA_STORAGE_DIR
        . "catch_workers_output = yes\n"
        . "php_admin_flag[log_errors] = on\n"
        . "php_admin_flag[display_errors] = off\n"
        . 'php_admin_value[error_log] = ' . $home . "/php-errors.log\n"
        . ($disabled !== '' ? 'php_admin_value[disable_functions] = ' . $disabled . "\n" : ''));

    // Apache signals its whole process group when it stops; without a session of its own
    // that group would include this test run (and the terminal that started it).
    $setsid = trim((string) @shell_exec('command -v setsid 2>/dev/null'));
    if ($setsid === '') {
        throw new \RuntimeException('FAVA_TEST_APACHE needs the «setsid» command (util-linux).');
    }
    $setsid = escapeshellarg($setsid) . ' ';
    $fpmCmd = $setsid . escapeshellarg($cfg['fpm']) . ' -F -y ' . escapeshellarg($home . '/php-fpm.conf')
        . (!empty($cfg['fpm_ini']) ? ' -c ' . escapeshellarg($cfg['fpm_ini']) : '');
    start_background($fpmCmd, $home . '/fpm-out.log', $env + (array) ($cfg['fpm_env'] ?? []) + [
        'FAVA_CONFIG_DIR' => $home . '/config',
        'FAVA_STORAGE_DIR' => $home . '/storage',
    ]);
    if (!wait_for_port($fpmPort, 15)) {
        throw new \RuntimeException('PHP-FPM did not start: ' . @file_get_contents($home . '/fpm-out.log') . @file_get_contents($home . '/fpm.log'));
    }
    $log = $home . '/apache-error.log';
    $proc = start_background($setsid . escapeshellarg($cfg['httpd']) . ' -DFOREGROUND -f ' . escapeshellarg($home . '/httpd.conf'), $home . '/httpd-out.log', (array) ($cfg['httpd_env'] ?? []) + ['FAVA_APACHE' => '1']);
    if (!wait_for_port($port, 15)) {
        throw new \RuntimeException('Apache did not start: ' . @file_get_contents($home . '/httpd-out.log') . @file_get_contents($log));
    }
    return ['base' => 'http://127.0.0.1:' . $port . rtrim($prefix, '/'), 'home' => $home, 'log' => $log, 'port' => $port, 'proc' => $proc,
        'server' => 'apache', 'root' => $appRoot] + app_dirs($env, $home, $appRoot);
}

/** PHP errors logged by the application server so far (should stay empty). */
function app_errors(array $app): string
{
    $out = '';
    $storage = $app['storage'] ?? $app['home'] . '/storage';
    foreach ([$app['home'] . '/php-errors.log', $storage . '/logs/app.log'] as $f) {
        if (is_file($f)) {
            $out .= (string) file_get_contents($f);
        }
    }
    foreach (glob($storage . '/logs/*.log') ?: [] as $f) {
        if (basename($f) !== 'app.log') {
            $out .= (string) file_get_contents($f);
        }
    }
    return $out;
}

/** Run the installer with SQLite and return a logged-in admin client. */
function install_app(array $app, array $over = []): HttpClient
{
    $c = new HttpClient($app['base']);
    // What a browser does: open the site, land on the installer, let the page find out whether
    // short addresses work on this server, and send the form to the address it names.
    $page = $c->getFollow('/');
    $action = '/install';
    if (preg_match('/<form[^>]*\baction="([^"]+)"/', $page->body, $m)) {
        $action = html_entity_decode($m[1], ENT_QUOTES);
    }
    if (!isset($over['pretty_urls'])) {
        $probe = $c->get('/__rewrite_test', ['Accept: application/json']);
        $json = $probe->status === 200 ? json_decode($probe->body, true) : null;
        $over['pretty_urls'] = (is_array($json) && ($json['rewrite'] ?? '') === 'fava') ? '1' : '0';
    }
    $r = $c->post($action, $over + [
        'driver' => 'sqlite',
        'db_prefix' => 'fava_',
        'org_name' => 'پارک فاوا',
        'app_url' => '',
        'admin_name' => 'مدیر آزمون',
        'admin_user' => 'admin',
        'admin_email' => 'admin@park.example.ir',
        'admin_pass' => 'Test-12345',
        'admin_pass2' => 'Test-12345',
    ]);
    if ($r->status !== 302) {
        throw new TestFailure('installation failed with status ' . $r->status . ': ' . mb_substr(strip_tags($r->body), 0, 600));
    }
    return $c;
}
