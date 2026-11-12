<?php

namespace Fava\Controllers;

use Fava\Core\Auth;
use Fava\Core\Config;
use Fava\Core\DB;
use Fava\Core\Logger;
use Fava\Core\Request;
use Fava\Core\Schema;
use Fava\Core\Session;
use Fava\Core\View;
use Fava\Domain\Activity;
use Fava\Domain\Settings;
use PDO;

/**
 * First-run installer: checks the hosting, creates the tables and the first admin
 * and writes config/config.php. Not reachable once the system is installed.
 */
class InstallController extends Controller
{
    private const STORAGE_DIRS = ['uploads', 'database', 'logs', 'sessions', 'cache'];

    public function index(array $params): void
    {
        $this->show($this->defaults(), []);
    }

    /** Answered through a pretty URL only when URL rewriting works on this server. */
    public function rewriteTest(array $params): void
    {
        View::json(['ok' => true, 'rewrite' => 'fava']);
    }

    private function defaults(): array
    {
        $drivers = PDO::getAvailableDrivers();
        return [
            'driver' => in_array('mysql', $drivers, true) ? 'mysql' : 'sqlite',
            'db_host' => 'localhost',
            'db_name' => '',
            'db_user' => '',
            'db_prefix' => 'fava_',
            'org_name' => 'پارک فاوا',
            'app_url' => Request::origin() . Request::basePath(),
            'admin_name' => '',
            'admin_user' => 'admin',
            'admin_email' => '',
            'pretty_urls' => '0',
        ];
    }

    private function show(array $old, array $errors, int $status = 200): void
    {
        $drivers = PDO::getAvailableDrivers();
        View::render('install/index', [
            'pageTitle' => 'نصب سامانه',
            'org' => 'پارک فاوا',
            'flashes' => [],
            'checks' => self::checks(),
            'old' => $old,
            'errors' => $errors,
            'hasMysql' => in_array('mysql', $drivers, true),
            'hasSqlite' => in_array('sqlite', $drivers, true),
            'rewriteProbe' => Request::basePath() . '/__rewrite_test',
            'storageProbe' => Request::basePath() . '/storage/probe.txt',
        ], 'plain', $status);
    }

    /** @return array[] [label, ok, required, detail] */
    public static function checks(): array
    {
        $drivers = PDO::getAvailableDrivers();
        $checks = [];
        $checks[] = ['نسخه PHP (حداقل ۷٫۴)', PHP_VERSION_ID >= 70400, true, PHP_VERSION];
        $checks[] = ['افزونه پایگاه‌داده (pdo_mysql یا pdo_sqlite)', in_array('mysql', $drivers, true) || in_array('sqlite', $drivers, true), true, implode('، ', $drivers) ?: 'هیچ‌کدام'];
        foreach (['mbstring' => true, 'dom' => true, 'zlib' => true, 'json' => true, 'openssl' => false, 'zip' => false] as $ext => $required) {
            $note = '';
            if ($ext === 'openssl') {
                $note = 'برای ارسال ایمیل از طریق SMTP امن';
            } elseif ($ext === 'zip') {
                $note = 'اختیاری؛ در نبود آن از خواننده داخلی استفاده می‌شود';
            }
            $checks[] = ['افزونه ' . $ext, extension_loaded($ext), $required, $note];
        }
        $checks[] = ['بارگذاری فایل (file_uploads)', (bool) ini_get('file_uploads'), true, 'حداکثر حجم هر فایل: ' . ini_get('upload_max_filesize')];

        $configDir = CONFIG_DIR;
        if (!is_dir($configDir)) {
            @mkdir($configDir, 0755, true);
        }
        $checks[] = ['دسترسی نوشتن در پوشه config', is_dir($configDir) && is_writable($configDir), true, $configDir];

        $storageOk = true;
        if (!is_dir(STORAGE_DIR)) {
            @mkdir(STORAGE_DIR, 0755, true);
        }
        foreach (self::STORAGE_DIRS as $sub) {
            $dir = STORAGE_DIR . '/' . $sub;
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (!is_dir($dir) || !is_writable($dir)) {
                $storageOk = false;
            }
        }
        $checks[] = ['دسترسی نوشتن در پوشه storage و زیرپوشه‌های آن', $storageOk, true, STORAGE_DIR];

        self::ensureGuards();
        $server = (string) ($_SERVER['SERVER_SOFTWARE'] ?? '');
        if (preg_match('/apache|litespeed/i', $server) && !is_file(APP_ROOT . '/.htaccess')) {
            // file managers and FTP programs hide dot-files unless told otherwise
            $checks[] = ['فایل ‎.htaccess در پوشه اصلی سامانه', false, false, 'این فایل بارگذاری نشده است. در File Manager گزینه «Show Hidden Files» را روشن کنید و فایل‌های ‎.htaccess و ‎.user.ini را هم بارگذاری کنید.'];
        }
        return $checks;
    }

