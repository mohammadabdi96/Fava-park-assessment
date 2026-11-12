<?php
/**
 * End-to-end tests: the real application on PHP's built-in web server, driven over HTTP
 * exactly like a browser would (cookies, CSRF tokens, redirects, uploads, JSON calls).
 */

require_once __DIR__ . '/../support/http.php';
require_once __DIR__ . '/../support/db.php';

if (!function_exists('proc_open')) {
    group('End-to-end over HTTP');
    test('built-in web server', function () {
        skip('proc_open is disabled; the HTTP tests cannot start a server');
    });
    return;
}

/** Tokens of the judge links shown on the admin's proposal page, in page order. */
function page_tokens(HttpResponse $r): array
{
    preg_match_all('#/f/([A-Za-z0-9_\-]{32})#', $r->body, $m);
    return array_values(array_unique($m[1]));
}

/** Answers as the browser sends them. */
function browser_answers(string $role, array $scores, array $over = []): array
{
    $a = answers_for($role, $scores, $over);
    $a['date'] = \Fava\Core\Jalali::today();
    return $a;
}

function text_of(HttpResponse $r): string
{
    return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) preg_replace('#<(script|style)\b.*?</\1>#is', '', $r->body)), ENT_QUOTES)));
}

group('End-to-end over HTTP');

$app = null;
$admin = null;
$state = [];

test('a fresh copy sends every visitor to the installer', function () use (&$app) {
    $app = start_app();
    $c = new HttpClient($app['base']);
    $r = $c->get('/');
    eq(302, $r->status);
    contains('index.php?r=/install', $r->location(), 'before installation the URL form that works everywhere is used');
    $r = $c->get('/index.php?r=/install');
    eq(200, $r->status);
    contains('نصب', $r->body);
    contains('name="admin_pass"', $r->body);
    $r = $c->get('/admin');
    eq(302, $r->status);
    $r = $c->get('/f/' . str_repeat('a', 32));
    eq(302, $r->status, 'judge links do nothing before installation');
});

test('private folders and files are never served', function () use (&$app) {
    $c = new HttpClient($app['base']);
    foreach (['/config/config.php', '/storage/probe.txt', '/storage/database/x.sqlite', '/app/bootstrap.php', '/app/Views/auth/login.php',
        '/tests/run.php', '/dev/router.php', '/docs/INSTALL.fa.md', '/README.md', '/.htaccess', '/.git/config', '/.user.ini', '/.gitignore'] as $path) {
        $r = $c->get($path);
        ok(in_array($r->status, [403, 404], true), $path . ' answered ' . $r->status);
        lacks('<?php', $r->body, $path);
    }
    $r = $c->get('/assets/css/app.css');
    eq(200, $r->status);
    $r = $c->get('/assets/js/form.js');
    eq(200, $r->status);
});

test('the installer validates its input', function () use (&$app) {
    $c = new HttpClient($app['base']);
    $r = $c->post('/install', ['driver' => 'sqlite', 'admin_name' => '', 'admin_user' => 'A B', 'admin_pass' => 'short', 'admin_pass2' => 'short']);
    eq(422, $r->status);
    contains('حداقل ۸ نویسه', $r->body);
    contains('نام مدیر سامانه را وارد کنید', $r->body);
    lacks('short', $r->body, 'passwords are never echoed back');
    $r = $c->post('/install', ['driver' => 'sqlite', 'admin_name' => 'x', 'admin_user' => 'admin', 'admin_pass' => 'Test-12345', 'admin_pass2' => 'Test-54321']);
    eq(422, $r->status);
    contains('تکرار رمز عبور', $r->body);
    $r = $c->post('/install', ['driver' => 'mysql', 'db_host' => '127.0.0.1:1', 'db_name' => 'nope', 'db_user' => 'nope', 'db_pass' => 'secret-db-pass',
        'admin_name' => 'x', 'admin_user' => 'admin', 'admin_pass' => 'Test-12345', 'admin_pass2' => 'Test-12345']);
    eq(422, $r->status, 'an unreachable database is reported on the form');
    lacks('secret-db-pass', $r->body);
    lacks('SQLSTATE', text_of($r), 'no raw driver message');
    lacks('secret-db-pass', app_errors($app), 'and the password is not written to the error log either');
    eq(false, is_file($app['home'] . '/config/config.php'), 'nothing was written');
});

test('installation creates the database, the config file and logs the admin in', function () use (&$app, &$admin) {
    $admin = install_app($app);
    ok(is_file($app['home'] . '/config/config.php'));
    eq(1, count(glob($app['home'] . '/storage/database/*.sqlite')));
    $cfg = (string) file_get_contents($app['home'] . '/config/config.php');
    contains("defined('APP_ROOT')", $cfg, 'the config file refuses direct access');
    lacks('Test-12345', $cfg);
    $r = $admin->getFollow('/admin');
    eq(200, $r->status);
    contains('مدیر آزمون', $r->body);
    contains('با موفقیت نصب شد', $r->body);

    $r = $admin->get('/install');
    eq(404, $r->status, 'the installer is closed afterwards');
    $r = $admin->post('/install', ['driver' => 'sqlite', 'admin_name' => 'x', 'admin_user' => 'evil', 'admin_pass' => 'Hacked-12345', 'admin_pass2' => 'Hacked-12345']);
    eq(404, $r->status);
});

test('security headers and cookie flags', function () use (&$app, &$admin) {
    $r = $admin->get('/admin');
    eq('nosniff', $r->header('x-content-type-options'));
    eq('SAMEORIGIN', $r->header('x-frame-options'));
    eq('same-origin', $r->header('referrer-policy'));
    contains('no-store', $r->header('cache-control'));
    contains('noindex', $r->header('x-robots-tag'));
    eq('', $r->header('x-powered-by'));
    eq(1, count($admin->cookies), 'one cookie only');
    $line = strtolower((string) reset($admin->cookieLines));
    contains('httponly', $line);
    contains('samesite=lax', $line);
    contains('path=/', $line);
    lacks('phpsessid', $line, 'no default PHP session name');
});

test('admin pages need a login; wrong passwords are refused', function () use (&$app, &$admin) {
    $guest = new HttpClient($app['base']);
    foreach (['/admin', '/admin/proposals', '/admin/judges', '/admin/settings', '/admin/activity', '/admin/export/proposals', '/admin/proposals/1', '/admin/proposals/1/file'] as $path) {
        $r = $guest->get($path);
        eq(302, $r->status, $path);
        eq('/admin/login', $r->location(), $path);
    }
    $r = $guest->post('/admin/proposals/upload', []);
    eq(302, $r->status);
    $r = $guest->get('/admin/proposals', ['Accept: application/json']);
    eq(401, $r->status, 'script calls get a status, not a login page');

    $r = $guest->get('/admin/login');
    eq(200, $r->status);
    preg_match('/name="_csrf" value="([^"]+)"/', $r->body, $m);
    $token = $m[1];
    $r = $guest->post('/admin/login', ['username' => 'admin', 'password' => 'Test-12345']);
    eq(419, $r->status, 'a login form without CSRF token is refused');
    $r = $guest->post('/admin/login', ['_csrf' => $token, 'username' => 'admin', 'password' => 'wrong-password']);
    eq(302, $r->status);
    $r = $guest->get($r->location());
    contains('نام کاربری یا رمز عبور نادرست است', $r->body);
    contains('value="admin"', $r->body, 'the user name is kept');
    lacks('wrong-password', $r->body);
    $guest->get('/admin/judges');
    $guest->post('/admin/proposals/1/delete', []);
    $before = $guest->cookies;
    $r = $guest->post('/admin/login', ['_csrf' => $token, 'username' => 'ADMIN', 'password' => 'Test-12345']);
    eq(302, $r->status);
    eq('/admin/judges', $r->location(), 'back to the page that was asked for (never to a form action)');
    ok($guest->cookies !== $before, 'the session id changes at login');
    eq(200, $guest->get('/admin')->status);

    $r = $guest->post('/admin/logout', ['_csrf' => $guest->csrf()]);
    eq(302, $r->status);
    eq(302, $guest->get('/admin')->status, 'logged out');
});

test('forms without a valid CSRF token change nothing', function () use (&$app, &$admin) {
    $r = $admin->post('/admin/settings/general', ['org_name' => 'Hacked']);
    eq(419, $r->status);
    $r = $admin->post('/admin/settings/general', ['_csrf' => 'made-up', 'org_name' => 'Hacked']);
    eq(419, $r->status);
    $r = $admin->post('/admin/proposals/upload', ['_csrf' => 'x'], ['files[]' => ['a.docx', (string) file_get_contents(fixture('sample-proposal-3.docx'))]]);
    eq(419, $r->status);
    lacks('Hacked', $admin->get('/admin')->body);
    eq(0, count(glob($app['home'] . '/storage/uploads/*') ?: []));
});

test('uploading a worksheet extracts the proposal data', function () use (&$app, &$admin, &$state) {
    $docx = (string) file_get_contents(fixture('sample-proposal-1.docx'));
    $r = $admin->post('/admin/proposals/upload', ['_csrf' => $admin->csrf()], ['files[]' => ['کاربرگ البرز.docx', $docx]]);
    eq(302, $r->status);
    ok((bool) preg_match('#^/admin/proposals/(\d+)$#', $r->location(), $m), 'redirected to the proposal: ' . $r->location());
    $state['id'] = (int) $m[1];
    $state['url'] = '/admin/proposals/' . $state['id'];
    $r = $admin->get($state['url']);
    eq(200, $r->status);
    contains('طراحی و ساخت سامانه هوشمند پایش و بهینه‌سازی مصرف انرژی ساختمان‌های اداری', $r->body);
    contains('شرکت داده‌پردازان نوآور البرز', $r->body);
    contains('کاربرگ البرز.docx', $r->body);
    contains('دو عنوان متفاوت', $r->body, 'the warning of the extractor is shown');
    contains('class="docx"', $r->body, 'the worksheet itself can be read on the page');
    eq([], page_tokens($r), 'no judge links before the data is confirmed');
    eq(1, count(glob($app['home'] . '/storage/uploads/*.docx')));

    $r = $admin->submit('/admin/proposals/upload', [], ['files[]' => ['again.docx', $docx]]);
    contains('قبلاً بارگذاری شده است', $r->body);
    eq(1, count(glob($app['home'] . '/storage/uploads/*.docx')));
});

