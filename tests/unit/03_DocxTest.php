<?php

use Fava\Core\ZipLite;
use Fava\Docx\DocRenderer;
use Fava\Docx\DocxException;
use Fava\Docx\DocxReader;
use Fava\Docx\FieldExtractor;

function extract_file(string $path, string $name = ''): array
{
    return FieldExtractor::extract(DocxReader::read($path), $name !== '' ? $name : basename($path));
}

/** Run the callback once with ZipArchive (when available) and once with the built-in reader. */
function with_both_zip_readers(callable $fn): void
{
    $modes = class_exists('ZipArchive') ? [false, true] : [true];
    try {
        foreach ($modes as $pure) {
            ZipLite::$forcePurePhp = $pure;
            $fn($pure ? 'built-in zip reader' : 'ZipArchive');
        }
    } finally {
        ZipLite::$forcePurePhp = false;
    }
}

group('Reading the applicant worksheet (.docx)');

test('sample 1: title, company, expected output and the supporting facts', function () {
    with_both_zip_readers(function (string $mode) {
        $r = extract_file(fixture('sample-proposal-1.docx'));
        $f = $r['fields'];
        eq('ok', $r['status'], $mode);
        eq('طراحی و ساخت سامانه هوشمند پایش و بهینه‌سازی مصرف انرژی ساختمان‌های اداری', $f['title'], $mode);
        eq('شرکت داده‌پردازان نوآور البرز', $f['company']);
        contains('سامانه نرم‌افزاری تحت وب', $f['expected_output']);
        contains('گزارش صرفه‌جویی ماهانه', $f['expected_output']);
        eq('1405/07/12', $f['filled_date']);
        eq(6, $f['trl_declared']);
        eq(['ارتقاء فناوری محصول موجود'], $f['request_type'], 'the ticked option, not all options');
        eq('سارا نیک‌منش', $f['ceo_name']);
        eq('09120000001', $f['ceo_phone']);
        eq(10, count($f['company_facts']));
        eq('10101234567', array_column($f['company_facts'], 'value', 'key')['national_id']);
        eq(4, $f['team_count']);
        eq('بهنام فراهانی', $f['team']['rows'][1][1]);
        eq(3, count($f['outputs']));
        eq('18.500.000.000', $f['cost_total_raw']);
        near(18500000000.0, $f['cost_total']);
        eq([18.5, 185.0], array_map('floatval', array_column($f['amount_suggestions'], 'value')), 'the unit in the table is ambiguous: both readings are offered');
        contains('کاربرگ درخواست اعتبار حمایتی', $r['doc_type']);
    });
});

test('sample 1: two different titles in the file are reported to the admin', function () {
    $r = extract_file(fixture('sample-proposal-1.docx'));
    eq('سامانه هوشمند پایش مصرف انرژی ساختمان', $r['fields']['title_alt']);
    eq(1, count($r['warnings']));
    contains('دو عنوان متفاوت', $r['warnings'][0]);
});

test('sample 2: labels with Arabic letters, total in million toman', function () {
    $r = extract_file(fixture('sample-proposal-2.docx'));
    $f = $r['fields'];
    eq('ok', $r['status']);
    eq('توسعه و تولید نیمه‌صنعتی کیت تشخیص سریع آلودگی میکروبی آب', $f['title']);
    eq('زیست‌فناوران سبز خزر (سهامی خاص)', $f['company']);
    eq('کیت تشخیص 20 دقیقه‌ای به همراه دستگاه خوانشگر قابل حمل', $f['expected_output']);
    eq(4, $f['trl_declared']);
    eq('دکتر مریم حسین‌زاده', $f['ceo_name']);
    eq('میلیون تومان', $f['cost_unit']);
    eq([24.5], array_map('floatval', array_column($f['amount_suggestions'], 'value')), '2,450 million toman = 24.5 billion rial');
});

