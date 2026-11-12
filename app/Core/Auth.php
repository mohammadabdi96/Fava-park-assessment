<?php

namespace Fava\Core;

class Auth
{
    private const MAX_FAILURES = 6;
    private const WINDOW_MINUTES = 15;

    /** @var array|null|false */
    private static $user = false;

    public static function user(): ?array
    {
        if (self::$user === false) {
            self::$user = null;
            $id = (int) Session::get('admin_id', 0);
            if ($id > 0) {
                $row = DB::get()->one('SELECT id, username, name, email, password_hash FROM {admins} WHERE id = ?', [$id]);
                // a password change elsewhere invalidates this session
                if ($row && hash_equals((string) Session::get('admin_sig', ''), self::signature($row))) {
                    unset($row['password_hash']);
                    self::$user = $row;
                }
            }
        }
        return self::$user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int) $u['id'] : null;
    }

    public static function name(): string
    {
        $u = self::user();
        return $u ? (string) ($u['name'] ?: $u['username']) : '';
    }

    private static function signature(array $row): string
    {
        return hash_hmac('sha256', $row['id'] . '|' . $row['password_hash'], (string) Config::get('app_key', ''));
    }

    public static function isLocked(string $ip): bool
    {
        $since = gmdate('Y-m-d H:i:s', time() - self::WINDOW_MINUTES * 60);
        $fails = (int) DB::get()->val(
            'SELECT COUNT(*) FROM {login_attempts} WHERE ip = ? AND success = 0 AND created_at >= ?',
            [$ip, $since]
        );
        return $fails >= self::MAX_FAILURES;
    }

    /** @return bool true on success */
    public static function attempt(string $username, string $password): bool
    {
        $db = DB::get();
        $ip = Request::ip();
        $row = $db->one('SELECT * FROM {admins} WHERE username = ?', [mb_strtolower(trim($username))]);
        $ok = $row && password_verify($password, (string) $row['password_hash']);
        if (!$row) {
            // keep timing similar whether or not the user exists
            password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
        }
        $db->insert('login_attempts', [
            'created_at' => now_utc(),
            'ip' => $ip,
            'username' => mb_substr($username, 0, 60),
            'success' => $ok ? 1 : 0,
        ]);
        if (!$ok) {
            return false;
        }
        if (password_needs_rehash((string) $row['password_hash'], PASSWORD_DEFAULT)) {
            $row['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            $db->update('admins', ['password_hash' => $row['password_hash']], 'id = ?', [(int) $row['id']]);
        }
        self::login($row);
        $db->update('admins', ['last_login_at' => now_utc()], 'id = ?', [(int) $row['id']]);
        // old attempts are no longer interesting
        $db->delete('login_attempts', 'created_at < ?', [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
        return true;
    }

    public static function login(array $row): void
    {
        Session::regenerate();
        Session::set('admin_id', (int) $row['id']);
        Session::set('admin_sig', self::signature($row));
        self::$user = false;
    }

    /** Call after the logged-in admin changed their own password, to keep the session valid. */
    public static function refresh(int $id): void
    {
        $row = DB::get()->one('SELECT * FROM {admins} WHERE id = ?', [$id]);
        if ($row) {
            Session::set('admin_sig', self::signature($row));
        }
        self::$user = false;
    }

    public static function logout(): void
    {
        Session::destroy();
        self::$user = null;
    }

    /**
     * Recovery without e-mail: the site owner uploads storage/reset-admin.txt containing a new
     * password (first line). On the next visit to the login page the first admin gets that password.
     */
    public static function applyResetFile(): ?string
    {
        $file = STORAGE_DIR . '/reset-admin.txt';
        if (!is_file($file)) {
            return null;
        }
        $password = trim((string) strtok((string) @file_get_contents($file), "\n"));
        @unlink($file);
        if (is_file($file)) {
            return 'فایل reset-admin.txt قابل حذف نیست؛ برای امنیت، بازنشانی انجام نشد.';
        }
        if (mb_strlen($password) < 8) {
            return 'رمز داخل فایل reset-admin.txt باید حداقل ۸ نویسه باشد.';
        }
        $db = DB::get();
        $admin = $db->one('SELECT id, username FROM {admins} ORDER BY id ASC LIMIT 1');
        if (!$admin) {
            return null;
        }
        $db->update('admins', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [(int) $admin['id']]);
        $db->delete('login_attempts', '1 = 1');
        return 'رمز عبور کاربر «' . $admin['username'] . '» بازنشانی شد. اکنون با رمز جدید وارد شوید.';
    }
}
