<?php

use Fava\Domain\Assignments;
use Fava\Domain\Mailer;
use Fava\Domain\Notifier;
use Fava\Domain\Proposals;
use Fava\Domain\Settings;

require_once __DIR__ . '/../support/db.php';

/**
 * Start the fake SMTP server (tests/support/fake_smtp.php).
 * @return array{port:int,dir:string,proc:resource}
 */
function smtp_server(string $mode, string $user = '', string $pass = ''): array
{
    $dir = tmp_dir('fava-smtp-');
    $port = free_port();
    $cmd = php_command() . ' ' . escapeshellarg(__DIR__ . '/../support/fake_smtp.php') . ' ' . $port . ' ' . $mode . ' ' . escapeshellarg($dir);
    if ($user !== '') {
        $cmd .= ' ' . escapeshellarg($user) . ' ' . escapeshellarg($pass);
    }
    $proc = start_background($cmd, $dir . '/server.log');
    $end = microtime(true) + 15;
    while (!is_file($dir . '/ready')) {
        if (microtime(true) > $end) {
            throw new \RuntimeException('fake SMTP server did not start: ' . @file_get_contents($dir . '/server.log'));
        }
        usleep(30000);
    }
    return ['port' => $port, 'dir' => $dir, 'proc' => $proc];
}

function smtp_stop(array $server): void
{
    @touch($server['dir'] . '/stop');
    stop_background($server['proc']);
}

/** @return array{raw:string,headers:array,envelope:array,text:string,html:string} */
function smtp_message(array $server, int $n = 1): array
{
    $file = $server['dir'] . '/message-' . $n . '.eml';
    $end = microtime(true) + 20; // returns as soon as the message is there; generous for a busy machine
    while (!is_file($server['dir'] . '/message-' . $n . '.json') && microtime(true) < $end) {
        usleep(20000);
    }
    if (!is_file($file)) {
        throw new TestFailure('message ' . $n . ' never arrived at the fake server');
    }
    $raw = (string) file_get_contents($file);
    list($head, $body) = explode("\r\n\r\n", $raw, 2);
    $headers = [];
    foreach (preg_split('/\r\n(?![ \t])/', $head) as $line) {
        list($k, $v) = explode(':', $line, 2);
        $headers[strtolower($k)] = trim((string) preg_replace('/\r\n[ \t]+/', ' ', $v));
    }
    $parts = ['text' => '', 'html' => ''];
    if (preg_match('/boundary="([^"]+)"/', $headers['content-type'] ?? '', $m)) {
        foreach (explode('--' . $m[1], $body) as $part) {
            if (strpos($part, "\r\n\r\n") === false) {
                continue;
            }
            list($ph, $pb) = explode("\r\n\r\n", $part, 2);
            $decoded = base64_decode($pb);
            if (stripos($ph, 'text/plain') !== false) {
                $parts['text'] = $decoded;
            } elseif (stripos($ph, 'text/html') !== false) {
                $parts['html'] = $decoded;
            }
        }
    }
    return ['raw' => $raw, 'headers' => $headers, 'envelope' => json_decode((string) file_get_contents($server['dir'] . '/message-' . $n . '.json'), true)] + $parts;
}

function mail_cfg(array $server, array $over = []): array
{
    return $over + [
        'transport' => 'smtp', 'host' => '127.0.0.1', 'port' => $server['port'], 'encryption' => 'none',
        'username' => '', 'password' => '', 'from_email' => 'assess@park.example.ir', 'from_name' => 'پارک فاوا', 'verify_peer' => 1,
    ];
}

function decode_header(string $v): string
{
    return function_exists('iconv_mime_decode') ? (string) iconv_mime_decode($v, 0, 'UTF-8') : (string) mb_decode_mimeheader($v);
}

group('E-mail');

$sqlite = test_databases()['SQLite'] ?? null;

test('Persian subjects are encoded in words of legal length', function () {
    $subject = 'دعوت به داوری طرح «طراحی و ساخت سامانه هوشمند پایش و بهینه‌سازی مصرف انرژی ساختمان‌های اداری» — پارک فاوا';
    $enc = Mailer::encodeHeader($subject);
    foreach (explode("\r\n ", $enc) as $word) {
        ok(strlen($word) <= 75, 'encoded word too long: ' . strlen($word));
        ok((bool) preg_match('/^=\?UTF-8\?B\?[A-Za-z0-9+\/=]+\?=$/', $word), $word);
        ok(mb_check_encoding(base64_decode(substr($word, 10, -2)), 'UTF-8'), 'a character was split between two words');
    }
    eq($subject, decode_header($enc));
    eq('Plain ASCII subject', Mailer::encodeHeader('Plain ASCII subject'));
    lacks("\n", Mailer::encodeHeader("line one\r\nBcc: victim@example.com"));
});

