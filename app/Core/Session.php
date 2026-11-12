<?php

namespace Fava\Core;

/**
 * Admin session. Judges never get a session: their private link is the credential.
 */
class Session
{
    private const IDLE_SECONDS = 28800; // 8 hours

    /** @var bool */
    private static $started = false;

    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }
        $dir = STORAGE_DIR . '/sessions';
        if ((is_dir($dir) || @mkdir($dir, 0700, true)) && is_writable($dir)) {
            session_save_path($dir);
            // Shared hosts often disable PHP's own garbage collection for the default path.
            @ini_set('session.gc_probability', '1');
            @ini_set('session.gc_divisor', '100');
        }
        @ini_set('session.gc_maxlifetime', (string) self::IDLE_SECONDS);
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.use_trans_sid', '0');

        $secureCfg = Config::get('session.secure', 'auto');
        $secure = $secureCfg === 'auto' ? Request::isHttps() : (bool) $secureCfg;
        $sameSite = (string) Config::get('session.samesite', 'Lax');
        if (getenv('FAVA_EMBED')) {
            // development preview inside a frame of another site: the cookie must be allowed cross-site
            $sameSite = 'None';
            $secure = true;
        }
        $base = Request::basePath();

        session_name('FAVASESS');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $base === '' ? '/' : $base . '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);
        session_start();
        self::$started = true;

        $now = time();
        if (isset($_SESSION['_seen']) && $now - (int) $_SESSION['_seen'] > self::IDLE_SECONDS) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['_seen'] = $now;

        // flash data lives for exactly one further request
        $_SESSION['_flash_now'] = $_SESSION['_flash'] ?? [];
        $_SESSION['_old_now'] = $_SESSION['_old_next'] ?? [];
        unset($_SESSION['_flash'], $_SESSION['_old_next']);
    }

    public static function get(string $key, $default = null)
    {
        if (!self::$started) {
            return $default;
        }
        if ($key === '_old') {
            return $_SESSION['_old_now'] ?? [];
        }
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        if (self::$started) {
            unset($_SESSION[$key]);
        }
    }

    public static function flash(string $type, string $message): void
    {
        self::start();
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** Messages flashed by the previous request. */
    public static function flashes(): array
    {
        if (!self::$started) {
            return [];
        }
        $now = $_SESSION['_flash_now'] ?? [];
        $_SESSION['_flash_now'] = [];
        return $now;
    }

    /** Keep the submitted form values for the next request. */
    public static function keepInput(array $input): void
    {
        self::start();
        foreach (array_keys($input) as $key) {
            // never keep secrets in the session, whatever the field is called
            if ($key === '_csrf' || stripos((string) $key, 'pass') !== false) {
                unset($input[$key]);
            }
        }
        $_SESSION['_old_next'] = $input;
    }

    public static function regenerate(): void
    {
        self::start();
        session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => $p['path'],
                'domain' => $p['domain'],
                'secure' => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
        self::$started = false;
    }
}
