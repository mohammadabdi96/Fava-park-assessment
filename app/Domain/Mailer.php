<?php

namespace Fava\Domain;

use Fava\Core\Crypto;
use Fava\Core\DB;
use Fava\Core\Logger;
use Fava\Core\Request;

/**
 * Sends e-mail through an SMTP mailbox (the usual cPanel setup) or PHP's mail().
 * No external library: a small SMTP client over stream sockets.
 */
class Mailer
{
    /** @var string[] conversation with the server, kept for the «test» button */
    private static $transcript = [];

    public static function config(): array
    {
        $cfg = (array) Settings::get('mail', []);
        $cfg += Settings::defaults()['mail'];
        $cfg['password'] = Crypto::open((string) $cfg['password']);
        if ((string) $cfg['from_name'] === '') {
            $cfg['from_name'] = (string) Settings::get('org_name', '');
        }
        return $cfg;
    }

    public static function configured(?array $cfg = null): bool
    {
        $cfg = $cfg ?? self::config();
        if ($cfg['transport'] === 'smtp') {
            return $cfg['host'] !== '' && filter_var($cfg['from_email'], FILTER_VALIDATE_EMAIL) !== false;
        }
        if ($cfg['transport'] === 'mail') {
            return filter_var($cfg['from_email'], FILTER_VALIDATE_EMAIL) !== false;
        }
        return false;
    }

    public static function transcript(): array
    {
        return self::$transcript;
    }