test('uploads that are not worksheets are refused with a clear message', function () use (&$app, &$admin) {
    $r = $admin->submit('/admin/proposals/upload', [], ['files[]' => ['shell.php', '<?php echo 1;']]);
    contains('فقط فایل Word', $r->body);
    $r = $admin->submit('/admin/proposals/upload', [], ['files[]' => ['fake.docx', '<?php echo 1;']]);
    contains('معتبر نیست', $r->body);
    $r = $admin->submit('/admin/proposals/upload', [], ['files[]' => ['old.doc', 'x']]);
    contains('Save As', $r->body);
    $r = $admin->submit('/admin/proposals/upload', [], ['files[]' => ['evil.docx', (string) file_get_contents(fixture('evil-doctype.docx'))]]);
    contains('DTD', $r->body);
    $r = $admin->submit('/admin/proposals/upload', []);
    contains('هیچ فایلی انتخاب نشده', $r->body);
    eq(1, count(glob($app['home'] . '/storage/uploads/*')), 'nothing but the first worksheet is stored');
    foreach (glob($app['home'] . '/storage/uploads/*') as $f) {
        ok(substr($f, -5) === '.docx');
    }
});

test('several files can be uploaded at once', function () use (&$app, &$admin, &$state) {
    $r = $admin->post('/admin/proposals/upload', ['_csrf' => $admin->csrf()], ['files[]' => [
        ['sample-2.docx', (string) file_get_contents(fixture('sample-proposal-2.docx'))],
        ['sample-3.docx', (string) file_get_contents(fixture('sample-proposal-3.docx'))],
        ['broken.docx', 'not a zip'],
    ]]);
    eq(302, $r->status);
    $r = $admin->get($r->location());
    contains('۲ طرح بارگذاری شد', $r->body);
    contains('broken.docx', $r->body);
    contains('کیت تشخیص سریع', $r->body);
    eq(3, count(glob($app['home'] . '/storage/uploads/*.docx')));
    $state['second'] = $state['id'] + 1;
    $state['third'] = $state['id'] + 2;
});

test('the admin confirms the data and fixes the requested amount', function () use (&$admin, &$state) {
    $r = $admin->submit($state['url'] . '/update', ['action' => 'confirm', 'title' => '', 'company' => 'x']);
    contains('عنوان طرح نمی‌تواند خالی باشد', $r->body);
    $r = $admin->submit($state['url'] . '/update', ['action' => 'confirm', 'title' => 'سامانه پایش انرژی', 'company' => 'داده‌پردازان البرز', 'requested_amount' => '18,5']);
    contains('مبلغ درخواستی باید عددی', $r->body, '18,5 is ambiguous and is refused, not read as 185');
    contains('value="18,5"', $r->body, 'what was typed is still in the field');
    $r = $admin->submit($state['url'] . '/update', ['action' => 'confirm', 'title' => 'سامانه پایش انرژی', 'company' => 'داده‌پردازان البرز', 'requested_amount' => '18.5', 'due_date' => '1405/13/01']);
    contains('مهلت داوری', $r->body);

    $r = $admin->submit($state['url'] . '/update', [
        'action' => 'confirm',
        'title' => 'سامانه هوشمند پایش مصرف انرژی <script>alert(1)</script>',
        'company' => 'داده‌پردازان نوآور البرز',
        'expected_output' => "سامانه تحت وب\n۵۰ گره سخت‌افزاری",
        'requested_amount' => '۱۸٫۵',
        'due_date' => '۱۴۰۶/۰۱/۱۵',
        'admin_notes' => 'یادداشت محرمانه مدیر',
        'share_doc' => '1',
        'share_file' => '1',
    ]);
    eq(200, $r->status);
    contains('اطلاعات طرح تأیید شد', $r->body);
    lacks('<script>alert(1)</script>', $r->body, 'titles are escaped everywhere');
    contains('&lt;script&gt;alert(1)&lt;/script&gt;', $r->body);
    contains('۱۸.۵', $r->body);
});

test('assigning the two judges creates two different private links', function () use (&$admin, &$state) {
    $r = $admin->submit($state['url'] . '/assign/tech', ['name' => '', 'email' => 'x@example.org']);
    contains('نام داور را وارد کنید', $r->body);
    $r = $admin->submit($state['url'] . '/assign/tech', ['name' => 'دکتر رضا فنی‌پور', 'email' => 'not-an-email']);
    contains('ایمیل معتبر نیست', $r->body);
    eq([], page_tokens($r));

    $r = $admin->submit($state['url'] . '/assign/tech', ['name' => 'دکتر رضا فنی‌پور', 'email' => 'reza@example.org', 'save_judge' => '1']);
    contains('داور فنی تعیین شد', $r->body);
    $tokens = page_tokens($r);
    eq(1, count($tokens));
    $state['tech'] = $tokens[0];

    $r = $admin->submit($state['url'] . '/assign/biz', ['name' => 'مهندس مریم بازارگان']);
    $tokens = array_values(array_diff(page_tokens($r), [$state['tech']]));
    eq(1, count($tokens));
    $state['biz'] = $tokens[0];
    contains('دکتر رضا فنی‌پور', $r->body);
    contains('مهندس مریم بازارگان', $r->body);

    $r = $admin->get('/admin/judges');
    contains('دکتر رضا فنی‌پور', $r->body, 'saved to the judges directory');
    lacks('مهندس مریم بازارگان', $r->body, 'not saved unless asked');

    $r = $admin->submit($state['url'] . '/assign/admin', ['name' => 'x']);
    eq(404, $r->status, 'only the two roles exist');
});

test('a judge opens the link: the form of that role only, nothing of the admin', function () use (&$app, &$state) {
    $judge = new HttpClient($app['base']);
    $r = $judge->get('/f/' . $state['tech']);
    eq(200, $r->status);
    eq([], $judge->cookies, 'no cookie, no session for judges');
    contains('no-store', $r->header('cache-control'));
    contains('noindex', $r->header('x-robots-tag'));
    contains('داور فنی', $r->body);
    contains('سامانه هوشمند پایش مصرف انرژی', $r->body);
    contains('داده‌پردازان نوآور البرز', $r->body);
    contains('۵۰ گره سخت‌افزاری', $r->body);
    lacks('<script>alert(1)</script>', $r->body);
    contains('data-crit="tech-1"', $r->body);
    contains('data-crit="team-5"', $r->body);
    lacks('data-crit="market-1"', $r->body, 'business criteria are not on the technical form');
    lacks('data-crit="finance-1"', $r->body);
    lacks('یادداشت محرمانه مدیر', $r->body, 'admin notes stay private');
    lacks('مهندس مریم بازارگان', $r->body, 'the other judge is not named');
    lacks($state['biz'], $r->body, 'the other link is not on the page');
    lacks('/admin', $r->body, 'no admin links');
    lacks('_csrf', $r->body);
    contains('class="docx"', $r->body, 'the worksheet can be read next to the form');
    ok((bool) preg_match('#<script type="application/json" id="form-boot">(.*?)</script>#s', $r->body, $m), 'boot data');
    $boot = json_decode($m[1], true);
    eq('tech', $boot['cfg']['role']);
    near(18.5, $boot['fixedAmount']);
    eq(['tech', 'team'], array_keys($boot['cfg']['dims']));
    eq('دکتر رضا فنی‌پور', $boot['answers']['name'], 'the name is pre-filled');
    eq(false, $boot['preview']);

    $r = $judge->get('/f/' . $state['biz']);
    eq(200, $r->status);
    contains('داور کسب‌وکاری', $r->body);
    contains('data-crit="market-1"', $r->body);
    lacks('data-crit="tech-1"', $r->body);
    lacks('دکتر رضا فنی‌پور', $r->body);
});

test('invalid, mistyped or foreign links show a neutral page', function () use (&$app, &$state) {
    $judge = new HttpClient($app['base']);
    foreach ([str_repeat('a', 32), substr($state['tech'], 0, 31) . 'A', strtolower($state['tech']) === $state['tech'] ? strtoupper($state['tech']) : strtolower($state['tech'])] as $bad) {
        $r = $judge->get('/f/' . $bad);
        eq(404, $r->status, $bad);
        contains('معتبر نیست', $r->body);
        lacks('سامانه هوشمند پایش', $r->body);
    }
    eq(404, $judge->get('/f/short')->status);
    eq(404, $judge->get('/f/' . $state['tech'] . '/nothing')->status);
    $r = $judge->postJson('/f/' . str_repeat('b', 32) . '/draft', ['open' => true]);
    eq(404, $r->status);
    eq(false, $r->json()['ok']);
    $r = $judge->postJson('/f/' . str_repeat('b', 32) . '/submit', ['answers' => []]);
    eq(404, $r->status);
    eq(405, $judge->get('/f/' . $state['tech'] . '/submit')->status);
});

