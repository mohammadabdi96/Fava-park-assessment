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
function start_app(array $env = [], string $prefix = ''): array
{
    $home = tmp_dir('fava-app-');
    mkdir($home . '/config');
    mkdir($home . '/storage');
    $port = free_port();
    $root = dirname(__DIR__, 2);
    $docRoot = $root;
    if ($prefix !== '') {
        // serve the application from a sub-folder, as on «example.ir/assess»
        $docRoot = $home . '/www';
        mkdir($docRoot);
        if (!@symlink($root, $docRoot . '/' . trim($prefix, '/'))) {
            throw new TestSkipped('symlinks are not available');
        }
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
        . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($docRoot) . ' ' . escapeshellarg($router);
    $proc = start_background($cmd, $log, $env + [
        'FAVA_CONFIG_DIR' => $home . '/config',
        'FAVA_STORAGE_DIR' => $home . '/storage',
    ]);
    if (!wait_for_port($port, 15)) {
        throw new \RuntimeException('the built-in web server did not start: ' . @file_get_contents($log));
    }
    return ['base' => 'http://127.0.0.1:' . $port . rtrim($prefix, '/'), 'home' => $home, 'log' => $log, 'port' => $port, 'proc' => $proc];
}

/** PHP errors logged by the application server so far (should stay empty). */
function app_errors(array $app): string
{
    $out = '';
    foreach ([$app['home'] . '/php-errors.log', $app['home'] . '/storage/logs/app.log'] as $f) {
        if (is_file($f)) {
            $out .= (string) file_get_contents($f);
        }
    }
    foreach (glob($app['home'] . '/storage/logs/*.log') ?: [] as $f) {
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
    $r = $c->post('/install', $over + [
        'driver' => 'sqlite',
        'db_prefix' => 'fava_',
        'org_name' => 'پارک فاوا',
        'app_url' => '',
        'admin_name' => 'مدیر آزمون',
        'admin_user' => 'admin',
        'admin_email' => 'admin@park.example.ir',
        'admin_pass' => 'Test-12345',
        'admin_pass2' => 'Test-12345',
        'pretty_urls' => '1',
    ]);
    if ($r->status !== 302) {
        throw new TestFailure('installation failed with status ' . $r->status . ': ' . mb_substr(strip_tags($r->body), 0, 600));
    }
    return $c;
}