    /**
     * @param array $meta ['kind' => string, 'proposal_id' => ?int, 'assignment_id' => ?int, 'reply_to' => ?string]
     * @return array{ok:bool,error:string}
     */
    public static function send(string $toEmail, string $toName, string $subject, string $text, string $html, array $meta = [], ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        self::$transcript = [];
        $error = '';
        $toEmail = trim($toEmail);
        try {
            if (!self::configured($cfg)) {
                throw new \RuntimeException('ارسال ایمیل هنوز در تنظیمات سامانه پیکربندی نشده است.');
            }
            if (filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
                throw new \RuntimeException('نشانی ایمیل گیرنده معتبر نیست.');
            }
            $message = self::build($cfg, $toEmail, $toName, $subject, $text, $html, $meta);
            if ($cfg['transport'] === 'smtp') {
                self::smtp($cfg, $toEmail, $message['headers'] . "\r\n" . $message['body']);
            } else {
                self::phpMail($cfg, $toEmail, $message);
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            Logger::error('mail to ' . $toEmail . ' failed: ' . $error);
        }
        self::log($toEmail, $subject, $meta, $error);
        return ['ok' => $error === '', 'error' => $error];
    }

    private static function log(string $to, string $subject, array $meta, string $error): void
    {
        try {
            DB::get()->insert('mail_log', [
                'created_at' => now_utc(),
                'to_email' => mb_substr($to, 0, 190),
                'subject' => mb_substr($subject, 0, 250),
                'kind' => isset($meta['kind']) ? mb_substr((string) $meta['kind'], 0, 30) : null,
                'status' => $error === '' ? 'sent' : 'failed',
                'error' => $error !== '' ? mb_substr($error, 0, 2000) : null,
                'proposal_id' => $meta['proposal_id'] ?? null,
                'assignment_id' => $meta['assignment_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Logger::exception($e, 'mail log');
        }
    }

    // ---- message ---------------------------------------------------------------

    /** RFC 2047 encoded-word(s) for a header value. */
    public static function encodeHeader(string $value): string
    {
        $value = trim((string) preg_replace('/[\r\n\t]+/', ' ', $value));
        if ($value === '' || !preg_match('/[^\x20-\x7E]/', $value)) {
            return $value;
        }
        $words = [];
        $chunk = '';
        $length = mb_strlen($value, 'UTF-8');
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($value, $i, 1, 'UTF-8');
            // an encoded word may not exceed 75 characters: =?UTF-8?B?…?= leaves 63 for base64 (45 bytes)
            if (strlen($chunk . $char) > 45) {
                $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
                $chunk = '';
            }
            $chunk .= $char;
        }
        if ($chunk !== '') {
            $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }
        return implode("\r\n ", $words);
    }

    private static function address(string $email, string $name): string
    {
        $name = trim((string) preg_replace('/[\r\n"<>]+/', ' ', $name));
        if ($name === '') {
            return $email;
        }
        if (preg_match('/[^\x20-\x7E]/', $name)) {
            return self::encodeHeader($name) . ' <' . $email . '>';
        }
        return '"' . addcslashes($name, '\\"') . '" <' . $email . '>';
    }

    private static function domainOf(string $email): string
    {
        $pos = strrpos($email, '@');
        $domain = $pos !== false ? substr($email, $pos + 1) : '';
        return preg_match('/^[A-Za-z0-9.\-]+$/', $domain) ? $domain : 'localhost';
    }

    /** @return array{headers:string,body:string,subject:string,header_list:string[]} */
    private static function build(array $cfg, string $toEmail, string $toName, string $subject, string $text, string $html, array $meta): array
    {
        $from = (string) $cfg['from_email'];
        $boundary = 'fava-' . bin2hex(random_bytes(12));
        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'From: ' . self::address($from, (string) $cfg['from_name']),
            'To: ' . self::address($toEmail, $toName),
            'Subject: ' . self::encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . self::domainOf($from) . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: Fava-Assessment/' . APP_VERSION,
        ];
        if (!empty($meta['reply_to']) && filter_var($meta['reply_to'], FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: ' . $meta['reply_to'];
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $html = str_replace(["\r\n", "\r"], "\n", $html);
        $body = "This is a multi-part message in MIME format.\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text), 76, "\r\n")
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html), 76, "\r\n")
            . '--' . $boundary . "--\r\n";
        return ['headers' => implode("\r\n", $headers) . "\r\n", 'body' => $body, 'subject' => $subject, 'header_list' => $headers];
    }

    // ---- transports ------------------------------------------------------------

    private static function phpMail(array $cfg, string $to, array $message): void
    {
        if (!function_exists('mail')) {
            throw new \RuntimeException('تابع mail() روی این سرور غیرفعال است؛ از روش SMTP استفاده کنید.');
        }
        // mail() adds To and Subject itself
        $headers = [];
        foreach ($message['header_list'] as $h) {
            if (stripos($h, 'To:') === 0 || stripos($h, 'Subject:') === 0) {
                continue;
            }
            $headers[] = $h;
        }
        $subject = self::encodeHeader($message['subject']);
        $extra = implode("\r\n", $headers);
        $ok = @mail($to, $subject, $message['body'], $extra, '-f' . $cfg['from_email']);
        if (!$ok) {
            $ok = @mail($to, $subject, $message['body'], $extra);
        }
        if (!$ok) {
            throw new \RuntimeException('سرور ارسال ایمیل با تابع mail() را نپذیرفت. روش SMTP را در تنظیمات امتحان کنید.');
        }
    }

    private static function smtp(array $cfg, string $to, string $data): void
    {
        $host = trim((string) $cfg['host']);
        $port = (int) $cfg['port'] ?: 465;
        $enc = (string) $cfg['encryption'];
        $verify = !empty($cfg['verify_peer']);
        if (($enc === 'ssl' || $enc === 'tls') && !extension_loaded('openssl')) {
            throw new \RuntimeException('افزونه OpenSSL در PHP فعال نیست؛ اتصال امن SMTP ممکن نیست.');
        }
        $context = stream_context_create(['ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);
        if (!can_call('stream_socket_client')) {
            throw new \RuntimeException('این هاست اجازه اتصال به سرور ایمیل را نمی‌دهد (تابع stream_socket_client غیرفعال است). از پشتیبانی هاست بخواهید آن را فعال کند، یا روش «تابع mail()» را امتحان کنید.');
        }
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client(($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
        if (!$fp) {
            $hint = $enc === 'ssl' && $verify ? ' اگر گواهی سرور ایمیل معتبر نیست، گزینه «بررسی گواهی» را خاموش کنید.' : '';
            throw new \RuntimeException('اتصال به سرور SMTP برقرار نشد (' . $host . ':' . $port . '): ' . ($errstr !== '' ? $errstr : 'خطای ' . $errno) . '.' . $hint);
        }
        stream_set_timeout($fp, 20);
        try {
            self::expect($fp, [220]);
            $helo = self::heloName();
            $caps = self::command($fp, 'EHLO ' . $helo, [250]);
            if ($enc === 'tls') {
                if (stripos($caps, 'STARTTLS') === false) {
                    throw new \RuntimeException('سرور SMTP از STARTTLS پشتیبانی نمی‌کند؛ نوع رمزنگاری را SSL (درگاه ۴۶۵) انتخاب کنید.');
                }
                self::command($fp, 'STARTTLS', [220]);
                $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                    $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                }
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                    $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                }
                if (@stream_socket_enable_crypto($fp, true, $method) !== true) {
                    throw new \RuntimeException('برقراری اتصال امن (TLS) با سرور SMTP ناموفق بود.' . ($verify ? ' اگر گواهی سرور معتبر نیست، گزینه «بررسی گواهی» را خاموش کنید.' : ''));
                }
                $caps = self::command($fp, 'EHLO ' . $helo, [250]);
            }
            $user = (string) $cfg['username'];
            if ($user !== '') {
                $pass = (string) $cfg['password'];
                if (preg_match('/AUTH[ =][^\r\n]*\bPLAIN\b/i', $caps) && !preg_match('/AUTH[ =][^\r\n]*\bLOGIN\b/i', $caps)) {
                    self::command($fp, 'AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass), [235], 'AUTH PLAIN ***');
                } else {
                    self::command($fp, 'AUTH LOGIN', [334]);
                    self::command($fp, base64_encode($user), [334], '(username)');
                    self::command($fp, base64_encode($pass), [235], '(password)');
                }
            }
            self::command($fp, 'MAIL FROM:<' . $cfg['from_email'] . '>', [250]);
            self::command($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
            self::command($fp, 'DATA', [354]);
            // normalise line ends and protect lines that start with a dot
            $data = (string) preg_replace('/\r\n|\r|\n/', "\r\n", $data);
            $data = (string) preg_replace('/^\./m', '..', $data);
            self::write($fp, rtrim($data, "\r\n") . "\r\n.\r\n");
            self::$transcript[] = 'C: (message, ' . strlen($data) . ' bytes)';
            self::expect($fp, [250]);
            try {
                self::command($fp, 'QUIT', [221]);
            } catch (\Throwable $e) {
                // the message has been accepted; a rude goodbye does not matter
            }
        } finally {
            @fclose($fp);
        }
    }

    private static function heloName(): string
    {
        $host = Request::host();
        $host = (string) preg_replace('/:\d+$/', '', $host);
        if ($host === '' || $host === 'localhost' || !preg_match('/^[A-Za-z0-9.\-]+$/', $host)) {
            $host = (string) ((can_call('gethostname') ? gethostname() : '') ?: 'localhost');
        }
        return $host;
    }

    private static function write($fp, string $data): void
    {
        $len = strlen($data);
        $sent = 0;
        while ($sent < $len) {
            $n = @fwrite($fp, substr($data, $sent));
            if ($n === false || $n === 0) {
                throw new \RuntimeException('ارتباط با سرور SMTP هنگام ارسال قطع شد.');
            }
            $sent += $n;
        }
    }

    private static function command($fp, string $line, array $ok, ?string $shown = null): string
    {
        self::$transcript[] = 'C: ' . ($shown ?? $line);
        self::write($fp, $line . "\r\n");
        return self::expect($fp, $ok);
    }

    private static function expect($fp, array $ok): string
    {
        $response = '';
        $code = 0;
        for ($i = 0; $i < 100; $i++) {
            $line = fgets($fp, 2048);
            if ($line === false) {
                $meta = stream_get_meta_data($fp);
                throw new \RuntimeException(!empty($meta['timed_out']) ? 'سرور SMTP در زمان مقرر پاسخ نداد.' : 'سرور SMTP ارتباط را بست.');
            }
            $response .= $line;
            self::$transcript[] = 'S: ' . rtrim($line);
            if (strlen($line) < 4 || $line[3] !== '-') {
                $code = (int) substr($line, 0, 3);
                break;
            }
        }
        if (!in_array($code, $ok, true)) {
            $detail = trim((string) preg_replace('/\s+/', ' ', $response));
            if ($code === 535 || $code === 534) {
                throw new \RuntimeException('نام کاربری یا رمز عبور صندوق ایمیل پذیرفته نشد. (' . $detail . ')');
            }
            if ($code >= 550 && $code <= 554) {
                throw new \RuntimeException('سرور ایمیل پیام را نپذیرفت: ' . $detail);
            }
            throw new \RuntimeException('پاسخ غیرمنتظره از سرور SMTP: ' . ($detail !== '' ? $detail : 'بدون پاسخ'));
        }
        return $response;
    }
}