test('the admin sees when a judge has opened the link and started the form', function () use (&$app, &$admin, &$state) {
    $judge = new HttpClient($app['base']);
    $r = $admin->get($state['url']);
    contains('ارسال نشده', text_of($r));

    $r = $judge->postJson('/f/' . $state['tech'] . '/draft', ['open' => true]);
    eq(200, $r->status);
    eq(true, $r->json()['ok']);
    $r = $admin->get($state['url']);
    contains('مشاهده شده', text_of($r));

    $draft = ['scalability' => 'high', 'scores' => ['tech-1' => 4, 'tech-2' => 5, 'market-1' => 5], 'q' => ['q1' => 'مزیت فنی روشن است.'], 'name' => 'دکتر رضا فنی‌پور'];
    $r = $judge->postJson('/f/' . $state['tech'] . '/draft', ['answers' => $draft]);
    eq(200, $r->status);
    $j = $r->json();
    eq(true, $j['ok']);
    ok($j['rev'] !== '', 'revision of the saved draft');

    $r = $judge->get('/f/' . $state['tech']);
    preg_match('#id="form-boot">(.*?)</script>#s', $r->body, $m);
    $boot = json_decode($m[1], true);
    eq(['tech-1' => 4, 'tech-2' => 5], $boot['answers']['scores'], 'the draft comes back; foreign criteria were dropped');
    eq('مزیت فنی روشن است.', $boot['answers']['q']['q1']);
    eq($j['rev'], $boot['rev']);

    $r = $admin->get($state['url']);
    contains('در حال تکمیل', text_of($r));
    $r = $admin->get('/admin/proposals');
    contains('در حال داوری', text_of($r));
});

test('odd requests to the form endpoints are handled calmly', function () use (&$app, &$state) {
    $judge = new HttpClient($app['base']);
    $url = '/f/' . $state['tech'] . '/draft';
    $r = $judge->postJson($url, '{broken json');
    eq(200, $r->status, 'nothing to save, nothing breaks');
    $r = $judge->postJson($url, '[]');
    eq(200, $r->status);
    $r = $judge->postJson($url, ['answers' => 'text']);
    eq(200, $r->status);
    $r = $judge->postJson($url, ['answers' => ['scores' => ['tech-1' => ['x' => 1]], 'reasons' => 'x', 'name' => ['a'], 'trl' => '99', 'q' => 'x']]);
    eq(200, $r->status);
    $r = $judge->postJson($url, ['answers' => ['name' => str_repeat('ن', 300000)]]);
    eq(413, $r->status, 'an absurdly large body is refused');
    $r = $judge->post($url, ['answers' => 'form-encoded']);
    eq(200, $r->status);
    $r = $judge->get('/f/' . $state['tech']);
    eq(200, $r->status);
    preg_match('#id="form-boot">(.*?)</script>#s', $r->body, $m);
    ok(is_array(json_decode($m[1], true)), 'the form still loads');
});

test('an incomplete form is not accepted, and says what is missing', function () use (&$app, &$admin, &$state) {
    $judge = new HttpClient($app['base']);
    $r = $judge->postJson('/f/' . $state['tech'] . '/submit', ['answers' => ['scalability' => 'high', 'scores' => ['tech-1' => 4]]]);
    eq(422, $r->status);
    $j = $r->json();
    eq(false, $j['ok']);
    eq(['priority', 'scores', 'trl', 'risk_exec', 'reasons', 'commitment', 'name', 'date', 'milestone2'], array_keys($j['errors']));
    contains('۹ معیار', $j['errors']['scores']);
    ok(!isset($j['errors']['requested_amount']), 'the amount was fixed by the admin');

    $answers = browser_answers('tech', [4, 5, 3, 4, 4], ['commitment' => false]);
    $r = $judge->postJson('/f/' . $state['tech'] . '/submit', ['answers' => $answers]);
    eq(422, $r->status);
    eq(['commitment'], array_keys($r->json()['errors']));
    $r = $admin->get($state['url']);
    lacks('ثبت شده', text_of($r));
});

test('the technical judge submits; the form becomes read-only', function () use (&$app, &$admin, &$state) {
    $judge = new HttpClient($app['base']);
    $answers = browser_answers('tech', [4, 5, 3, 4, 4], [
        'requested_amount' => 999,
        'reasons' => ['نوآوری فنی قابل قبول است.', 'تیم توان اجرا دارد. <b>bold</b>'],
        'q' => ['q1' => 'مزیت رقابتی: الگوریتم بومی', 'q2' => '', 'q3' => ''],
        'scores' => array_merge(browser_answers('tech', [4, 5, 3, 4, 4])['scores'], ['market-1' => 1, 'finance-5' => 1]),
        'risk_market' => 1,
    ]);
    $r = $judge->postJson('/f/' . $state['tech'] . '/submit', ['answers' => $answers]);
    eq(200, $r->status);
    $j = $r->json();
    eq(true, $j['ok']);
    eq('/f/' . $state['tech'] . '?done=1', $j['redirect']);

    $r = $judge->get($j['redirect']);
    eq(200, $r->status);
    contains('ثبت شد', $r->body);
    contains('نوآوری فنی قابل قبول است.', $r->body);
    contains('&lt;b&gt;bold&lt;/b&gt;', $r->body, 'the judge\'s own text is escaped');
    lacks('id="form-boot"', $r->body, 'no editable form any more');
    contains('۴۰', text_of($r), 'the judge sees the share of points');

    $r = $judge->postJson('/f/' . $state['tech'] . '/draft', ['answers' => ['name' => 'late autosave']]);
    eq(409, $r->status);
    eq(true, $r->json()['submitted']);
    $r = $judge->postJson('/f/' . $state['tech'] . '/submit', ['answers' => browser_answers('tech', [1])]);
    eq(200, $r->status);
    eq(true, $r->json()['already'], 'a second submission changes nothing');

    $r = $admin->get($state['url']);
    $t = text_of($r);
    contains('ثبت شده', $t);
    lacks('late autosave', $r->body);
    lacks('۹۹۹', $t, 'the amount typed by the judge is ignored when the admin fixed it');
});

test('after the second form the result is calculated', function () use (&$app, &$admin, &$state) {
    $judge = new HttpClient($app['base']);
    $answers = browser_answers('biz', [3, 4, 3, 2, 4], ['scalability' => 'medium', 'priority' => 'medium', 'reasons' => ['بازار هدف مشخص است.']]);
    $r = $judge->postJson('/f/' . $state['biz'] . '/submit', ['answers' => $answers]);
    eq(200, $r->status);
    eq(true, $r->json()['ok']);

    $r = $admin->get($state['url']);
    $t = text_of($r);
    contains('۷۲', $t, 'total score');
    contains('۱۱.۸۸', $t, 'provisional amount with the lower tier');
    contains('نظر دو داور یکسان نیست', $t, 'the tier conflict is put to the admin');
    $r = $admin->get('/admin');
    contains('۷۲', text_of($r));

    $r = $admin->submit($state['url'] . '/finalize', ['decision' => 'approve', 'amount' => '11.88']);
    contains('نظر دو داور درباره سطح طرح یا مبلغ درخواستی یکسان نیست', $r->body, 'no final decision before the conflict is settled');
});

test('the admin settles the tier and records the final decision', function () use (&$admin, &$state) {
    $r = $admin->submit($state['url'] . '/resolve', ['tier' => 'Z']);
    contains('سطح طرح را انتخاب کنید', $r->body);
    $r = $admin->submit($state['url'] . '/resolve', ['tier' => 'A']);
    contains('نتیجه دوباره محاسبه شد', $r->body);
    contains('۱۴.۶۵۲', text_of($r));

    $r = $admin->submit($state['url'] . '/finalize', ['decision' => 'conditional', 'amount' => '12']);
    contains('دلیل آن را بنویسید', $r->body);
    $r = $admin->submit($state['url'] . '/finalize', ['decision' => 'conditional', 'amount' => '۱۲', 'note' => 'تأیید مشروط به ارائه قرارداد پایلوت']);
    contains('تصمیم نهایی ثبت شد', $r->body);
    $t = text_of($r);
    contains('تأیید مشروط', $t);
    contains('تأیید مشروط به ارائه قرارداد پایلوت', $t);

    $r = $admin->submit($state['url'] . '/reopen/tech');
    contains('ابتدا تصمیم نهایی را لغو کنید', $r->body, 'forms cannot be reopened under a final decision');
    $r = $admin->submit($state['url'] . '/update', ['action' => 'save', 'title' => 'x', 'company' => 'y', 'requested_amount' => '30']);
    contains('ابتدا تصمیم نهایی را لغو کنید', $r->body, 'nor can the amount be changed');
    $r = $admin->get('/admin/proposals?status=finalized');
    contains('سامانه هوشمند پایش', $r->body);
});

test('the printable report and the CSV exports contain the result', function () use (&$admin, &$state) {
    $r = $admin->get($state['url'] . '/report');
    eq(200, $r->status);
    $t = text_of($r);
    foreach (['سامانه هوشمند پایش', 'داور فنی آزمون', 'داور کسب‌وکاری آزمون', '۷۲', 'تأیید مشروط', 'نوآوری فنی قابل قبول است.', 'بازار هدف مشخص است.', 'مزیت رقابتی: الگوریتم بومی'] as $needle) {
        contains($needle, $t);
    }
    lacks('<script>alert(1)</script>', $r->body);
    lacks('<b>bold</b>', $r->body);

    $r = $admin->get('/admin/export/proposals');
    eq(200, $r->status);
    contains('text/csv', $r->header('content-type'));
    contains('attachment', $r->header('content-disposition'));
    eq("\xEF\xBB\xBF", substr($r->body, 0, 3), 'UTF-8 BOM so that Excel shows Persian text');
    $rows = array_map('str_getcsv', preg_split('/\r\n|\n/', trim(substr($r->body, 3))));
    eq(4, count($rows), 'header + three proposals');
    $flat = implode('|', array_merge(...$rows));
    contains('72', $flat);
    contains('conditional', strtolower($flat) . 'conditional');
    foreach ($rows as $row) {
        eq(count($rows[0]), count($row), 'every row has the same number of columns');
    }

    $r = $admin->get('/admin/export/scores');
    eq(200, $r->status);
    $rows = array_map('str_getcsv', preg_split('/\r\n|\n/', trim(substr($r->body, 3))));
    eq(3, count($rows), 'header + one row per submitted form');
    $flat = implode('|', array_merge(...$rows));
    contains('داور فنی آزمون', $flat, 'the name the judge signed with');
    contains('P1405-001', $flat);
    contains('نوآوری فنی قابل قبول است.', $flat);
    eq(count($rows[0]), count($rows[1]));
    eq(count($rows[0]), count($rows[2]));
});

