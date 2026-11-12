<?php

namespace Fava\Controllers;

use Fava\Core\Auth;
use Fava\Core\Config;
use Fava\Core\Crypto;
use Fava\Core\DB;
use Fava\Core\Fa;
use Fava\Core\Request;
use Fava\Core\Session;
use Fava\Domain\Activity;
use Fava\Domain\FormDef;
use Fava\Domain\Mailer;
use Fava\Domain\Notifier;
use Fava\Domain\Settings;

class SettingsController extends Controller
{
    private const TABS = ['general', 'scoring', 'mail', 'account', 'system'];

    public function index(array $params): void
    {
        $tab = (string) Request::query('tab', 'general');
        if (!in_array($tab, self::TABS, true)) {
            $tab = 'general';
        }
        $db = DB::get();
        $mail = (array) Settings::get('mail', []) + Settings::defaults()['mail'];
        $hasPassword = (string) $mail['password'] !== '';
        unset($mail['password']);
        $mailTest = Session::get('mail_test');
        Session::forget('mail_test');

        $system = [];
        if ($tab === 'system') {
            $free = @disk_free_space(STORAGE_DIR);
            $system = [
                'نسخه سامانه' => APP_VERSION,
                'PHP' => PHP_VERSION . ' (' . PHP_SAPI . ')',
                'پایگاه‌داده' => $db->serverVersion(),
                'پیشوند جدول‌ها' => $db->prefix,
                'نشانی‌های خوانا (mod_rewrite)' => Config::get('pretty_urls', true) ? 'فعال' : 'غیرفعال (index.php?r=…)',
                'حداکثر حجم بارگذاری' => filesize_h(Settings::maxUploadBytes()) . ' (upload_max_filesize=' . ini_get('upload_max_filesize') . '، post_max_size=' . ini_get('post_max_size') . ')',
                'خواننده فایل Word' => class_exists('ZipArchive') ? 'ZipArchive' : 'خواننده داخلی (بدون افزونه zip)',
                'OpenSSL' => extension_loaded('openssl') ? 'فعال' : 'غیرفعال',
                'پوشه storage' => STORAGE_DIR . (is_writable(STORAGE_DIR) ? '' : ' — قابل نوشتن نیست!'),
                'فضای آزاد دیسک' => $free ? filesize_h($free) : '—',
                'تعداد طرح‌ها' => fa((string) $db->val('SELECT COUNT(*) FROM {proposals}')),
                'زمان سرور' => jdate(time(), 'Y/m/d H:i') . ' (به وقت تهران)',
            ];
        }

        $this->admin('admin/settings', [
            'pageTitle' => 'تنظیمات',
            'tab' => $tab,
            's' => Settings::all(),
            'mail' => $mail,
            'hasPassword' => $hasPassword,
            'mailReady' => Mailer::configured(),
            'mailTest' => is_array($mailTest) ? $mailTest : null,
            'mailLog' => $tab === 'mail' ? $db->all('SELECT * FROM {mail_log} ORDER BY id DESC LIMIT 12') : [],
            'scoring' => FormDef::currentConfig(),
            'scoringDefaults' => FormDef::get()['defaults'],
            'scoringCustom' => is_array(Settings::get('scoring')),
            'def' => FormDef::get(),
            'placeholders' => Notifier::PLACEHOLDERS,
            'admin' => Auth::user(),
            'system' => $system,
            'storageProbe' => Request::basePath() . '/storage/probe.txt',
            'detectedUrl' => Request::origin() . Request::basePath(),
        ], 'settings');
    }

    private function backTo(string $tab): void
    {
        redirect('/admin/settings', ['tab' => $tab]);
    }

    private function failed(string $tab, array $errors): void
    {
        foreach ($errors as $m) {
            flash('error', $m);
        }
        Session::keepInput($_POST);
        $this->backTo($tab);
    }