test('sample 3: sparse worksheet, total in rial', function () {
    $r = extract_file(fixture('sample-proposal-3.docx'));
    $f = $r['fields'];
    eq('ok', $r['status']);
    eq([], $r['warnings']);
    eq('پلتفرم آموزش مجازی مهارت‌های فنی', $f['title']);
    eq('گروه فناوری آوای کویر', $f['company']);
    eq('اپلیکیشن موبایل آموزش مهارت؛ پنل مدرسان', $f['expected_output']);
    eq(3, $f['trl_declared']);
    eq('ریال', $f['cost_unit']);
    near(6.5, (float) $f['amount_suggestions'][0]['value']);
});

test('an older judging sheet is still read as far as possible', function () {
    $r = extract_file(fixture('old-judging-sheet.docx'));
    eq('ok', $r['status']);
    eq('دستگاه خشک‌کن خورشیدی محصولات کشاورزی', $r['fields']['title']);
    eq('صنایع نوین دشت مغان', $r['fields']['company']);
    contains('خشک‌کن', $r['fields']['expected_output']);
    contains('کاربرگ داوری', $r['doc_type']);
});

test('an empty worksheet is accepted but marked for manual entry', function () {
    $r = extract_file(fixture('empty.docx'));
    eq('failed', $r['status']);
    eq(3, count($r['warnings']));
    eq('', $r['fields']['title']);
    eq(null, $r['fields']['trl_declared']);
    eq([], $r['fields']['amount_suggestions']);
});

test('the company name is taken from the file name as a last resort', function () {
    $path = docx_with_body(wp('عنوان طرح: سامانه نمونه') . wp('متنی بدون نام شرکت'));
    $r = extract_file($path, 'کاربرگ درخواست - شرکت نمونه‌پرداز.docx');
    eq('سامانه نمونه', $r['fields']['title']);
    ok($r['fields']['company'] === '' || mb_strpos($r['fields']['company'], 'نمونه‌پرداز') !== false, 'company: ' . $r['fields']['company']);
    eq('partial', $r['status']);
});

test('label and value in neighbouring table cells or in one paragraph', function () {
    $path = docx_with_body(
        wtbl([['نام شرکت/ شتاب‌دهنده:', 'فناوران آزمون'], ['شناسه ملی:', '۱۴۰۰۱۲۳۴۵۶۷']])
        . wp('عنوان طرح: پایش هوشمند')
        . wp('خروجی اصلی پروژه: نرم‌افزار پایش')
    );
    $f = extract_file($path)['fields'];
    eq('فناوران آزمون', $f['company']);
    eq('پایش هوشمند', $f['title']);
    eq('نرم‌افزار پایش', $f['expected_output']);
});

group('Refusing files that are not safe or not Word documents');

test('a file that is not a zip archive', function () {
    with_both_zip_readers(function (string $mode) {
        $e = throws(function () {
            DocxReader::read(fixture('not-a-docx.docx'));
        }, DocxException::class, $mode);
        contains('معتبر نیست', $e->getMessage());
    });
});

test('a zip archive that is not a Word document', function () {
    $path = tmp_dir() . '/x.docx';
    file_put_contents($path, zip_build(['readme.txt' => 'hello']));
    with_both_zip_readers(function (string $mode) use ($path) {
        $e = throws(function () use ($path) {
            DocxReader::read($path);
        }, DocxException::class, $mode);
        contains('بخش اصلی سند', $e->getMessage());
    });
});

test('XML with a DTD / entities (XXE, billion laughs) is refused', function () {
    with_both_zip_readers(function (string $mode) {
        $e = throws(function () {
            DocxReader::read(fixture('evil-doctype.docx'));
        }, DocxException::class, $mode);
        contains('DTD', $e->getMessage());
    });
    $secret = tmp_dir() . '/secret.txt';
    file_put_contents($secret, 'TOP-SECRET');
    $xml = '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY x SYSTEM "file://' . $secret . '">]>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>&x;</w:t></w:r></w:p></w:body></w:document>';
    $path = docx_with_body('', ['word/document.xml' => $xml]);
    throws(function () use ($path) {
        DocxReader::read($path);
    }, DocxException::class);
});