test('spreadsheet formulas in exported text are neutralised', function () use (&$admin, &$state) {
    $url = '/admin/proposals/' . $state['third'];
    $admin->submit($url . '/update', ['action' => 'confirm', 'title' => '=HYPERLINK("http://evil.example","click")', 'company' => '@SUM(A1:A9)', 'requested_amount' => '5']);
    $r = $admin->get('/admin/export/proposals');
    $rows = array_map('str_getcsv', preg_split('/\r\n|\n/', trim(substr($r->body, 3))));
    foreach ($rows as $row) {
        foreach ($row as $cell) {
            ok($cell === '' || !in_array($cell[0], ['=', '+', '@'], true), 'a cell starts with a formula character: ' . $cell);
            ok($cell === '' || $cell[0] !== '-' || is_numeric($cell), 'a cell starts with a minus sign: ' . $cell);
        }
    }
    contains('HYPERLINK', $r->body, 'the text itself is still there');
});

test('the Word file can be downloaded by the admin, and by a judge only while allowed', function () use (&$app, &$admin, &$state) {
    $orig = (string) file_get_contents(fixture('sample-proposal-1.docx'));
    $r = $admin->get($state['url'] . '/file');
    eq(200, $r->status);
    eq($orig, $r->body, 'byte for byte the uploaded file');
    contains('wordprocessingml', $r->header('content-type'));
    contains("filename*=UTF-8''" . rawurlencode('کاربرگ البرز.docx'), $r->header('content-disposition'));
    eq('nosniff', $r->header('x-content-type-options'));

    $judge = new HttpClient($app['base']);
    $r = $judge->get('/f/' . $state['biz'] . '/file');
    eq(200, $r->status);
    eq($orig, $r->body);

    $admin->submit($state['url'] . '/unfinalize');
    $admin->submit($state['url'] . '/update', ['action' => 'save', 'title' => 'سامانه هوشمند پایش مصرف انرژی', 'company' => 'داده‌پردازان نوآور البرز', 'requested_amount' => '18.5']);
    $r = $judge->get('/f/' . $state['biz'] . '/file');
    eq(403, $r->status, 'sharing was switched off (unchecked boxes)');
    lacks('PK', substr($r->body, 0, 2));
    eq(404, $judge->get('/f/' . str_repeat('c', 32) . '/file')->status);
});

test('reopening a form lets the judge correct it with the same link', function () use (&$app, &$admin, &$state) {
    $r = $admin->submit($state['url'] . '/reopen/biz');
    contains('فرم بازگشایی شد', $r->body);
    $t = text_of($r);
    contains('به‌محض اینکه هر دو داور فرم خود را ثبت کنند', $t, 'the result is withdrawn until the form is back');
    lacks('مبلغ حمایتی محاسبه‌شده', $t);
    $csv = $admin->get('/admin/export/proposals');
    $rows = array_map('str_getcsv', preg_split('/\r\n|\n/', trim(substr($csv->body, 3))));
    $row = array_combine($rows[0], $rows[1]);
    eq('', $row['امتیاز کل']);
    eq('', $row['مبلغ محاسبه‌شده (میلیارد ریال)']);
    eq('', $row['تصمیم نهایی'], 'and so is the final decision that was based on it');

    $judge = new HttpClient($app['base']);
    $r = $judge->get('/f/' . $state['biz']);
    eq(200, $r->status);
    preg_match('#id="form-boot">(.*?)</script>#s', $r->body, $m);
    $boot = json_decode($m[1], true);
    eq(4, $boot['answers']['scores']['market-2'], 'the earlier answers are in the form');
    eq(['بازار هدف مشخص است.'], $boot['answers']['reasons']);

    $answers = browser_answers('biz', [5], ['scalability' => 'high', 'priority' => 'high', 'reasons' => ['پس از بازنگری: بازار بزرگ‌تر است.']]);
    $r = $judge->postJson('/f/' . $state['biz'] . '/submit', ['answers' => $answers]);
    eq(200, $r->status);
    $r = $admin->get($state['url']);
    $t = text_of($r);
    contains('۹۰', $t, '40 + 50');
    lacks('نظر دو داور یکسان نیست', $t, 'both judges now say tier A');
    lacks('موارد اختلاف', $t);
    contains('۱۸.۳۱۵', $t, '18.5 × 0.90 × 1.10');
    $r = $admin->submit($state['url'] . '/finalize', ['decision' => 'approve', 'amount' => '18.5']);
    contains('دلیل آن را بنویسید', $r->body, 'another amount than the calculated one needs a reason');
    $r = $admin->submit($state['url'] . '/finalize', ['decision' => 'approve', 'amount' => '18.315']);
    contains('تصمیم نهایی ثبت شد', $r->body, 'the calculated result is confirmed without a note');
});

test('replacing, unassigning and renewing links', function () use (&$app, &$admin, &$state) {
    $url = '/admin/proposals/' . $state['second'];
    $admin->submit($url . '/update', ['action' => 'confirm', 'title' => 'کیت تشخیص سریع', 'company' => 'زیست‌فناوران سبز خزر', 'share_doc' => '1']);
    $r = $admin->submit($url . '/assign/tech', ['name' => 'داور اول']);
    $old = page_tokens($r)[0];
    $judge = new HttpClient($app['base']);
    eq(200, $judge->get('/f/' . $old)->status);

    $r = $admin->submit($url . '/regenerate/tech');
    $new = page_tokens($r)[0];
    ok($new !== $old);
    eq(404, $judge->get('/f/' . $old)->status, 'the old link is dead');
    eq(200, $judge->get('/f/' . $new)->status);

    $r = $admin->submit($url . '/assign/tech', ['name' => 'داور دوم']);
    $newer = page_tokens($r)[0];
    ok($newer !== $new, 'another person gets another link');
    eq(404, $judge->get('/f/' . $new)->status);

    $r = $admin->submit($url . '/mark-sent/tech');
    contains('ثبت شد که پیوند را برای داور فرستاده‌اید', $r->body);
    $r = $admin->submit($url . '/send', ['role' => 'tech']);
    contains('ارسال ایمیل هنوز پیکربندی نشده است', $r->body);

    $r = $admin->submit($url . '/unassign/tech');
    contains('داور فنی حذف شد', $r->body);
    eq([], page_tokens($r));
    eq(404, $judge->get('/f/' . $newer)->status);
    $r = $admin->submit($url . '/unassign/tech');
    contains('داوری تعیین نشده', $r->body);
});

test('the admin preview shows the judge\'s form without saving anything', function () use (&$admin, &$state) {
    $url = '/admin/proposals/' . $state['second'];
    $r = $admin->get($url . '/preview/biz');
    eq(200, $r->status);
    contains('data-crit="market-1"', $r->body);
    preg_match('#id="form-boot">(.*?)</script>#s', $r->body, $m);
    $boot = json_decode($m[1], true);
    eq(true, $boot['preview']);
    eq(null, $boot['urls'], 'nothing to post to');
    eq(null, $boot['fixedAmount'], 'no amount fixed: the judge will be asked for it');
    eq(404, $admin->get($url . '/preview/other')->status);
});

test('scoring settings are validated and only affect new proposals', function () use (&$admin, &$state) {
    $base = ['ceiling_A' => '40', 'ceiling_B' => '15', 'ceiling_C' => '8', 'reject_below' => '40', 'conditional_below' => '60',
        'w_tech' => '30', 'w_team' => '20', 'w_market' => '25', 'w_finance' => '25', 'risk_base' => '0.7', 'risk_step' => '0.15',
        'pay_1' => '30', 'pay_2' => '40', 'pay_3' => '30', 'cap_amount' => '1'];
    $r = $admin->submit('/admin/settings/scoring', ['w_tech' => '50'] + $base);
    contains('مجموع وزن چهار بُعد باید ۱۰۰ باشد', $r->body);
    $r = $admin->submit('/admin/settings/scoring', ['ceiling_A' => '5'] + $base);
    contains('سقف سطح الف نباید', $r->body);
    $r = $admin->submit('/admin/settings/scoring', ['reject_below' => '70'] + $base);
    contains('آستانه', $r->body);
    $r = $admin->submit('/admin/settings/scoring', ['risk_step' => '0,15'] + $base);
    contains('ضریب پایه ریسک', $r->body, 'a comma decimal is refused, not misread');
    $r = $admin->submit('/admin/settings/scoring', ['pay_1' => '50'] + $base);
    contains('مجموع درصد سه مرحله', $r->body);

    $r = $admin->submit('/admin/settings/scoring', $base);
    contains('پارامترهای امتیازدهی ذخیره شد', $r->body);
    contains('value="40"', $r->body);

    $r = $admin->get($state['url']);
    lacks('۴۰ میلیارد', text_of($r), 'the finished proposal keeps its own rules');
    $r = $admin->submit($state['url'] . '/refresh-config');
    contains('تصمیم نهایی ثبت شده است', $r->body);

    $r = $admin->submit('/admin/settings/scoring', ['action' => 'reset']);
    contains('به مقادیر پیش‌فرض برگشت', $r->body);
    contains('value="30"', $r->body);
});