test('a message arrives complete over plain SMTP', function () use ($sqlite) {
    $db = fresh_app($sqlite);
    $server = smtp_server('plain');
    $text = "سلام\n.این خط با نقطه شروع می‌شود\n\nپیوند: https://assess.example.ir/f/abc";
    $r = Mailer::send('judge@example.org', 'دکتر رضا فنی‌پور', 'دعوت به داوری طرح «نمونه»', $text, '<p>سلام <b>داور</b></p>', ['kind' => 'invite', 'reply_to' => 'admin@park.example.ir'], mail_cfg($server));
    eq(['ok' => true, 'error' => ''], $r);
    $m = smtp_message($server);
    smtp_stop($server);
    eq('assess@park.example.ir', $m['envelope']['from']);
    eq(['judge@example.org'], $m['envelope']['to']);
    eq('دعوت به داوری طرح «نمونه»', decode_header($m['headers']['subject']));
    contains('<assess@park.example.ir>', $m['headers']['from']);
    eq('پارک فاوا <assess@park.example.ir>', decode_header($m['headers']['from']));
    eq('دکتر رضا فنی‌پور <judge@example.org>', decode_header($m['headers']['to']));
    eq('admin@park.example.ir', $m['headers']['reply-to']);
    eq('1.0', $m['headers']['mime-version']);
    ok((bool) preg_match('/^<[0-9a-f]{32}@park\.example\.ir>$/', $m['headers']['message-id']), $m['headers']['message-id']);
    ok(strtotime($m['headers']['date']) > time() - 120, 'Date header: ' . $m['headers']['date']);
    eq($text, $m['text']);
    eq('<p>سلام <b>داور</b></p>', $m['html']);
    foreach (explode("\r\n", $m['raw']) as $line) {
        ok(strlen($line) <= 998, 'line longer than SMTP allows');
    }
    lacks("\n.\r\n", $m['raw']);
    $log = $db->one('SELECT * FROM {mail_log}');
    eq('sent', $log['status']);
    eq('invite', $log['kind']);
    eq('judge@example.org', $log['to_email']);
    ok(count(Mailer::transcript()) > 6, 'the conversation is kept for the test button');
    lacks('secret', implode("\n", Mailer::transcript()));
});

test('names and subjects cannot smuggle in extra headers or recipients', function () use ($sqlite) {
    fresh_app($sqlite);
    $server = smtp_server('plain');
    $r = Mailer::send('judge@example.org', "Eve\r\nBcc: victim@example.com", "Hi\r\nBcc: victim2@example.com\r\n\r\nInjected body", 'text', '<p>html</p>', [], mail_cfg($server, ['from_name' => "Park\"\r\nX-Evil: 1"]));
    eq(true, $r['ok']);
    $m = smtp_message($server);
    eq(['judge@example.org'], $m['envelope']['to']);
    ok(!isset($m['headers']['bcc']), 'no Bcc header');
    ok(!isset($m['headers']['x-evil']), 'no injected header');
    eq('text', $m['text']);

    $r = Mailer::send("judge@example.org>\r\nRCPT TO:<victim@example.com", '', 's', 't', 'h', [], mail_cfg($server));
    eq(false, $r['ok'], 'an address with line breaks is refused before talking to the server');
    $r = Mailer::send('not-an-address', '', 's', 't', 'h', [], mail_cfg($server));
    eq(false, $r['ok']);
    contains('معتبر نیست', $r['error']);
    smtp_stop($server);
    eq(false, is_file($server['dir'] . '/message-2.eml'));
});

test('login with user name and password (AUTH)', function () use ($sqlite) {
    $db = fresh_app($sqlite);
    $server = smtp_server('plain', 'assess@park.example.ir', 'p@ss word/+=');
    $cfg = mail_cfg($server, ['username' => 'assess@park.example.ir', 'password' => 'p@ss word/+=']);
    eq(true, Mailer::send('a@example.org', '', 'موضوع', 'متن', '<p>متن</p>', [], $cfg)['ok']);
    eq(true, smtp_message($server)['envelope']['authed']);
    lacks('p@ss', implode("\n", Mailer::transcript()), 'the password never appears in the transcript');
    lacks(base64_encode('p@ss word/+='), implode("\n", Mailer::transcript()));

    $r = Mailer::send('a@example.org', '', 'موضوع', 'متن', '<p>متن</p>', ['kind' => 'test'], ['password' => 'wrong'] + $cfg);
    eq(false, $r['ok']);
    contains('نام کاربری یا رمز عبور', $r['error']);
    $r = Mailer::send('a@example.org', '', 'موضوع', 'متن', '<p>متن</p>', [], ['username' => '', 'password' => ''] + $cfg);
    eq(false, $r['ok'], 'the server insists on a login');
    smtp_stop($server);
    eq(['sent', 'failed', 'failed'], $db->col('SELECT status FROM {mail_log} ORDER BY id'));
    contains('535', (string) $db->val("SELECT error FROM {mail_log} WHERE kind = 'test'"));
});