test('broken XML gives a clear message instead of a crash', function () {
    $path = docx_with_body('', ['word/document.xml' => '<w:document xmlns:w="x"><w:body><w:p>']);
    $e = throws(function () use ($path) {
        DocxReader::read($path);
    }, DocxException::class);
    contains('XML', $e->getMessage());
});

test('a zip bomb (60 MB part compressed to a few KB) is refused', function () {
    if (!function_exists('gzdeflate')) {
        skip('zlib is not available');
    }
    $big = '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>'
        . str_repeat('A', 60 * 1048576) . '</w:t></w:r></w:p></w:body></w:document>';
    $path = docx_with_body('', ['word/document.xml' => $big], true);
    unset($big);
    ok(filesize($path) < 1048576, 'the test file itself is small: ' . filesize($path));
    with_both_zip_readers(function (string $mode) use ($path) {
        $before = memory_get_usage();
        $e = throws(function () use ($path) {
            DocxReader::read($path);
        }, DocxException::class, $mode);
        contains('بزرگ', $e->getMessage(), $mode);
        ok(memory_get_usage() - $before < 16 * 1048576, $mode . ': the part must not be kept in memory');
    });
});

test('a document with tens of thousands of paragraphs is cut off, not read endlessly', function () {
    $path = docx_with_body(str_repeat(wp('خط'), 20000), [], function_exists('gzdeflate'));
    $doc = DocxReader::read($path);
    eq(1, $doc['meta']['truncated']);
    ok(count($doc['blocks']) <= 8000, 'blocks: ' . count($doc['blocks']));
    contains('ادامه سند', DocRenderer::html($doc));
});

test('absurdly nested tables do not exhaust the stack', function () {
    $x = wp('درون');
    for ($i = 0; $i < 60; $i++) {
        $x = '<w:tbl><w:tr><w:tc>' . $x . '</w:tc></w:tr></w:tbl>';
    }
    $doc = DocxReader::read(docx_with_body($x));
    $depth = 0;
    $b = $doc['blocks'];
    while ($b && ($b[0]['t'] ?? '') === 'tbl') {
        $depth++;
        $b = $b[0]['rows'][0]['c'][0]['b'] ?? [];
    }
    ok($depth >= 1 && $depth <= 13, 'nesting kept: ' . $depth);
    ok(strlen(DocRenderer::html($doc)) > 0);
});

group('Showing the worksheet in the browser');

