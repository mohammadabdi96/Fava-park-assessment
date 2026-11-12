<?php

use Fava\Core\Fa;
use Fava\Core\Jalali;

group('Persian text and numbers');

test('digits are converted both ways', function () {
    eq('۱۴۰۵/۰۸/۲۰', Fa::digits('1405/08/20'));
    eq('۳ طرح بارگذاری شد', Fa::digits('3 طرح بارگذاری شد'));
    eq('www.3onsor.ir', Fa::digits('www.3onsor.ir'), 'digits of an address are not translated');
    eq('نشانی: info2@mail.example.com (۲ صندوق)', Fa::digits('نشانی: info2@mail.example.com (2 صندوق)'));
    eq('طرح P1405-002 با ۱۲ امتیاز', Fa::digits('طرح P1405-002 با 12 امتیاز'));
    eq('گواهی «ISO9001» سال ۱۴۰۳', Fa::digits('گواهی «ISO9001» سال 1403'));
    eq('TRL ۶', Fa::digits('TRL 6'), 'a number next to a Latin word is still a number');
    eq('بدون رقم', Fa::digits('بدون رقم'));
    eq("\xC3\x28 5x", Fa::digits("\xC3\x28 5x"), 'broken text is returned untouched');
    eq('1405/08/20', Fa::latin('۱۴۰۵/۰۸/۲۰'));
    eq('2026', Fa::latin('٢٠٢٦'), 'Arabic-Indic digits');
});

test('numbers typed into forms: the dot is always a decimal mark', function () {
    near(14.652, Fa::number('14.652'), 'three decimals are not thousands');
    near(0.15, Fa::number('0.150'));
    near(18.5, Fa::number('۱۸٫۵'), 'Persian decimal separator');
    near(18.5, Fa::number(' ۱۸.۵ '));
    near(2.5, Fa::number('۲/۵'), 'traditional Persian decimal slash');
    near(1500.0, Fa::number('1,500'));
    near(1234567.89, Fa::number('۱٬۲۳۴٬۵۶۷٫۸۹'));
    near(12.0, Fa::number(12));
    near(0.5, Fa::number('.5'));
});

test('ambiguous or wrong numbers are rejected instead of guessed', function () {
    eq(null, Fa::number('18,5'), '18,5 must not become 185');
    eq(null, Fa::number('1.234.567'));
    eq(null, Fa::number('1405/08/20'));
    eq(null, Fa::number('abc'));
    eq(null, Fa::number(''));
    eq(null, Fa::number('12 میلیارد'));
    eq(null, Fa::number(null));
    eq(null, Fa::number(['1']));
});

test('numbers found in documents: dots and commas may group thousands', function () {
    near(22000000000.0, Fa::looseNumber('22.000.000.000'));
    near(18500000000.0, Fa::looseNumber('۱۸٬۵۰۰٬۰۰۰٬۰۰۰'));
    near(2450.0, Fa::looseNumber('2,450'));
    near(6.5, Fa::looseNumber('6.5'));
    eq(null, Fa::looseNumber('—'));
});

test('matching keys ignore letter variants, half-spaces and punctuation', function () {
    eq(Fa::key('نام شرکت/ شتاب‌دهنده:'), Fa::key('نام شركت / شتاب دهنده'));
    eq(Fa::key('عنوان طرح فناوری'), Fa::key("عنوان  طرح\u{200C}فناوري :"));
    eq('trl6', Fa::key('TRL ۶'));
});

test('unicode-safe trim does not damage Persian text', function () {
    eq('سامانه', Fa::trim("\u{200C} سامانه \u{00A0}"));
    eq('عنوان', Fa::trim(':عنوان–', ':\x{2013}'));
    eq('پلتفرم آموزش', Fa::clean("  پلتفرم \t آموزش \n"));
    eq("خط اول\nخط دوم", Fa::clean("خط اول  \n\n   خط دوم ", true));
});

group('Jalali calendar');

test('known dates convert correctly', function () {
    eq([1405, 1, 1], Jalali::toJalali(2026, 3, 21));
    eq([1405, 8, 21], Jalali::toJalali(2026, 11, 12));
    eq([1404, 1, 1], Jalali::toJalali(2025, 3, 21));
    eq([1403, 12, 30], Jalali::toJalali(2025, 3, 20), '1403 is a leap year');
    eq([1357, 11, 22], Jalali::toJalali(1979, 2, 11));
    eq([2026, 3, 21], Jalali::toGregorian(1405, 1, 1));
    eq([2021, 3, 20], Jalali::toGregorian(1399, 12, 30));
    eq([2000, 1, 1], Jalali::toGregorian(1378, 10, 11));
});