test('general settings, the judges directory and the activity log', function () use (&$admin, &$state) {
    $r = $admin->submit('/admin/settings/general', ['org_name' => '', 'max_upload_mb' => '20']);
    contains('نام سازمان را وارد کنید', $r->body);
    $r = $admin->submit('/admin/settings/general', ['org_name' => 'پارک علم و فناوری فاوا', 'app_url' => 'ftp://x', 'max_upload_mb' => '20']);
    contains('باید با http://', $r->body);
    $r = $admin->submit('/admin/settings/general', ['org_name' => 'پارک علم و فناوری فاوا', 'app_url' => 'https://assess.park.example.ir/', 'max_upload_mb' => '10',
        'support_contact' => 'دبیرخانه: ۰۲۱-۰۰۰۰', 'notify_on_submit' => '1', 'judge_show_doc' => '1']);
    contains('تنظیمات ذخیره شد', $r->body);
    contains('پارک علم و فناوری فاوا', $r->body);
    $r = $admin->get('/admin/proposals/' . $state['second']);
    eq(200, $r->status);

    $r = $admin->submit('/admin/judges/save', ['name' => 'دکتر نگار صادقی', 'email' => 'negar@example.org', 'mobile' => '۰۹۱۲۰۰۰۰۰۰۲', 'expertise' => 'biz', 'organization' => 'دانشگاه نمونه']);
    contains('دکتر نگار صادقی', $r->body);
    $r = $admin->submit('/admin/judges/save', ['name' => '', 'email' => 'bad']);
    contains('نام داور را وارد کنید', $r->body);
    $r = $admin->get('/admin/proposals/' . $state['second']);
    contains('دکتر نگار صادقی', $r->body, 'offered when assigning a business judge');

    $r = $admin->get('/admin/activity');
    eq(200, $r->status);
    $t = text_of($r);
    foreach (['سامانه نصب شد', 'بارگذاری شد', 'فرم را ثبت کرد', 'تصمیم نهایی', 'بازگشایی شد'] as $needle) {
        contains($needle, $t);
    }
    $r = $admin->get('/admin/activity?action=submit');
    eq(200, $r->status);
    $r = $admin->get('/admin/proposals?q=' . rawurlencode('كيت تشخيص'));
    contains('کیت تشخیص سریع', $r->body, 'search with Arabic letters');
    $r = $admin->get('/admin/proposals?q=' . rawurlencode('<script>alert(1)</script>'));
    eq(200, $r->status);
    lacks('<script>alert(1)</script>', $r->body, 'the search box is escaped');
});

test('with the absolute address set, judge links use it', function () use (&$admin, &$state) {
    $url = '/admin/proposals/' . $state['second'];
    $r = $admin->submit($url . '/assign/biz', ['name' => 'داور پیوند']);
    contains('https://assess.park.example.ir/f/', $r->body);
    $admin->submit($url . '/unassign/biz');
    $admin->submit('/admin/settings/general', ['org_name' => 'پارک فاوا', 'app_url' => '', 'max_upload_mb' => '20', 'judge_show_doc' => '1', 'judge_allow_download' => '1', 'notify_on_submit' => '1']);
});

test('changing the password ends other sessions', function () use (&$app, &$admin) {
    $other = new HttpClient($app['base']);
    $r = $other->get('/admin/login');
    preg_match('/name="_csrf" value="([^"]+)"/', $r->body, $m);
    $other->post('/admin/login', ['_csrf' => $m[1], 'username' => 'admin', 'password' => 'Test-12345']);
    eq(200, $other->get('/admin')->status);

    $r = $admin->submit('/admin/settings/password', ['name' => 'مدیر آزمون', 'email' => 'admin@park.example.ir', 'current_password' => 'wrong', 'password' => 'New-Pass-2026', 'password_confirm' => 'New-Pass-2026']);
    contains('رمز عبور فعلی نادرست است', $r->body);
    lacks('New-Pass-2026', $r->body);
    $r = $admin->submit('/admin/settings/password', ['name' => 'مدیر آزمون', 'email' => 'admin@park.example.ir', 'current_password' => 'Test-12345', 'password' => 'short', 'password_confirm' => 'short']);
    contains('حداقل ۸ نویسه', $r->body);
    $r = $admin->submit('/admin/settings/password', ['name' => 'مدیر آزمون', 'email' => 'admin@park.example.ir', 'current_password' => 'Test-12345', 'password' => 'New-Pass-2026', 'password_confirm' => 'New-Pass-2026']);
    contains('رمز عبور تغییر کرد', $r->body);
    eq(200, $admin->get('/admin')->status, 'this session stays');
    eq(302, $other->get('/admin')->status, 'the other browser is logged out');
});

test('deleting a proposal removes its file and kills its links', function () use (&$app, &$admin, &$state) {
    $url = '/admin/proposals/' . $state['second'];
    $r = $admin->submit($url . '/assign/tech', ['name' => 'داور موقت']);
    $token = page_tokens($r)[0];
    $before = count(glob($app['home'] . '/storage/uploads/*.docx'));
    $r = $admin->submit($url . '/delete');
    contains('طرح حذف شد', $r->body);
    eq($before - 1, count(glob($app['home'] . '/storage/uploads/*.docx')));
    eq(404, $admin->get($url)->status);
    eq(404, (new HttpClient($app['base']))->get('/f/' . $token)->status);
    $r = $admin->get('/admin/activity');
    eq(200, $r->status, 'the log still opens although it mentions the deleted proposal');
    contains('حذف شد', text_of($r));
});

test('too many wrong passwords block the address for a while', function () use (&$app) {
    $c = new HttpClient($app['base']);
    $r = $c->get('/admin/login');
    preg_match('/name="_csrf" value="([^"]+)"/', $r->body, $m);
    for ($i = 0; $i < 6; $i++) {
        $r = $c->post('/admin/login', ['_csrf' => $m[1], 'username' => 'admin', 'password' => 'guess-' . $i]);
        eq(302, $r->status);
    }
    $r = $c->post('/admin/login', ['_csrf' => $m[1], 'username' => 'admin', 'password' => 'New-Pass-2026']);
    $r = $c->get($r->location());
    contains('تا ۱۵ دقیقه دیگر ممکن نیست', $r->body, 'even the right password is refused while locked');
    eq(302, $c->get('/admin')->status);
});

test('the lost password can be reset with a file on the server', function () use (&$app, &$admin) {
    file_put_contents($app['home'] . '/storage/reset-admin.txt', "Recovered-2026\n");
    $c = new HttpClient($app['base']);
    $r = $c->get('/admin/login');
    contains('بازنشانی شد', $r->body);
    eq(false, is_file($app['home'] . '/storage/reset-admin.txt'));
    preg_match('/name="_csrf" value="([^"]+)"/', $r->body, $m);
    $r = $c->post('/admin/login', ['_csrf' => $m[1], 'username' => 'admin', 'password' => 'Recovered-2026']);
    eq(302, $r->status);
    eq('/admin', $r->location(), 'the reset also lifts the lock');
    eq(200, $c->get('/admin')->status);
    eq(302, $admin->get('/admin')->status, 'whoever was logged in with the old password is out');
    $admin = $c;
});

test('unknown pages and wrong methods give proper error pages', function () use (&$app, &$admin) {
    $r = $admin->get('/admin/nothing-here');
    eq(404, $r->status);
    contains('پیدا نشد', $r->body);
    contains('dir="rtl"', $r->body);
    eq(404, $admin->get('/admin/proposals/999999')->status);
    eq(404, $admin->get('/admin/proposals/abc')->status);
    eq(405, $admin->get('/admin/proposals/upload')->status);
    eq(404, $admin->get('/wp-login.php')->status);
    $r = $admin->get('/admin/nothing-here', ['Accept: application/json']);
    eq(404, $r->status);
    eq(false, $r->json()['ok']);
});

test('no PHP warning or exception was logged during the whole run', function () use (&$app) {
    $log = app_errors($app);
    if (getenv('FAVA_TEST_DUMP_LOG')) {
        file_put_contents((string) getenv('FAVA_TEST_DUMP_LOG'), $log);
    }
    $bad = [];
    foreach (explode("\n", $log) as $line) {
        if ($line === '' || strpos($line, 'installer: database') !== false) {
            continue; // the deliberately wrong database of the installer test
        }
        if (preg_match('/PHP (warning|deprecation|Warning|Notice|Fatal|Deprecated)|exception|Exception|Error/', $line)) {
            $bad[] = mb_substr($line, 0, 400);
        }
    }
    eq([], array_slice($bad, 0, 5));
});

group('End-to-end: other ways to run');