test('text from the document is always escaped', function () {
    $evil = '<script>alert(1)</script><img src=x onerror=alert(2)> "quoted" & \'single\'';
    $path = docx_with_body(wp($evil) . wtbl([[$evil, 'عنوان طرح: ' . $evil]]));
    $doc = DocxReader::read($path);
    $html = DocRenderer::html($doc);
    lacks('<script', $html);
    lacks('<img', $html);
    contains('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    contains('<table', $html);
    // only tags the renderer itself writes may appear
    preg_match_all('/<\/?([a-zA-Z0-9]+)/', $html, $m);
    $unexpected = array_diff(array_unique(array_map('strtolower', $m[1])), ['div', 'p', 'b', 'mark', 'span', 'table', 'tbody', 'thead', 'tr', 'td', 'th', 'h3', 'h4', 'h5', 'br', 'colgroup', 'col']);
    eq([], array_values($unexpected), 'unexpected tags in the rendered worksheet');
    lacks(' on', preg_replace('/>[^<]*</', '><', $html), 'no event-handler attributes');
});

test('the real structure survives: headings, tables, merged cells, check boxes', function () {
    $doc = DocxReader::read(fixture('sample-proposal-1.docx'));
    eq(0, $doc['meta']['truncated']);
    ok($doc['meta']['tables'] >= 10, 'tables: ' . $doc['meta']['tables']);
    contains('کاربرگ درخواست اعتبار حمایتی', $doc['meta']['header']);
    $html = DocRenderer::html($doc);
    contains('<div class="docx" dir="rtl">', $html);
    contains('<table', $html);
    contains('colspan=', $html);
    contains('dx-cb', $html, 'check boxes are drawn');
    contains('داده‌پردازان نوآور البرز', $html);
    lacks('class="dx-li dx-l0dx-b"', $html);
    eq(substr_count($html, '<table'), substr_count($html, '</table>'));
    eq(substr_count($html, '<td') + substr_count($html, '<th') - substr_count($html, '<thead') , substr_count($html, '</td>') + substr_count($html, '</th>'));
});

test('pictures and charts cannot be shown, so the reader is told they exist', function () {
    $ns = ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
        . ' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
        . ' xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:v="urn:schemas-microsoft-com:vml"'
        . ' xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape"';
    $picture = '<w:p><w:r><w:drawing' . $ns . '><wp:inline><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        . '<pic:pic><pic:blipFill><a:blip r:embed="rId5"/></pic:blipFill></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
    $chart = '<w:p><w:r><w:drawing' . $ns . '><wp:inline><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart">'
        . '<c:chart r:id="rId6"/></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
    $oldPicture = '<w:p><w:r><w:pict' . $ns . '><v:shape><v:imagedata r:id="rId7"/></v:shape></w:pict></w:r></w:p>';
    $textBox = '<w:p><w:r><w:drawing' . $ns . '><wp:anchor><a:graphic><a:graphicData uri="http://schemas.microsoft.com/office/word/2010/wordprocessingShape">'
        . '<wps:wsp><wps:txbx><w:txbxContent>' . wp('متن داخل کادر') . '</w:txbxContent></wps:txbx></wps:wsp></a:graphicData></a:graphic></wp:anchor></w:drawing></w:r></w:p>';

    $doc = DocxReader::read(docx_with_body(wp('عنوان طرح: طرح مصور') . $picture . wp('شرح نمودار') . $chart . $oldPicture . $textBox));
    eq(3, $doc['meta']['images'], 'a picture, a chart and an old-style picture; the text box is not one');
    $html = DocRenderer::html($doc);
    contains('این کاربرگ ۳ تصویر یا نمودار دارد', $html);
    ok(strpos($html, 'تصویر یا نمودار') < strpos($html, 'عنوان طرح'), 'the note comes first');
    contains('متن داخل کادر', $html, 'text inside a shape is still read');

    $plain = DocxReader::read(docx_with_body(wp('فقط متن') . $textBox));
    eq(0, $plain['meta']['images']);
    lacks('تصویر یا نمودار', DocRenderer::html($plain));
    lacks('تصویر یا نمودار', DocRenderer::html(['blocks' => $plain['blocks'], 'meta' => []]), 'documents stored before this note existed still render');
});

test('an empty document renders a friendly note', function () {
    contains('متنی برای نمایش وجود ندارد', DocRenderer::html(['blocks' => [], 'meta' => []]));
    contains('متنی برای نمایش وجود ندارد', DocRenderer::html([]));
});

test('reading is fast enough for shared hosting', function () {
    $start = microtime(true);
    for ($i = 0; $i < 5; $i++) {
        extract_file(fixture('sample-proposal-1.docx'));
    }
    $ms = (microtime(true) - $start) * 1000 / 5;
    ok($ms < 1500, sprintf('one worksheet took %.0f ms', $ms));
});

test('over-long values are shortened so they always fit the database', function () {
    $long = str_repeat('بسیار طولانی ', 6000); // ~78,000 characters
    $path = docx_with_body(
        wtbl([['نام شرکت/ شتاب‌دهنده:', $long]])
        . wp('عنوان طرح: ' . $long)
        . wp('خروجی اصلی پروژه: ' . $long)
    );
    $f = extract_file($path)['fields'];
    ok(mb_strlen($f['title']) <= 500, 'title: ' . mb_strlen($f['title']));
    ok(mb_strlen($f['company']) <= 250, 'company: ' . mb_strlen($f['company']));
    ok(mb_strlen($f['expected_output']) <= 5000, 'output: ' . mb_strlen($f['expected_output']));
    eq('…', mb_substr($f['title'], -1));
    ok(strlen(json_encode($f, JSON_UNESCAPED_UNICODE)) < 200000);
});