    public function saveGeneral(array $params): void
    {
        $org = Fa::clean(Request::str('org_name'));
        $url = rtrim(Request::str('app_url'), '/');
        $notify = mb_strtolower(Request::str('notify_email'));
        $maxMb = Request::int('max_upload_mb', 20);
        $errors = [];
        if ($org === '') {
            $errors[] = 'نام سازمان را وارد کنید.';
        }
        if ($url !== '' && !preg_match('#^https?://[^\s/]+#i', $url)) {
            $errors[] = 'نشانی سامانه باید با http:// یا https:// شروع شود.';
        }
        if ($notify !== '' && !filter_var($notify, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'ایمیل دریافت اعلان‌ها معتبر نیست.';
        }
        if ($maxMb < 1 || $maxMb > 200) {
            $errors[] = 'حداکثر حجم فایل باید بین ۱ تا ۲۰۰ مگابایت باشد.';
        }
        if ($errors) {
            $this->failed('general', $errors);
        }
        Settings::setMany([
            'org_name' => mb_substr($org, 0, 150),
            'system_title' => mb_substr(Fa::clean(Request::str('system_title')) ?: 'سامانه ارزش‌گذاری طرح‌ها', 0, 150),
            'form_title' => mb_substr(Fa::clean(Request::str('form_title')) ?: 'کاربرگ ارزش‌گذاری استراتژیک طرح', 0, 150),
            'app_url' => $url,
            'support_contact' => mb_substr(Fa::clean(Request::str('support_contact')), 0, 250),
            'notify_email' => $notify,
            'notify_on_submit' => Request::bool('notify_on_submit') ? 1 : 0,
            'judge_show_doc' => Request::bool('judge_show_doc') ? 1 : 0,
            'judge_allow_download' => Request::bool('judge_allow_download') ? 1 : 0,
            'max_upload_mb' => $maxMb,
        ]);
        Activity::log('settings', 'تنظیمات عمومی ویرایش شد.');
        flash('success', 'تنظیمات ذخیره شد.');
        $this->backTo('general');
    }

    public function saveScoring(array $params): void
    {
        if (Request::str('action') === 'reset') {
            Settings::set('scoring', null);
            Activity::log('settings', 'پارامترهای امتیازدهی به مقادیر پیش‌فرض برگشت.');
            flash('success', 'پارامترهای امتیازدهی به مقادیر پیش‌فرض برگشت.');
            $this->backTo('scoring');
        }
        $num = function (string $key): ?float {
            return Fa::number(Request::str($key));
        };
        $errors = [];
        $tiers = [];
        foreach (['A', 'B', 'C'] as $t) {
            $v = $num('ceiling_' . $t);
            if ($v === null || $v <= 0 || $v > 1000000) {
                $errors[] = 'سقف حمایتی هر سطح باید عددی بزرگ‌تر از صفر باشد.';
                break;
            }
            $tiers[$t] = ['ceiling' => round($v, 3)];
        }
        if (!$errors && !($tiers['A']['ceiling'] >= $tiers['B']['ceiling'] && $tiers['B']['ceiling'] >= $tiers['C']['ceiling'])) {
            $errors[] = 'سقف سطح الف نباید از سطح ب، و سقف سطح ب نباید از سطح ج کمتر باشد.';
        }
        $reject = $num('reject_below');
        $conditional = $num('conditional_below');
        if ($reject === null || $conditional === null || $reject <= 0 || $conditional > 100 || $reject >= $conditional) {
            $errors[] = 'آستانه‌ها باید بین ۰ و ۱۰۰ باشند و آستانه رد از آستانه تأیید کمتر باشد.';
        }
        $weights = [];
        foreach (['tech', 'team', 'market', 'finance'] as $dim) {
            $v = $num('w_' . $dim);
            if ($v === null || $v <= 0 || $v >= 100) {
                $errors[] = 'وزن هر بُعد باید عددی بین ۰ و ۱۰۰ باشد.';
                break;
            }
            $weights[$dim] = $v / 100;
        }
        if (count($weights) === 4 && abs(array_sum($weights) - 1) > 0.0001) {
            $errors[] = 'مجموع وزن چهار بُعد باید ۱۰۰ باشد (اکنون ' . nf(array_sum($weights) * 100) . ' است).';
        }
        $base = $num('risk_base');
        $step = $num('risk_step');
        if ($base === null || $step === null || $base <= 0 || $base > 2 || $step < 0 || $step > 0.5) {
            $errors[] = 'ضریب پایه ریسک باید بین ۰ و ۲ و گام آن بین ۰ و ۰٫۵ باشد.';
        }
        $payment = [];
        foreach ([1, 2, 3] as $i) {
            $v = $num('pay_' . $i);
            if ($v === null || $v < 0 || $v > 100) {
                $errors[] = 'درصد هر مرحله پرداخت باید بین ۰ و ۱۰۰ باشد.';
                break;
            }
            $payment[] = $v;
        }
        if (count($payment) === 3 && abs(array_sum($payment) - 100) > 0.0001) {
            $errors[] = 'مجموع درصد سه مرحله پرداخت باید ۱۰۰ باشد.';
        }
        if ($errors) {
            $this->failed('scoring', $errors);
        }
        Settings::set('scoring', [
            'tiers' => $tiers,
            'thresholds' => ['reject_below' => $reject, 'conditional_below' => $conditional],
            'weights' => $weights,
            'risk' => ['base' => $base, 'step' => $step],
            'payment' => $payment,
            'cap_amount' => Request::bool('cap_amount'),
        ]);
        Activity::log('settings', 'پارامترهای امتیازدهی ویرایش شد.');
        flash('success', 'پارامترهای امتیازدهی ذخیره شد. این مقادیر برای طرح‌هایی به‌کار می‌رود که هنوز داوری برایشان تعیین نشده است.');
        $this->backTo('scoring');
    }

    public function saveMail(array $params): void
    {
        if (Request::str('section') === 'templates') {
            $pairs = [];
            foreach (['mail_invite_subject', 'mail_invite_body', 'mail_reminder_subject', 'mail_reminder_body'] as $key) {
                $value = str_replace("\r\n", "\n", Request::str($key));
                if ($value === '') {
                    $value = Settings::defaults()[$key];
                }
                $pairs[$key] = mb_substr($value, 0, strpos($key, 'subject') !== false ? 200 : 5000);
            }
            Settings::setMany($pairs);
            Activity::log('settings', 'متن ایمیل‌ها ویرایش شد.');
            flash('success', 'متن ایمیل‌ها ذخیره شد.');
            $this->backTo('mail');
        }

        $current = (array) Settings::get('mail', []) + Settings::defaults()['mail'];
        $transport = Request::str('transport');
        if (!in_array($transport, ['smtp', 'mail', 'none'], true)) {
            $transport = 'none';
        }
        $enc = Request::str('encryption');
        if (!in_array($enc, ['ssl', 'tls', 'none'], true)) {
            $enc = 'ssl';
        }
        $host = Request::str('host');
        $port = Request::int('port', $enc === 'ssl' ? 465 : ($enc === 'tls' ? 587 : 25));
        $from = mb_strtolower(Request::str('from_email'));
        $errors = [];
        if ($transport !== 'none' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'نشانی ایمیل فرستنده را درست وارد کنید (همان صندوقی که در cPanel ساخته‌اید).';
        }
        if ($transport === 'smtp') {
            if ($host === '' || !preg_match('/^[A-Za-z0-9.\-]+$/', $host)) {
                $errors[] = 'نام سرور SMTP را وارد کنید (مثلاً mail.example.ir).';
            }
            if ($port < 1 || $port > 65535) {
                $errors[] = 'درگاه SMTP معتبر نیست.';
            }
        }
        if ($errors) {
            $this->failed('mail', $errors);
        }
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        Settings::set('mail', [
            'transport' => $transport,
            'host' => $host,
            'port' => $port,
            'encryption' => $enc,
            'username' => Request::str('username'),
            // an empty field keeps the stored password
            'password' => $password !== '' ? Crypto::seal($password) : (string) $current['password'],
            'from_email' => $from,
            'from_name' => mb_substr(Fa::clean(Request::str('from_name')), 0, 100),
            'verify_peer' => Request::bool('verify_peer') ? 1 : 0,
        ]);
        Activity::log('settings', 'تنظیمات ارسال ایمیل ویرایش شد.');
        flash('success', 'تنظیمات ایمیل ذخیره شد. با دکمه «ارسال ایمیل آزمایشی» از درستی آن مطمئن شوید.');
        $this->backTo('mail');
    }

    public function testMail(array $params): void
    {
        $to = mb_strtolower(Request::str('to'));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->failed('mail', ['نشانی ایمیل گیرنده آزمایشی معتبر نیست.']);
        }
        $org = (string) Settings::get('org_name', 'پارک فاوا');
        $text = "این یک پیام آزمایشی از سامانه ارزش‌گذاری طرح‌های {$org} است.\nاگر این پیام را می‌بینید، تنظیمات ارسال ایمیل درست است.";
        $html = \Fava\Core\View::partial('emails/message', [
            'org' => $org,
            'heading' => 'ایمیل آزمایشی',
            'body' => $text,
            'button' => null,
            'facts' => ['زمان ارسال' => jdate(time(), 'Y/m/d H:i')],
            'footnote' => '',
            'footlink' => '',
        ]);
        $r = Mailer::send($to, '', 'ایمیل آزمایشی سامانه ' . $org, $text, $html, ['kind' => 'test']);
        Session::set('mail_test', ['ok' => $r['ok'], 'error' => $r['error'], 'to' => $to, 'log' => array_slice(Mailer::transcript(), -14)]);
        if ($r['ok']) {
            flash('success', 'ایمیل آزمایشی به ' . $to . ' تحویل سرور ایمیل شد. صندوق ورودی (و پوشه هرزنامه) را بررسی کنید.');
        } else {
            flash('error', 'ارسال ایمیل آزمایشی ناموفق بود: ' . $r['error']);
        }
        $this->backTo('mail');
    }

