<?php

namespace Fava\Domain;

use Fava\Core\Config;
use Fava\Core\DB;

/**
 * Settings the admin edits in the panel (stored as JSON in the settings table).
 */
class Settings
{
    /** @var array|null */
    private static $cache = null;

    public static function defaults(): array
    {
        return [
            'org_name' => 'پارک فاوا',
            'system_title' => 'سامانه ارزش‌گذاری طرح‌ها',
            'form_title' => 'کاربرگ ارزش‌گذاری استراتژیک طرح',
            'app_url' => '',
            'timezone' => 'Asia/Tehran',
            'support_contact' => '',
            'judge_show_doc' => 1,
            'judge_allow_download' => 1,
            'max_upload_mb' => 20,
            'notify_email' => '',
            'notify_on_submit' => 1,
            'mail' => [
                'transport' => 'none', // smtp | mail | none
                'host' => '',
                'port' => 465,
                'encryption' => 'ssl', // ssl | tls | none
                'username' => '',
                'password' => '',
                'from_email' => '',
                'from_name' => '',
                'verify_peer' => 1,
            ],
            'mail_invite_subject' => 'دعوت به داوری طرح «{title}»',
            'mail_invite_body' => "{judge} گرامی،\nبا سلام و احترام\n\n"
                . "از شما دعوت می‌شود به‌عنوان {role}، طرح «{title}» از «{company}» را در {org} ارزیابی فرمایید.\n"
                . "کاربرگ ارزش‌گذاری به‌صورت برخط آماده شده است و اطلاعات طرح در همان صفحه در دسترس شماست.\n\n"
                . "{deadline}"
                . "این پیوند مخصوص شماست؛ لطفاً آن را در اختیار دیگران قرار ندهید. تا پیش از ثبت نهایی می‌توانید فرم را ببندید و بعداً ادامه دهید.\n\n"
                . "با سپاس از همکاری شما\n{org}",
            'mail_reminder_subject' => 'یادآوری: داوری طرح «{title}»',
            'mail_reminder_body' => "{judge} گرامی،\nبا سلام و احترام\n\n"
                . "کاربرگ ارزش‌گذاری طرح «{title}» از «{company}» هنوز ثبت نهایی نشده است. خواهشمند است در اولین فرصت آن را تکمیل فرمایید.\n\n"
                . "{deadline}"
                . "با سپاس\n{org}",
            'scoring' => null,
            'schema_version' => 0,
        ];
    }

    public static function all(): array
    {
        if (self::$cache === null) {
            $data = self::defaults();
            if (Config::installed()) {
                foreach (DB::get()->all('SELECT skey, svalue FROM {settings}') as $row) {
                    $decoded = json_decode((string) $row['svalue'], true);
                    if ($decoded === null && $row['svalue'] !== 'null') {
                        continue;
                    }
                    if ($row['skey'] === 'mail' && is_array($decoded)) {
                        $decoded = array_merge($data['mail'], $decoded);
                    }
                    $data[$row['skey']] = $decoded;
                }
            }
            self::$cache = $data;
        }
        return self::$cache;
    }

    public static function get(string $key, $default = null)
    {
        $all = self::all();
        return array_key_exists($key, $all) && $all[$key] !== null ? $all[$key] : $default;
    }

    public static function set(string $key, $value): void
    {
        $db = DB::get();
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $exists = $db->val('SELECT COUNT(*) FROM {settings} WHERE skey = ?', [$key]);
        if ($exists) {
            $db->update('settings', ['svalue' => $json], 'skey = ?', [$key]);
        } else {
            $db->insert('settings', ['skey' => $key, 'svalue' => $json]);
        }
        self::$cache = null;
    }

    public static function setMany(array $pairs): void
    {
        DB::get()->tx(function () use ($pairs) {
            foreach ($pairs as $k => $v) {
                self::set($k, $v);
            }
        });
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    /** Largest upload the server will actually accept, in bytes. */
    public static function maxUploadBytes(): int
    {
        $limits = [((int) self::get('max_upload_mb', 20)) * 1048576];
        foreach (['upload_max_filesize', 'post_max_size'] as $ini) {
            $b = ini_bytes((string) ini_get($ini));
            if ($b > 0) {
                $limits[] = $b;
            }
        }
        return (int) min($limits);
    }
}
