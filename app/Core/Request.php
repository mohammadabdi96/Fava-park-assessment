<?php

namespace Fava\Core;

class Request
{
    /** @var string|null */
    private static $basePath = null;
    /** @var array|null */
    private static $json = null;

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    /** Folder the application is installed in, relative to the web root ('' when installed at the root). */
    public static function basePath(): string
    {
        if (self::$basePath === null) {
            $configured = Config::get('base_path');
            if (is_string($configured)) {
                self::$basePath = rtrim($configured, '/');
            } else {
                $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
                // SCRIPT_NAME ends with /index.php when routed through the front controller
                $pos = strpos($script, '/index.php');
                $dir = $pos !== false ? substr($script, 0, $pos) : str_replace('\\', '/', dirname($script));
                self::$basePath = ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');
            }
        }
        return self::$basePath;
    }

    /** Route path, e.g. /admin/proposals/5 */
    public static function path(): string
    {
        if (isset($_GET['r']) && is_string($_GET['r']) && $_GET['r'] !== '') {
            $path = '/' . ltrim($_GET['r'], '/');
        } else {
            $uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
            $uri = rawurldecode($uri);
            $base = self::basePath();
            if ($base !== '' && strncmp($uri, $base, strlen($base)) === 0) {
                $uri = (string) substr($uri, strlen($base));
            }
            if (strncmp($uri, '/index.php', 10) === 0) {
                $uri = (string) substr($uri, 10);
            }
            $path = '/' . ltrim($uri, '/');
        }
        $path = preg_replace('#/+#', '/', $path);
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        return $path;
    }

    public static function isHttps(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        if (Config::get('trust_proxy', false)) {
            $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
            if ($proto !== '' && strpos($proto, 'https') === 0) {
                return true;
            }
            if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') {
                return true;
            }
        }
        return false;
    }

    public static function host(): string
    {
        $host = '';
        if (Config::get('trust_proxy', false) && !empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
            $host = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_HOST'])[0]);
        }
        if ($host === '') {
            $host = (string) ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        }
        // keep only characters that are valid in a host[:port]
        return preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $host);
    }

    /** scheme://host[:port] */
    public static function origin(): string
    {
        return (self::isHttps() ? 'https' : 'http') . '://' . self::host();
    }

    public static function ip(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (Config::get('trust_proxy', false)) {
            // only safe when a proxy in front of the app overwrites this header
            $forwarded = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
            if (filter_var($forwarded, FILTER_VALIDATE_IP)) {
                $ip = $forwarded;
            }
        }
        return substr($ip, 0, 45);
    }

    public static function userAgent(): string
    {
        return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
    }

    /** POST value (falls back to GET), trimmed when it is a string. */
    public static function input(string $key, $default = null)
    {
        $value = $_POST[$key] ?? ($_GET[$key] ?? $default);
        return is_string($value) ? trim($value) : $value;
    }

    public static function str(string $key, string $default = ''): string
    {
        $v = self::input($key, $default);
        return is_string($v) ? $v : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::input($key);
        return is_scalar($v) && $v !== '' ? (int) Fa::latin((string) $v) : $default;
    }

    public static function bool(string $key): bool
    {
        $v = self::input($key);
        return $v === '1' || $v === 'on' || $v === 'true' || $v === 1 || $v === true;
    }

    public static function query(string $key, $default = null)
    {
        $value = $_GET[$key] ?? $default;
        return is_string($value) ? trim($value) : $value;
    }

    /** Decoded JSON request body. */
    public static function json(): array
    {
        if (self::$json === null) {
            $raw = file_get_contents('php://input');
            $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            self::$json = is_array($data) ? $data : [];
        }
        return self::$json;
    }

    public static function wantsJson(): bool
    {
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        $with = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $type = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        return strpos($accept, 'application/json') !== false || $with === 'xmlhttprequest' || strpos($type, 'application/json') === 0;
    }

    public static function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return (string) ($_SERVER[$key] ?? '');
    }
}