    public function savePassword(array $params): void
    {
        $db = DB::get();
        $id = (int) Auth::id();
        $row = $db->one('SELECT * FROM {admins} WHERE id = ?', [$id]);
        $name = Fa::clean(Request::str('name'));
        $email = mb_strtolower(Request::str('email'));
        $current = isset($_POST['current_password']) && is_string($_POST['current_password']) ? $_POST['current_password'] : '';
        $new = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $confirm = isset($_POST['password_confirm']) && is_string($_POST['password_confirm']) ? $_POST['password_confirm'] : '';
        $errors = [];
        if ($name === '') {
            $errors[] = 'نام را وارد کنید.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'نشانی ایمیل معتبر نیست.';
        }
        $data = ['name' => mb_substr($name, 0, 150), 'email' => $email !== '' ? $email : null];
        if ($new !== '' || $confirm !== '') {
            if (!$row || !password_verify($current, (string) $row['password_hash'])) {
                $errors[] = 'رمز عبور فعلی نادرست است.';
            } elseif (mb_strlen($new) < 8) {
                $errors[] = 'رمز عبور جدید باید حداقل ۸ نویسه باشد.';
            } elseif ($new !== $confirm) {
                $errors[] = 'تکرار رمز عبور جدید با آن یکسان نیست.';
            } else {
                $data['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
            }
        }
        if ($errors) {
            $this->failed('account', $errors);
        }
        $db->update('admins', $data, 'id = ?', [$id]);
        Auth::refresh($id);
        Activity::log('settings', isset($data['password_hash']) ? 'رمز عبور مدیر تغییر کرد.' : 'مشخصات حساب مدیر ویرایش شد.');
        flash('success', isset($data['password_hash']) ? 'رمز عبور تغییر کرد.' : 'مشخصات حساب ذخیره شد.');
        $this->backTo('account');
    }
}