test('every day of 60 years survives a round trip', function () {
    $day = gmmktime(0, 0, 0, 3, 21, 1990);
    $end = gmmktime(0, 0, 0, 3, 21, 2050);
    $prev = null;
    $n = 0;
    for (; $day < $end; $day += 86400) {
        $g = [(int) gmdate('Y', $day), (int) gmdate('n', $day), (int) gmdate('j', $day)];
        $j = Jalali::toJalali($g[0], $g[1], $g[2]);
        if (Jalali::toGregorian($j[0], $j[1], $j[2]) !== $g || !Jalali::isValid($j[0], $j[1], $j[2])) {
            throw new TestFailure('round trip failed for ' . implode('-', $g));
        }
        if ($prev !== null) {
            // consecutive days: same month +1 day, or the first day of the next month/year
            $next = $prev[2] < Jalali::monthLength($prev[0], $prev[1]) ? [$prev[0], $prev[1], $prev[2] + 1]
                : ($prev[1] < 12 ? [$prev[0], $prev[1] + 1, 1] : [$prev[0] + 1, 1, 1]);
            if ($next !== $j) {
                throw new TestFailure('calendar jumps after ' . implode('/', $prev));
            }
        }
        $prev = $j;
        $n++;
    }
    ok($n > 21900);
    TestState::$assertions += $n;
});

test('leap years and month lengths', function () {
    foreach ([1370, 1375, 1379, 1383, 1387, 1391, 1395, 1399, 1403, 1408] as $y) {
        ok(Jalali::isLeapYear($y), $y . ' should be a leap year');
    }
    foreach ([1400, 1401, 1402, 1404, 1405, 1406, 1407] as $y) {
        ok(!Jalali::isLeapYear($y), $y . ' should not be a leap year');
    }
    eq(31, Jalali::monthLength(1405, 6));
    eq(30, Jalali::monthLength(1405, 7));
    eq(29, Jalali::monthLength(1405, 12));
    eq(30, Jalali::monthLength(1403, 12));
});

test('parsing accepts Persian digits and rejects impossible dates', function () {
    eq([1405, 8, 20], Jalali::parse('۱۴۰۵/۰۸/۲۰'));
    eq([1405, 8, 5], Jalali::parse('1405-8-5'));
    eq([1405, 12, 29], Jalali::parse(' 1405.12.29 '));
    eq(null, Jalali::parse('1405/12/30'), '1405 is not a leap year');
    eq(null, Jalali::parse('1405/13/01'));
    eq(null, Jalali::parse('1405/07/31'));
    eq(null, Jalali::parse('05/08/20'));
    eq(null, Jalali::parse('2026-11-12 10:00'));
    eq('2026-12-06', Jalali::toGregorianString('۱۴۰۵/۰۹/۱۵'));
});

test('formatting uses Tehran time (+03:30)', function () {
    $ts = gmmktime(20, 45, 0, 11, 11, 2026); // 00:15 the next day in Tehran
    eq('1405/08/21 00:15', Jalali::format('Y/m/d H:i', $ts));
    eq('21 آبان 1405', Jalali::format('j F Y', $ts));
    eq('پنجشنبه', Jalali::format('l', $ts));
    eq('۱۴۰۵/۰۸/۲۱ ۰۰:۱۵', jdate('2026-11-11 20:45:00'));
    eq('—', jdate(null));
});

group('Display helpers');

test('numbers, money and sizes are formatted for Persian readers', function () {
    eq('۱۸.۵ میلیارد ریال', money(18.5));
    eq('۳۰ میلیارد ریال', money(30.0));
    eq('۱۴.۶۵۲ میلیارد ریال', money(14.652), 'amounts are shown as exactly as they are stored (three decimals)');
    eq('۴.۳۹۶ میلیارد ریال', money(4.3956));
    eq('۱٬۲۵۰.۵ میلیارد ریال', money(1250.5));
    eq('۱.۴۶۵ میلیارد تومان', toman_hint(14.652));
    eq('۶۵.۲ میلیون تومان', toman_hint(0.652));
    eq('۱٬۲۳۴٬۵۶۷.۸۹', nf(1234567.891));
    eq('۷۲.۰', nf(72, 1));
    eq('—', nf(null));
    eq('۱.۸۵ میلیارد تومان', toman_hint(18.5));
    eq('۸۰۰ میلیون تومان', toman_hint(8));
    eq('۲۰ مگابایت', filesize_h(20 * 1048576));
    eq('۱.۵ مگابایت', filesize_h(1.5 * 1048576));
    eq('۳۲۸ کیلوبایت', filesize_h(335644));
});

test('output escaping and JSON for inline scripts', function () {
    eq('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', e('<script>alert("x")</script>'));
    $json = json_out(['a' => '</script><b>&', 'ف' => 'فارسی']);
    lacks('</script>', $json);
    lacks('<', $json);
    contains('فارسی', $json);
    eq(['a' => '</script><b>&', 'ف' => 'فارسی'], json_decode($json, true));
    eq("خط ۱<br />\nخط &lt;۲&gt;", nl2p("خط ۱\nخط <۲>"));
});

test('download names keep Persian characters and cannot inject headers', function () {
    $h = content_disposition("کاربرگ طرح.docx");
    contains("filename*=UTF-8''" . rawurlencode('کاربرگ طرح.docx'), $h);
    contains('filename="file.docx"', $h, 'ASCII fallback');
    $h = content_disposition("a\"b\r\nX-Evil: 1.docx");
    lacks("\r", $h);
    lacks("\n", $h);
    lacks('"b', $h);
});

test('upload size limits written like php.ini are understood', function () {
    eq(2 * 1048576, ini_bytes('2M'));
    eq(1073741824, ini_bytes('1G'));
    eq(512 * 1024, ini_bytes('512k'));
    eq(1234, ini_bytes('1234'));
});