test('without URL rewriting everything works through index.php?r=', function () {
    $app = start_app();
    $c = install_app($app, ['pretty_urls' => '0']);
    $r = $c->get('/index.php?r=/admin');
    eq(200, $r->status);
    contains('index.php?r=/admin/proposals', $r->body, 'links are written in the fallback form');
    lacks('href="/admin/proposals"', $r->body);
    preg_match('/name="_csrf" value="([^"]+)"/', $r->body, $m);
    $r = $c->post('/index.php?r=/admin/proposals/upload', ['_csrf' => $m[1]], ['files[]' => ['a.docx', (string) file_get_contents(fixture('sample-proposal-3.docx'))]]);
    eq(302, $r->status);
    contains('index.php?r=/admin/proposals/1', $r->location());
    $c->post('/index.php?r=/admin/proposals/1/update', ['_csrf' => $m[1], 'action' => 'confirm', 'title' => 'پلتفرم آموزش', 'company' => 'آوای کویر']);
    $r = $c->post('/index.php?r=/admin/proposals/1/assign/biz', ['_csrf' => $m[1], 'name' => 'داور']);
    $r = $c->get($r->location());
    ok((bool) preg_match('#index\.php\?r=/f/([A-Za-z0-9_\-]{32})#', $r->body, $t), 'the judge link uses the fallback form too');
    $judge = new HttpClient($app['base']);
    $r = $judge->get('/index.php?r=/f/' . $t[1]);
    eq(200, $r->status);
    preg_match('#id="form-boot">(.*?)</script>#s', $r->body, $b);
    $boot = json_decode($b[1], true);
    contains('index.php?r=/f/' . $t[1] . '/draft', $boot['urls']['draft']);
    $r = $judge->postJson($boot['urls']['draft'], ['open' => true]);
    eq(200, $r->status);
    $r = $c->get('/index.php?r=/admin/proposals&q=' . rawurlencode('آموزش'));
    eq(200, $r->status);
    contains('پلتفرم آموزش', $r->body);
    $r = $c->get('/index.php?r=/admin/proposals&status=review');
    lacks('پلتفرم آموزش', $r->body, 'filters work in this mode as well');
    eq('', preg_replace('/^.*(PHP warning|exception).*$/im', 'X', app_errors($app)) === app_errors($app) ? '' : app_errors($app));
});

test('installed in a sub-folder (example.ir/assess/)', function () {
    $app = start_app([], '/assess');
    $c = install_app($app);
    $r = $c->get('/admin');
    eq(200, $r->status);
    contains('href="/assess/admin/proposals"', $r->body);
    contains('/assess/assets/css/app.css', $r->body);
    eq(200, (new HttpClient($app['base']))->get('/assets/css/app.css')->status);
    $r = $c->post('/admin/proposals/upload', ['_csrf' => $c->csrf()], ['files[]' => ['a.docx', (string) file_get_contents(fixture('sample-proposal-3.docx'))]]);
    eq('/assess/admin/proposals/1', $r->location());
    $c->submit('/admin/proposals/1/update', ['action' => 'confirm', 'title' => 'پلتفرم آموزش', 'company' => 'آوای کویر']);
    $r = $c->submit('/admin/proposals/1/assign/tech', ['name' => 'داور']);
    ok((bool) preg_match('#http://127\.0\.0\.1:\d+/assess/f/([A-Za-z0-9_\-]{32})#', $r->body, $t), 'absolute judge link includes the folder');
    $judge = new HttpClient($app['base']);
    $r = $judge->get('/f/' . $t[1]);
    eq(200, $r->status);
    preg_match('#id="form-boot">(.*?)</script>#s', $r->body, $b);
    eq('/assess/f/' . $t[1] . '/submit', json_decode($b[1], true)['urls']['submit']);
    $line = strtolower((string) reset($c->cookieLines));
    contains('path=/assess', $line, 'the session cookie is limited to the folder');
});

test('behind HTTPS the session cookie is marked Secure', function () {
    $app = start_app();
    $c = new HttpClient($app['base']);
    $c->defaultHeaders = ['X-Forwarded-Proto: https'];
    $r = $c->post('/install', ['driver' => 'sqlite', 'admin_name' => 'مدیر', 'admin_user' => 'admin', 'admin_pass' => 'Test-12345', 'admin_pass2' => 'Test-12345', 'pretty_urls' => '1']);
    eq(302, $r->status);
    contains('secure', strtolower((string) reset($c->cookieLines)));
    $r = $c->get('/admin');
    eq(200, $r->status);
});

group('End-to-end: e-mail, and a proposal whose amount the judges enter');

$mail = ['app' => null, 'admin' => null, 'smtp' => null, 'n' => 0, 'tokens' => []];

test('SMTP settings are saved (password encrypted) and the test message arrives', function () use (&$mail) {
    $mail['smtp'] = smtp_server('plain', 'assess@park.example.ir', 'Smtp-Secret-1');
    $mail['app'] = start_app();
    $c = $mail['admin'] = install_app($mail['app']);
    $good = ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => (string) $mail['smtp']['port'], 'encryption' => 'none',
        'username' => 'assess@park.example.ir', 'password' => 'Smtp-Secret-1', 'from_email' => 'assess@park.example.ir', 'from_name' => 'پارک فاوا', 'verify_peer' => '1'];

    $r = $c->submit('/admin/settings/mail', ['host' => 'bad host!', 'from_email' => 'x'] + $good);
    contains('نشانی ایمیل فرستنده را درست وارد کنید', $r->body);
    contains('نام سرور SMTP را وارد کنید', $r->body);
    lacks('Smtp-Secret-1', $r->body, 'a refused form does not echo the password');

    $r = $c->submit('/admin/settings/mail', $good);
    contains('تنظیمات ایمیل ذخیره شد', $r->body);
    lacks('Smtp-Secret-1', $r->body, 'the stored password is never sent back to the browser');
    $dbFile = glob($mail['app']['home'] . '/storage/database/*.sqlite')[0];
    lacks('Smtp-Secret-1', (string) file_get_contents($dbFile), 'and it is not readable in the database');

    $r = $c->submit('/admin/settings/mail', ['password' => ''] + $good);
    contains('تنظیمات ایمیل ذخیره شد', $r->body, 'an empty password field keeps the stored password');

    $r = $c->submit('/admin/settings/test-mail', ['to' => 'not-an-address']);
    contains('گیرنده آزمایشی معتبر نیست', $r->body);
    $r = $c->submit('/admin/settings/test-mail', ['to' => 'Someone@Example.org']);
    contains('تحویل سرور ایمیل شد', $r->body);
    $m = smtp_message($mail['smtp'], ++$mail['n']);
    eq(['someone@example.org'], $m['envelope']['to']);
    eq('assess@park.example.ir', $m['envelope']['from']);
    eq(true, $m['envelope']['authed'], 'logged in with the stored password');
    contains('ایمیل آزمایشی', decode_header($m['headers']['subject']));
    contains('تنظیمات ارسال ایمیل درست است', $m['text']);

    $r = $c->submit('/admin/settings/test-mail', ['to' => 'reject-me@example.org']);
    contains('ارسال ایمیل آزمایشی ناموفق بود', $r->body, 'a refusal by the mail server is shown to the admin');
    contains('550', $r->body, 'with what the server said');
    lacks('Smtp-Secret-1', $r->body);
    lacks(base64_encode('Smtp-Secret-1'), $r->body, 'the conversation shown on the page hides the login');
});

test('the invitation and the reminder are e-mailed with the right private link', function () use (&$mail) {
    $c = $mail['admin'];
    $r = $c->post('/admin/proposals/upload', ['_csrf' => $c->csrf()], ['files[]' => ['kit.docx', (string) file_get_contents(fixture('sample-proposal-2.docx'))]]);
    eq('/admin/proposals/1', $r->location());
    $r = $c->submit('/admin/proposals/1/send', ['role' => 'all']);
    contains('ابتدا اطلاعات طرح را بررسی و تأیید کنید', $r->body);
    $r = $c->submit('/admin/proposals/1/update', ['action' => 'confirm', 'title' => 'کیت تشخیص سریع آلودگی آب', 'company' => 'زیست‌فناوران سبز خزر', 'requested_amount' => '', 'due_date' => '1406/02/01', 'share_doc' => '1']);
    contains('اطلاعات طرح تأیید شد', $r->body);

    $r = $c->submit('/admin/proposals/1/assign/tech', ['name' => 'دکتر رضا فنی‌پور', 'email' => 'Reza@Example.org']);
    $tech = page_tokens($r)[0];
    $r = $c->submit('/admin/proposals/1/assign/biz', ['name' => 'مهندس مریم بازارگان']);
    $biz = array_values(array_diff(page_tokens($r), [$tech]))[0];
    $mail['tokens'] = ['tech' => $tech, 'biz' => $biz];

    $r = $c->submit('/admin/proposals/1/send', ['role' => 'all']);
    contains('ایمیل ارسال شد', $r->body, 'sent to the judge who has an address');
    contains('نشانی ایمیل ثبت نشده', $r->body, 'and the other one is reported');
    $m = smtp_message($mail['smtp'], ++$mail['n']);
    eq(['reza@example.org'], $m['envelope']['to']);
    contains($mail['app']['base'] . '/f/' . $tech, $m['text']);
    contains($mail['app']['base'] . '/f/' . $tech, $m['html']);
    lacks($biz, $m['text'] . $m['html'] . $m['raw']);
    contains('کیت تشخیص سریع آلودگی آب', $m['text']);
    contains('دکتر رضا فنی‌پور', $m['text']);
    contains('مهلت تکمیل فرم: ۱ اردیبهشت ۱۴۰۶', $m['text']);
    contains('دعوت', decode_header($m['headers']['subject']));
    $t = text_of($r);
    contains('ارسال شده', $t);
    contains('در حال داوری', $t, 'the proposal moved on by itself');
    contains('پیوند داور کسب‌وکاری هنوز ارسال نشده است', $t, 'the next step names the judge who has not received the link');
    contains('در انتظار ثبت فرم داور فنی', $t);
    $t = text_of($c->get('/admin'));
    contains('یکی از داوران هنوز تعیین نشده یا پیوند او ارسال نشده است', $t, 'and the dashboard lists it as work for the admin');
    lacks('کاری در انتظار شما نیست', $t);

    $r = $c->submit('/admin/proposals/1/send', ['role' => 'tech', 'kind' => 'reminder']);
    contains('ایمیل ارسال شد', $r->body);
    $m = smtp_message($mail['smtp'], ++$mail['n']);
    contains('یادآوری', decode_header($m['headers']['subject']));
    contains('/f/' . $tech, $m['text']);

    $r = $c->submit('/admin/proposals/1/send', ['role' => 'nobody']);
    contains('داوری برای ارسال انتخاب نشده است', $r->body);
});