test('SSL on port 465 style connections, with and without certificate checking', function () use ($sqlite) {
    if (!extension_loaded('openssl')) {
        skip('OpenSSL is not available');
    }
    fresh_app($sqlite);
    $server = smtp_server('ssl', 'u', 'p');
    $cfg = mail_cfg($server, ['encryption' => 'ssl', 'username' => 'u', 'password' => 'p']);
    $r = Mailer::send('a@example.org', '', 's', 't', '<p>h</p>', [], $cfg);
    eq(false, $r['ok'], 'a self-signed certificate is not trusted by default');
    contains('بررسی گواهی', $r['error'], 'the message tells the admin what to do');
    $r = Mailer::send('a@example.org', '', 's', 't', '<p>h</p>', [], ['verify_peer' => 0] + $cfg);
    eq(['ok' => true, 'error' => ''], $r);
    $m = smtp_message($server);
    smtp_stop($server);
    eq(true, $m['envelope']['tls']);
    eq(true, $m['envelope']['authed']);
});

test('STARTTLS on port 587 style connections', function () use ($sqlite) {
    if (!extension_loaded('openssl')) {
        skip('OpenSSL is not available');
    }
    fresh_app($sqlite);
    $server = smtp_server('starttls', 'u', 'p');
    $cfg = mail_cfg($server, ['encryption' => 'tls', 'username' => 'u', 'password' => 'p', 'verify_peer' => 0]);
    eq(['ok' => true, 'error' => ''], Mailer::send('a@example.org', '', 's', 't', '<p>h</p>', [], $cfg));
    $m = smtp_message($server);
    eq(true, $m['envelope']['tls'], 'the message travelled encrypted');
    eq(true, $m['envelope']['authed']);
    ok(in_array('STARTTLS', $m['envelope']['log'], true));
    $r = Mailer::send('a@example.org', '', 's', 't', '<p>h</p>', [], ['verify_peer' => 1] + $cfg);
    eq(false, $r['ok']);
    contains('TLS', $r['error']);
    smtp_stop($server);

    $plain = smtp_server('plain');
    $r = Mailer::send('a@example.org', '', 's', 't', '<p>h</p>', [], mail_cfg($plain, ['encryption' => 'tls']));
    eq(false, $r['ok']);
    contains('STARTTLS', $r['error'], 'a server without STARTTLS is reported, the message is not sent in clear text');
    smtp_stop($plain);
    eq(false, is_file($plain['dir'] . '/message-1.eml'));
});

test('server problems become readable messages', function () use ($sqlite) {
    fresh_app($sqlite);
    $server = smtp_server('plain');
    $r = Mailer::send('reject-me@example.org', '', 's', 't', '<p>h</p>', [], mail_cfg($server));
    eq(false, $r['ok']);
    contains('نپذیرفت', $r['error']);
    contains('550', $r['error']);
    smtp_stop($server);

    $r = Mailer::send('a@example.org', '', 's', 't', '<p>h</p>', [], mail_cfg(['port' => free_port()]));
    eq(false, $r['ok']);
    contains('اتصال به سرور SMTP برقرار نشد', $r['error']);

    $r = Mailer::send('a@example.org', '', 's', 't', '<p>h</p>', [], ['transport' => 'none'] + mail_cfg(['port' => 1]));
    eq(false, $r['ok']);
    contains('پیکربندی نشده', $r['error']);
    eq(false, Mailer::configured(['transport' => 'smtp', 'host' => '', 'from_email' => 'a@b.ir']));
    eq(false, Mailer::configured(['transport' => 'smtp', 'host' => 'mail.b.ir', 'from_email' => 'nonsense']));
    eq(true, Mailer::configured(['transport' => 'mail', 'from_email' => 'a@b.ir']));
});