    /**
     * The rules that close config/ and storage/ to the web, and the file the «is storage
     * reachable?» check asks for, are small hidden files that an upload can easily leave
     * behind. Put them back when they are missing; an existing file is never touched.
     */
    public static function ensureGuards(): void
    {
        $deny = "# Private folder – nothing in here may be opened from the web.\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";
        foreach ([CONFIG_DIR, STORAGE_DIR] as $dir) {
            if (is_dir($dir) && !is_file($dir . '/.htaccess')) {
                @file_put_contents($dir . '/.htaccess', $deny);
            }
        }
        if (is_dir(STORAGE_DIR) && !is_file(STORAGE_DIR . '/probe.txt')) {
            @file_put_contents(STORAGE_DIR . '/probe.txt', "fava-storage-probe\n");
        }
    }

    public function run(array $params): void
    {
        $in = [
            'driver' => Request::str('driver') === 'sqlite' ? 'sqlite' : 'mysql',
            'db_host' => Request::str('db_host', 'localhost'),
            'db_name' => Request::str('db_name'),
            'db_user' => Request::str('db_user'),
            'db_prefix' => Request::str('db_prefix', 'fava_'),
            'org_name' => Request::str('org_name'),
            'app_url' => rtrim(Request::str('app_url'), '/'),
            'admin_name' => Request::str('admin_name'),
            'admin_user' => mb_strtolower(Request::str('admin_user')),
            'admin_email' => mb_strtolower(Request::str('admin_email')),
            'pretty_urls' => Request::str('pretty_urls') === '1' ? '1' : '0',
        ];
        $dbPass = isset($_POST['db_pass']) && is_string($_POST['db_pass']) ? $_POST['db_pass'] : '';
        $pass = isset($_POST['admin_pass']) && is_string($_POST['admin_pass']) ? $_POST['admin_pass'] : '';
        $pass2 = isset($_POST['admin_pass2']) && is_string($_POST['admin_pass2']) ? $_POST['admin_pass2'] : '';

        $errors = [];
        foreach (self::checks() as $c) {
            if ($c[2] && !$c[1]) {
                $errors['checks'] = 'ابتدا پیش‌نیازهای قرمزرنگ بالای صفحه را برطرف کنید.';
                break;
            }
        }
        if ($in['driver'] === 'mysql') {
            if ($in['db_name'] === '') {
                $errors['db_name'] = 'نام پایگاه‌داده را وارد کنید.';
            }
            if ($in['db_user'] === '') {
                $errors['db_user'] = 'نام کاربری پایگاه‌داده را وارد کنید.';
            }
            if ($in['db_host'] === '') {
                $in['db_host'] = 'localhost';
            }
        }
        if (!preg_match('/^[A-Za-z0-9_]{0,20}$/', $in['db_prefix'])) {
            $errors['db_prefix'] = 'پیشوند فقط می‌تواند حروف انگلیسی، عدد و _ باشد.';
        }
        if ($in['org_name'] === '') {
            $in['org_name'] = 'پارک فاوا';
        }
        if ($in['app_url'] !== '' && !preg_match('#^https?://[^\s/]+#i', $in['app_url'])) {
            $errors['app_url'] = 'نشانی سامانه باید با http:// یا https:// شروع شود.';
        }
        if ($in['admin_name'] === '') {
            $errors['admin_name'] = 'نام مدیر سامانه را وارد کنید.';
        }
        if (!preg_match('/^[a-z0-9._\-]{3,60}$/', $in['admin_user'])) {
            $errors['admin_user'] = 'نام کاربری باید ۳ تا ۶۰ نویسه و فقط شامل حروف انگلیسی کوچک، عدد، نقطه، خط تیره یا _ باشد.';
        }
        if ($in['admin_email'] !== '' && !filter_var($in['admin_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['admin_email'] = 'نشانی ایمیل معتبر نیست.';
        }
        if (mb_strlen($pass) < 8) {
            $errors['admin_pass'] = 'رمز عبور باید حداقل ۸ نویسه باشد.';
        } elseif ($pass !== $pass2) {
            $errors['admin_pass2'] = 'تکرار رمز عبور با رمز یکسان نیست.';
        }
        if ($errors) {
            $this->show($in, $errors, 422);
            return;
        }

        // ---- database -----------------------------------------------------------
        if ($in['driver'] === 'sqlite') {
            $dbConfig = ['driver' => 'sqlite', 'path' => $this->sqliteFile(), 'prefix' => $in['db_prefix']];
        } else {
            $dbConfig = [
                'driver' => 'mysql',
                'host' => $in['db_host'],
                'name' => $in['db_name'],
                'user' => $in['db_user'],
                'pass' => $dbPass,
                'prefix' => $in['db_prefix'],
            ];
        }
        try {
            $db = DB::connect($dbConfig);
            Schema::install($db);
        } catch (\Throwable $e) {
            Logger::exception($e, 'installer: database');
            $this->show($in, ['db' => self::explainDbError($e)], 422);
            return;
        }

        // ---- config, settings, first admin ---------------------------------------
        try {
            DB::setInstance($db);
            Config::write([
                'installed' => true,
                'installed_at' => gmdate('Y-m-d H:i:s'),
                'db' => $dbConfig,
                'app_key' => bin2hex(random_bytes(32)),
                'pretty_urls' => $in['pretty_urls'] === '1',
                'debug' => false,
                'trust_proxy' => true,
            ]);
            Settings::flush();
            Settings::setMany([
                'org_name' => $in['org_name'],
                'app_url' => $in['app_url'],
                'schema_version' => Schema::VERSION,
            ]);
            if ($in['admin_email'] !== '' && (string) Settings::get('notify_email', '') === '') {
                Settings::set('notify_email', $in['admin_email']);
            }

            $existing = $db->one('SELECT id FROM {admins} WHERE username = ?', [$in['admin_user']]);
            $data = [
                'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
                'name' => mb_substr($in['admin_name'], 0, 150),
                'email' => $in['admin_email'] !== '' ? $in['admin_email'] : null,
            ];
            if ($existing) {
                // the installer is being run again on an existing database: this account gets the new password
                $db->update('admins', $data, 'id = ?', [(int) $existing['id']]);
                $adminId = (int) $existing['id'];
            } else {
                $adminId = $db->insert('admins', $data + ['username' => $in['admin_user'], 'created_at' => now_utc()]);
            }
            $admin = $db->one('SELECT * FROM {admins} WHERE id = ?', [$adminId]);
        } catch (\Throwable $e) {
            Logger::exception($e, 'installer: finish');
            if (is_file(Config::file())) {
                @unlink(Config::file());
            }
            Config::load(true);
            Config::set('pretty_urls', false);
            DB::setInstance(null);
            $this->show($in, ['db' => 'نصب کامل نشد: ' . $e->getMessage()], 500);
            return;
        }

        Session::start();
        Auth::login($admin);
        Activity::log('install', 'سامانه نصب شد (نسخه ' . APP_VERSION . '، پایگاه‌داده ' . $db->serverVersion() . ').', null, 'admin', (string) $admin['name']);
        flash('success', 'سامانه با موفقیت نصب شد. برای شروع، تنظیمات ارسال ایمیل را کامل کنید و نخستین طرح را بارگذاری کنید.');
        redirect('/admin');
    }

    /** Reuse the database file of an earlier installation when there is exactly one; otherwise start a new one. */
    private function sqliteFile(): string
    {
        $found = glob(STORAGE_DIR . '/database/*.sqlite') ?: [];
        if (count($found) === 1) {
            return 'database/' . basename($found[0]);
        }
        return 'database/fava-' . bin2hex(random_bytes(8)) . '.sqlite';
    }

    public static function explainDbError(\Throwable $e): string
    {
        $m = $e->getMessage();
        if (strpos($m, '[1045]') !== false) {
            return 'نام کاربری یا رمز عبور پایگاه‌داده پذیرفته نشد. در cPanel نام کاربر پایگاه‌داده معمولاً پیشوند حساب را دارد (مثل cpuser_fava).';
        }
        if (strpos($m, '[1044]') !== false) {
            return 'این کاربر به پایگاه‌داده دسترسی ندارد. در cPanel › MySQL Databases کاربر را با ALL PRIVILEGES به پایگاه‌داده اضافه کنید.';
        }
        if (strpos($m, '[1049]') !== false) {
            return 'پایگاه‌داده‌ای با این نام وجود ندارد. نام کامل آن را (با پیشوند حساب، مثل cpuser_fava) وارد کنید.';
        }
        if (strpos($m, '[2002]') !== false || strpos($m, '[2005]') !== false || strpos($m, 'getaddrinfo') !== false) {
            return 'اتصال به سرور پایگاه‌داده برقرار نشد. نام میزبان را بررسی کنید (در cPanel معمولاً localhost است).';
        }
        if (strpos($m, '[1142]') !== false) {
            return 'کاربر پایگاه‌داده اجازه ساخت جدول ندارد. همه دسترسی‌ها (ALL PRIVILEGES) را به او بدهید.';
        }
        return 'خطای پایگاه‌داده: ' . $m;
    }
}