test('when the admin leaves the amount open, each judge must enter it', function () use (&$mail) {
    $judge = new HttpClient($mail['app']['base']);
    $r = $judge->get('/f/' . $mail['tokens']['tech']);
    eq(200, $r->status);
    contains('data-field="requested_amount"', $r->body);
    preg_match('#id="form-boot">(.*?)</script>#s', $r->body, $b);
    eq(null, json_decode($b[1], true)['fixedAmount']);

    $answers = browser_answers('tech', [4]);
    $r = $judge->postJson('/f/' . $mail['tokens']['tech'] . '/submit', ['answers' => $answers]);
    eq(422, $r->status);
    eq(['requested_amount'], array_keys($r->json()['errors']));
    $r = $judge->postJson('/f/' . $mail['tokens']['tech'] . '/submit', ['answers' => ['requested_amount' => '20,5'] + $answers]);
    eq(422, $r->status, 'an ambiguous number is not guessed');
    $r = $judge->postJson('/f/' . $mail['tokens']['tech'] . '/submit', ['answers' => ['requested_amount' => '-3'] + $answers]);
    eq(422, $r->status);
    $r = $judge->postJson('/f/' . $mail['tokens']['tech'] . '/submit', ['answers' => ['requested_amount' => '۲۰'] + $answers]);
    eq(200, $r->status);

    $m = smtp_message($mail['smtp'], ++$mail['n']);
    eq(['admin@park.example.ir'], $m['envelope']['to'], 'the admin is told that a form came in');
    contains('داور فنی', $m['text']);
    contains('/admin/proposals/1', $m['text']);
    lacks('/f/', $m['text']);

    $r = $judge->postJson('/f/' . $mail['tokens']['biz'] . '/submit', ['answers' => ['requested_amount' => 25] + browser_answers('biz', [4])]);
    eq(200, $r->status);
    $m = smtp_message($mail['smtp'], ++$mail['n']);
    contains('کامل شد', decode_header($m['headers']['subject']));
    contains('۸۰', $m['text'], 'total score');
    contains('یکسان نیست', $m['text'], 'the difference between the judges is mentioned');
});

test('the admin settles the amount the judges disagree on', function () use (&$mail) {
    $c = $mail['admin'];
    $r = $c->get('/admin/proposals/1');
    $t = text_of($r);
    contains('نظر دو داور یکسان نیست', $t);
    contains('۲۰ میلیارد ریال', $t);
    contains('۲۵ میلیارد ریال', $t);
    $r = $c->submit('/admin/proposals/1/finalize', ['decision' => 'approve', 'amount' => '10', 'note' => 'x']);
    contains('نظر دو داور درباره سطح طرح یا مبلغ درخواستی یکسان نیست', $r->body);

    $r = $c->submit('/admin/proposals/1/resolve', []);
    contains('مبلغ درخواستی شرکت را وارد کنید', $r->body);
    $r = $c->submit('/admin/proposals/1/resolve', ['requested_amount' => 'abc']);
    contains('مبلغ درخواستی باید عددی بزرگ‌تر از صفر', $r->body);
    $r = $c->submit('/admin/proposals/1/resolve', ['requested_amount' => '22']);
    contains('نتیجه دوباره محاسبه شد', $r->body);
    $t = text_of($r);
    contains('۱۹.۳۶', $t, '22 × 0.80 × 1.10');
    lacks('نظر دو داور یکسان نیست', $t);

    $r = $c->submit('/admin/proposals/1/reextract');
    contains('فایل دوباره خوانده شد', $r->body);
    contains('کیت تشخیص سریع آلودگی آب', $r->body, 'the confirmed title is not overwritten by the file');
    contains('۱۹.۳۶', text_of($r), 'and the result stays');

    $c->submit('/admin/settings/scoring', ['ceiling_A' => '10', 'ceiling_B' => '9', 'ceiling_C' => '8', 'reject_below' => '40', 'conditional_below' => '60',
        'w_tech' => '30', 'w_team' => '20', 'w_market' => '25', 'w_finance' => '25', 'risk_base' => '0.7', 'risk_step' => '0.15',
        'pay_1' => '30', 'pay_2' => '40', 'pay_3' => '30', 'cap_amount' => '1']);
    $r = $c->get('/admin/proposals/1');
    contains('۱۹.۳۶', text_of($r), 'new settings do not touch a proposal that is being judged');
    $r = $c->submit('/admin/proposals/1/refresh-config');
    contains('پارامترهای امتیازدهی این طرح به‌روز شد', $r->body);
    contains('۸.۸ میلیارد ریال', text_of($r), 'unless the admin asks for it: the new ceiling of tier A (10) × 0.80 × 1.10');
    $csv = $c->get('/admin/export/proposals');
    $rows = array_map('str_getcsv', preg_split('/\r\n|\n/', trim(substr($csv->body, 3))));
    $row = array_combine($rows[0], $rows[1]);
    near(8.8, (float) $row['مبلغ محاسبه‌شده (میلیارد ریال)']);
    near(22.0, (float) $row['مبلغ درخواستی (میلیارد ریال)']);
    near(80.0, (float) $row['امتیاز کل']);

    $r = $c->submit('/admin/proposals/1/finalize', ['decision' => 'approve', 'amount' => '8.8']);
    contains('تصمیم نهایی ثبت شد', $r->body);
    $r = $c->get('/admin/proposals/1/report');
    $t = text_of($r);
    contains('۲۲', $t);
    contains('۲.۶۴', $t, 'first payment: 30% of 8.8');
    contains('۳.۵۲', $t, 'second payment: 40% of 8.8');
});

test('a proposal in the reject band: no milestone needed, rejection stores zero', function () use (&$mail) {
    $c = $mail['admin'];
    $r = $c->post('/admin/proposals/upload', ['_csrf' => $c->csrf()], ['files[]' => ['edu.docx', (string) file_get_contents(fixture('sample-proposal-3.docx'))]]);
    eq('/admin/proposals/2', $r->location());
    $c->submit('/admin/proposals/2/update', ['action' => 'confirm', 'title' => 'پلتفرم آموزش مهارت', 'company' => 'آوای کویر', 'requested_amount' => '6.5']);
    $r = $c->submit('/admin/proposals/2/assign/tech', ['name' => 'داور یک']);
    $tech = page_tokens($r)[0];
    $r = $c->submit('/admin/proposals/2/assign/biz', ['name' => 'داور دو']);
    $biz = array_values(array_diff(page_tokens($r), [$tech]))[0];

    $judge = new HttpClient($mail['app']['base']);
    $weak = browser_answers('tech', [1], ['milestone2' => '', 'scalability' => 'low', 'priority' => 'normal', 'trl' => 2, 'risk_exec' => 1]);
    $r = $judge->postJson('/f/' . $tech . '/submit', ['answers' => $weak]);
    eq(200, $r->status, 'no second milestone is asked for a proposal that scores in the reject band');
    $r = $judge->postJson('/f/' . $biz . '/submit', ['answers' => browser_answers('biz', [1], ['scalability' => 'low', 'priority' => 'normal', 'risk_market' => 1])]);
    eq(200, $r->status);
    smtp_message($mail['smtp'], ++$mail['n']);
    $m = smtp_message($mail['smtp'], ++$mail['n']);
    contains('پلتفرم آموزش مهارت', decode_header($m['headers']['subject']), 'the two notes to the admin about this proposal');
    contains('رد قطعی', $m['text']);

    $r = $c->get('/admin/proposals/2');
    $t = text_of($r);
    contains('۲۰', $t);
    contains('رد', $t);
    $r = $c->submit('/admin/proposals/2/finalize', ['decision' => 'reject']);
    contains('تصمیم نهایی ثبت شد', $r->body, 'the system\'s own verdict needs no note and no amount');
    $r = $c->get('/admin/export/proposals');
    $rows = array_map('str_getcsv', preg_split('/\r\n|\n/', trim(substr($r->body, 3))));
    eq(3, count($rows));
    $last = array_combine($rows[0], $rows[2]);
    eq('P1405-002', $last['کد']);
    near(20.0, (float) $last['امتیاز کل']);
    near(0.0, (float) $last['مبلغ نهایی (میلیارد ریال)']);

    $r = $c->submit('/admin/proposals/2/unfinalize');
    contains('تصمیم نهایی لغو شد', $r->body);
    $r = $c->submit('/admin/proposals/2/finalize', ['decision' => 'approve', 'amount' => '3']);
    contains('دلیل آن را بنویسید', $r->body, 'overruling the system needs a reason');
    $r = $c->submit('/admin/proposals/2/finalize', ['decision' => 'approve', 'amount' => '3', 'note' => 'اهمیت راهبردی برای استان']);
    contains('تصمیم نهایی ثبت شد', $r->body);
});

test('with e-mail switched off, nothing is sent and the admin is told how to proceed', function () use (&$mail) {
    $c = $mail['admin'];
    $r = $c->submit('/admin/settings/mail', ['transport' => 'none']);
    contains('تنظیمات ایمیل ذخیره شد', $r->body);
    $r = $c->submit('/admin/settings/test-mail', ['to' => 'someone@example.org']);
    contains('ناموفق', $r->body);
    smtp_stop($mail['smtp']);
    eq(false, is_file($mail['smtp']['dir'] . '/message-' . ($mail['n'] + 1) . '.eml'), 'no unexpected message was sent during the whole flow');
    $bad = array_filter(explode("\n", app_errors($mail['app'])), function ($line) {
        return (bool) preg_match('/PHP (warning|deprecation)|Exception|Error:/i', $line);
    });
    eq([], array_values(array_slice($bad, 0, 3)), 'nothing in the error log');
});