test('the invitation contains the private link, the role and the deadline', function () use ($sqlite) {
    fresh_app($sqlite);
    Settings::set('org_name', 'پارک فاوا');
    $id = (int) upload_fixture('sample-proposal-1.docx')['id'];
    Proposals::saveDetails($id, ['reviewed_at' => now_utc(), 'due_at' => '2026-12-06 20:29:59', 'requested_amount' => 18.5]);
    list($a) = Assignments::assign($id, 'tech', ['id' => null, 'name' => 'دکتر رضا <b>فنی‌پور</b>', 'email' => 'reza@example.org', 'mobile' => null]);
    $p = Proposals::find($id);

    $msg = Notifier::inviteMessage($p, $a);
    $link = 'https://assess.example.ir/f/' . $a['token'];
    contains('دعوت به داوری طرح', $msg['subject']);
    contains('طراحی و ساخت سامانه هوشمند', $msg['subject']);
    lacks("\n", $msg['subject']);
    contains($link, $msg['text']);
    contains('داور فنی', $msg['text']);
    contains('مهلت تکمیل فرم: 15 آذر 1405', \Fava\Core\Fa::latin($msg['text']));
    lacks('{', $msg['text'], 'no unreplaced placeholder');
    contains('href="' . $link . '"', $msg['html']);
    contains('دکتر رضا &lt;b&gt;فنی‌پور&lt;/b&gt;', $msg['html'], 'names are escaped in the HTML part');
    lacks('<b>فنی‌پور</b>', $msg['html']);
    contains('dir="rtl"', $msg['html']);

    $rem = Notifier::inviteMessage($p, $a, true);
    contains('یادآوری', $rem['subject']);
    contains($link, $rem['text']);

    Proposals::saveDetails($id, ['due_at' => null]);
    $msg = Notifier::inviteMessage(Proposals::find($id), $a);
    lacks('مهلت تکمیل', $msg['text']);
    lacks("\n\n\n", $msg['text'], 'the empty deadline leaves no gap');

    $biz = Assignments::assign($id, 'biz', ['id' => null, 'name' => 'ب', 'email' => null, 'mobile' => null])[0];
    $r = Notifier::invite($p, $biz);
    eq(false, $r['ok']);
    contains('نشانی ایمیل ثبت نشده', $r['error']);
});

test('invitation, reminder and the note to the admin are really sent', function () use ($sqlite) {
    $db = fresh_app($sqlite);
    $server = smtp_server('plain');
    Settings::set('mail', mail_cfg($server));
    Settings::set('notify_email', 'boss@park.example.ir');
    Settings::flush();
    ok(Mailer::configured());
    $id = (int) upload_fixture('sample-proposal-1.docx')['id'];
    review_proposal($id, 18.5);
    list($tech) = Assignments::assign($id, 'tech', ['id' => null, 'name' => 'دکتر رضا فنی‌پور', 'email' => 'reza@example.org', 'mobile' => null]);
    list($biz) = Assignments::assign($id, 'biz', ['id' => null, 'name' => 'مریم بازارگان', 'email' => 'maryam@example.org', 'mobile' => null]);
    $p = Proposals::find($id);

    eq(true, Notifier::invite($p, $tech)['ok']);
    eq(true, Notifier::invite($p, $biz, true)['ok']);
    $m1 = smtp_message($server, 1);
    $m2 = smtp_message($server, 2);
    eq(['reza@example.org'], $m1['envelope']['to']);
    contains('/f/' . $tech['token'], $m1['text']);
    lacks($biz['token'], $m1['raw'] . $m1['text'] . $m1['html'], 'never the other judge\'s link');
    eq('boss@park.example.ir', $m1['headers']['reply-to']);
    eq(['maryam@example.org'], $m2['envelope']['to']);
    contains('یادآوری', decode_header($m2['headers']['subject']));

    submit_form($id, 'tech', [4, 5, 3, 4, 4]);
    Notifier::judgeSubmitted(Proposals::find($id), Assignments::forProposal($id)['tech'], null);
    $m3 = smtp_message($server, 3);
    eq(['boss@park.example.ir'], $m3['envelope']['to']);
    contains('داور فنی', $m3['text']);
    lacks('/f/', $m3['text'], 'the admin note carries no judge link');
    contains('/admin/proposals/' . $id, $m3['text']);

    $p = submit_form($id, 'biz', [3, 4, 3, 2, 4]);
    Notifier::judgeSubmitted($p, Assignments::forProposal($id)['biz'], \Fava\Domain\Results::of($p));
    $m4 = smtp_message($server, 4);
    contains('کامل شد', decode_header($m4['headers']['subject']));
    contains('۷۲', $m4['text']);

    Settings::set('notify_on_submit', 0);
    Settings::flush();
    Notifier::judgeSubmitted($p, Assignments::forProposal($id)['biz'], null);
    smtp_stop($server);
    eq(false, is_file($server['dir'] . '/message-5.eml'), 'switched off in the settings');
    eq(['invite', 'reminder', 'admin_notice', 'admin_notice'], $db->col('SELECT kind FROM {mail_log} ORDER BY id'));
});
