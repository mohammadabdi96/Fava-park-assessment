<?php

namespace Fava\Core;

use Fava\Domain\Settings;

class App
{
    public static function run(): void
    {
        self::configureErrors();
        try {
            self::headers();
            /** @var Router $router */
            $router = require APP_ROOT . '/app/routes.php';
            $path = Request::path();
            $installed = Config::installed();

            if (!$installed) {
                // Until the installer has found out whether URL rewriting works, use the form
                // of URL that works on every server: index.php?r=/path
                Config::set('pretty_urls', false);
                if (strncmp($path, '/install', 8) !== 0 && $path !== '/__rewrite_test') {
                    redirect('/install');
                }
            }

            // PHP silently drops the whole body when it is larger than post_max_size
            if (Request::isPost() && !$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
                $limit = ini_bytes((string) ini_get('post_max_size'));
                if ($limit > 0 && (int) $_SERVER['CONTENT_LENGTH'] > $limit) {
                    throw new HttpException(413, 'حجم اطلاعات ارسالی (' . filesize_h((int) $_SERVER['CONTENT_LENGTH']) . ') از سقف مجاز سرور (' . filesize_h($limit) . ') بیشتر است. فایل‌ها را جداگانه بارگذاری کنید یا مقدار post_max_size را در تنظیمات PHP هاست افزایش دهید.');
                }
            }

            list($handler, $access, $params) = $router->match(Request::method(), $path);

            if ($access === 'install') {
                if ($installed) {
                    throw new HttpException(404, 'سامانه قبلاً نصب شده است.');
                }
            } elseif ($access === 'admin' || $access === 'guest') {
                Session::start();
                if ($access === 'admin') {
                    if (!Auth::check()) {
                        if (Request::wantsJson()) {
                            throw new HttpException(401, 'نشست شما به پایان رسیده است. دوباره وارد شوید.');
                        }
                        Session::set('_intended', $path);
                        redirect('/admin/login');
                    }
                    if (Request::isPost()) {
                        Csrf::verifyOrFail();
                    }
                } elseif (Request::isPost()) {
                    Csrf::verifyOrFail();
                }
            }

            $controller = new $handler[0]();
            $controller->{$handler[1]}($params);
        } catch (HttpException $e) {
            self::fail($e->status, $e->getMessage());
        } catch (\Throwable $e) {
            Logger::exception($e, Request::method() . ' ' . ($_SERVER['REQUEST_URI'] ?? ''));
            $message = 'خطایی در سامانه رخ داد. لطفاً دوباره تلاش کنید؛ اگر مشکل ادامه داشت با مدیر سامانه تماس بگیرید.';
            if (Config::get('debug', false)) {
                $message .= "\n\n" . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine();
            }
            self::fail(500, $message);
        }
    }

    private static function configureErrors(): void
    {
        error_reporting(E_ALL);
        $debug = (bool) Config::get('debug', false);
        @ini_set('display_errors', $debug ? '1' : '0');
        @ini_set('log_errors', '1');
        // In debug mode every warning stops the request, so nothing fails silently while testing.
        // In production warnings are logged and the request carries on.
        set_error_handler(function ($severity, $message, $file, $line) use ($debug) {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            $minor = in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true);
            if ($debug && !$minor) {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            }
            Logger::info('PHP ' . ($minor ? 'deprecation' : 'warning') . ': ' . $message, ['file' => $file . ':' . $line]);
            return true;
        });
    }

    private static function headers(): void
    {
        if (headers_sent()) {
            return;
        }
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store, max-age=0');
        // FAVA_EMBED=1 is a development switch for previews that show the site inside another site's frame
        if (Config::get('security.frame_protection', true) && !getenv('FAVA_EMBED')) {
            header('X-Frame-Options: SAMEORIGIN');
        }
        if (Request::isHttps() && Config::get('security.hsts', false)) {
            header('Strict-Transport-Security: max-age=15552000');
        }
    }

    public static function fail(int $status, string $message): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if ($message === '') {
            $message = $status === 404 ? 'صفحه مورد نظر پیدا نشد.' : 'خطایی رخ داد.';
        }
        if (Request::wantsJson()) {
            View::json(['ok' => false, 'error' => $message], $status);
            return;
        }
        $org = 'پارک فاوا';
        try {
            if (Config::installed()) {
                $org = (string) Settings::get('org_name', $org);
            }
        } catch (\Throwable $e) {
            // the error page must never fail
        }
        try {
            View::render('errors/error', ['status' => $status, 'message' => $message, 'org' => $org], null, $status);
        } catch (\Throwable $e) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
            echo '<div dir="rtl" style="font-family:Tahoma;padding:2rem">' . nl2br(e($message)) . '</div>';
        }
    }
}