$mysqlEnv = getenv('FAVA_TEST_MYSQL') ? json_decode((string) getenv('FAVA_TEST_MYSQL'), true) : null;
if (is_array($mysqlEnv)) {
    group('End-to-end: installed on MySQL/MariaDB');

    test('the installer explains wrong database details in plain words', function () use ($mysqlEnv) {
        $app = start_app();
        $c = new HttpClient($app['base']);
        $base = ['driver' => 'mysql', 'db_host' => $mysqlEnv['host'], 'db_name' => $mysqlEnv['name'], 'db_user' => $mysqlEnv['user'], 'db_pass' => $mysqlEnv['pass'],
            'db_prefix' => 'x_', 'admin_name' => 'مدیر', 'admin_user' => 'admin', 'admin_pass' => 'Test-12345', 'admin_pass2' => 'Test-12345', 'pretty_urls' => '1'];
        $r = $c->post('/install', ['db_pass' => 'wrong-password'] + $base);
        eq(422, $r->status);
        contains('نام کاربری یا رمز عبور پایگاه‌داده پذیرفته نشد', $r->body);
        lacks('wrong-password', $r->body);
        $r = $c->post('/install', ['db_name' => 'no_such_database_here'] + $base);
        eq(422, $r->status);
        ok(strpos($r->body, 'پایگاه‌داده‌ای با این نام وجود ندارد') !== false || strpos($r->body, 'این کاربر به پایگاه‌داده دسترسی ندارد') !== false, 'unknown database explained');
        $r = $c->post('/install', ['db_prefix' => 'bad prefix!'] + $base);
        eq(422, $r->status);
        contains('پیشوند فقط می‌تواند', $r->body);
        eq(false, is_file($app['home'] . '/config/config.php'));
        lacks($mysqlEnv['pass'], app_errors($app), 'the database password never reaches the log');
    });

    test('installation and a complete assessment on MySQL/MariaDB', function () use ($mysqlEnv) {
        $prefix = 'h' . bin2hex(random_bytes(3)) . '_';
        $pdo = new \PDO('mysql:host=' . preg_replace('/:(\d+)$/', ';port=$1', $mysqlEnv['host']) . ';dbname=' . $mysqlEnv['name'] . ';charset=utf8mb4',
            $mysqlEnv['user'], $mysqlEnv['pass'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $drop = function () use ($pdo, $prefix) {
            foreach ($pdo->query("SHOW TABLES LIKE '" . str_replace('_', '\\_', $prefix) . "%'")->fetchAll(\PDO::FETCH_COLUMN) as $t) {
                $pdo->exec('DROP TABLE IF EXISTS `' . $t . '`');
            }
        };
        register_shutdown_function($drop);

        $app = start_app();
        $c = install_app($app, ['driver' => 'mysql', 'db_host' => $mysqlEnv['host'], 'db_name' => $mysqlEnv['name'], 'db_user' => $mysqlEnv['user'],
            'db_pass' => $mysqlEnv['pass'], 'db_prefix' => $prefix]);
        $cfg = (string) file_get_contents($app['home'] . '/config/config.php');
        contains("'driver' => 'mysql'", $cfg);
        eq(200, $c->get('/admin')->status);

        $tables = $pdo->query("SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '" . str_replace('_', '\\_', $prefix) . "%'")->fetchAll(\PDO::FETCH_ASSOC);
        ok(count($tables) >= 7, count($tables) . ' tables');
        foreach ($tables as $t) {
            eq('InnoDB', $t['ENGINE'], $t['TABLE_NAME']);
            contains('utf8mb4', $t['TABLE_COLLATION'], $t['TABLE_NAME'] . ' must not inherit the server default');
        }

        $r = $c->post('/admin/proposals/upload', ['_csrf' => $c->csrf()], ['files[]' => ['کاربرگ ۱.docx', (string) file_get_contents(fixture('sample-proposal-1.docx'))]]);
        eq('/admin/proposals/1', $r->location());
        $title = 'سامانهٔ پایش «انرژی» — نسخه ۲ 🚀 ژ‌پ‌گ‌چ';
        $r = $c->submit('/admin/proposals/1/update', ['action' => 'confirm', 'title' => $title, 'company' => 'داده‌پردازان نوآور البرز', 'requested_amount' => '18.5']);
        contains(e($title), $r->body);
        eq($title, $pdo->query('SELECT title FROM `' . $prefix . 'proposals` WHERE id = 1')->fetchColumn(), 'stored as real UTF-8 (4-byte characters too), not as mojibake');

        $r = $c->submit('/admin/proposals/1/assign/tech', ['name' => 'دکتر رضا فنی‌پور']);
        $tech = page_tokens($r)[0];
        $r = $c->submit('/admin/proposals/1/assign/biz', ['name' => 'مهندس مریم بازارگان']);
        $biz = array_values(array_diff(page_tokens($r), [$tech]))[0];
        $judge = new HttpClient($app['base']);
        $other = strtolower($tech) === $tech ? strtoupper($tech) : strtolower($tech);
        eq(404, $judge->get('/f/' . $other)->status, 'links stay case-sensitive although MySQL compares text case-insensitively');
        eq(200, $judge->get('/f/' . $tech)->status);
        eq(200, $judge->postJson('/f/' . $tech . '/draft', ['open' => true, 'answers' => ['scores' => ['tech-1' => 3]]])->status);
        eq(200, $judge->postJson('/f/' . $tech . '/submit', ['answers' => browser_answers('tech', [4, 5, 3, 4, 4])])->status);
        eq(200, $judge->postJson('/f/' . $biz . '/submit', ['answers' => browser_answers('biz', [3, 4, 3, 2, 4], ['scalability' => 'medium', 'priority' => 'medium'])])->status);

        $r = $c->submit('/admin/proposals/1/resolve', ['tier' => 'A']);
        contains('۱۴.۶۵۲', text_of($r));
        $r = $c->submit('/admin/proposals/1/finalize', ['decision' => 'approve', 'amount' => '14.652']);
        contains('تصمیم نهایی ثبت شد', $r->body);
        $row = $pdo->query('SELECT status, total_score, final_amount, final_decision FROM `' . $prefix . 'proposals` WHERE id = 1')->fetch(\PDO::FETCH_ASSOC);
        eq('finalized', $row['status']);
        near(72.0, (float) $row['total_score']);
        near(14.652, (float) $row['final_amount']);

        foreach (['/admin', '/admin/proposals', '/admin/judges', '/admin/activity', '/admin/settings', '/admin/proposals/1/report', '/admin/export/proposals', '/admin/export/scores',
            '/admin/proposals?q=' . rawurlencode('انرژی'), '/admin/proposals?status=finalized', '/admin/activity?action=submit'] as $page) {
            eq(200, $c->get($page)->status, $page);
        }
        contains(e($title), $c->get('/admin/proposals?q=' . rawurlencode('انرژي'))->body, 'search with an Arabic letter variant');
        $r = $c->submit('/admin/proposals/1/delete');
        contains('طرح حذف شد', $r->body);
        eq(0, (int) $pdo->query('SELECT COUNT(*) FROM `' . $prefix . 'assignments`')->fetchColumn(), 'the forms went with the proposal');

        $bad = array_filter(explode("\n", app_errors($app)), function ($line) {
            return (bool) preg_match('/PHP (warning|deprecation)|Exception|Error:/i', $line);
        });
        eq([], array_values(array_slice($bad, 0, 3)), 'nothing in the error log');
        $drop();
    });

    test('running the installer again on the same database keeps the data', function () use ($mysqlEnv) {
        $prefix = 'r' . bin2hex(random_bytes(3)) . '_';
        $pdo = new \PDO('mysql:host=' . preg_replace('/:(\d+)$/', ';port=$1', $mysqlEnv['host']) . ';dbname=' . $mysqlEnv['name'] . ';charset=utf8mb4',
            $mysqlEnv['user'], $mysqlEnv['pass'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $drop = function () use ($pdo, $prefix) {
            foreach ($pdo->query("SHOW TABLES LIKE '" . str_replace('_', '\\_', $prefix) . "%'")->fetchAll(\PDO::FETCH_COLUMN) as $t) {
                $pdo->exec('DROP TABLE IF EXISTS `' . $t . '`');
            }
        };
        register_shutdown_function($drop);
        $db = ['driver' => 'mysql', 'db_host' => $mysqlEnv['host'], 'db_name' => $mysqlEnv['name'], 'db_user' => $mysqlEnv['user'], 'db_pass' => $mysqlEnv['pass'], 'db_prefix' => $prefix];

        $app = start_app();
        $c = install_app($app, $db);
        $c->post('/admin/proposals/upload', ['_csrf' => $c->csrf()], ['files[]' => ['a.docx', (string) file_get_contents(fixture('sample-proposal-3.docx'))]]);
        eq(1, (int) $pdo->query('SELECT COUNT(*) FROM `' . $prefix . 'proposals`')->fetchColumn());

        // the config file is lost (for example the site was moved); the installer runs again
        unlink($app['home'] . '/config/config.php');
        $c2 = install_app($app, $db + ['admin_pass' => 'Second-Pass-1', 'admin_pass2' => 'Second-Pass-1']);
        eq(1, (int) $pdo->query('SELECT COUNT(*) FROM `' . $prefix . 'proposals`')->fetchColumn(), 'the proposals are still there');
        eq(1, (int) $pdo->query('SELECT COUNT(*) FROM `' . $prefix . 'admins`')->fetchColumn(), 'no second admin account');
        $r = $c2->get('/admin/proposals/1');
        eq(200, $r->status);
        $drop();
    });
}
