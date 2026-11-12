<?php

namespace Fava\Docx;

use Fava\Core\Fa;

/**
 * Finds company and proposal data in a parsed worksheet.
 *
 * The extractor does not depend on positions in the file. It collects every
 * "label: value" pair it can see (two-column table rows, cells and paragraphs that
 * start with a label and a colon) and then looks the labels up by their normalised
 * text, so small differences in spacing, half-spaces or Arabic/Persian letters do
 * not matter. Whatever cannot be found is reported and left for the admin to fill in.
 */
class FieldExtractor
{
    /** label patterns (on Fa::key of the label) in priority order */
    private const LABELS = [
        'company' => ['/^نامشرکتشتابدهنده/u', '/^نامشرکت(یا)?تیم/u', '/^نامشرکتمتقاضی/u', '/^نامشرکت$/u', '/^ناممتقاضی/u', '/^نامشرکت/u', '/^نامتیم/u'],
        'title' => ['/^عنوانکاملطرح/u', '/^عنوانطرح$/u', '/^عنوانطرحفناوری/u', '/^عنوانطرح/u', '/^عنوانپروژه/u', '/^عنوانایده/u', '/^نامطرح/u'],
        'expected_output' => ['/^خروجیاصلیموردانتظار/u', '/^خروجیاصلی/u', '/^خروجیموردانتظار/u', '/^خروجینهایی/u'],
        'product_name' => ['/^ناممحصول/u'],
        'filled_date' => ['/^تاریختکمیلکاربرگ/u', '/^تاریختکمیل/u'],
        'national_id' => ['/^شناسهملی/u'],
        'kb_status' => ['/^وضعیتدانشبنیان/u'],
        'established' => ['/^تاریختاسیس/u'],
        'website' => ['/^وبسایت/u', '/^سایترسمی/u'],
        'staff' => ['/^تعدادکارکنان/u'],
        'tech_center' => ['/^ناممرکزفناوری/u', '/^مرکزفناوری/u'],
        'residency' => ['/^مدتزماناستقرار/u', '/^مدتاستقرار/u'],
        'prior_funding' => ['/تسهیلاتگرنتدریافتی/u', '/^رقمریالتسهیلات/u'],
        'ceo' => ['/^ناممدیرعامل/u', '/^مدیرعامل/u'],
        'trl' => ['/سطحبلوغفناوری/u', '/سطحامادگیفناوری/u'],
        'request_type' => ['/^درخواستیادستاوردطرح/u', '/^طرحپیشنهادیبرای/u', '/^طرحدرخواستیدرارتباطبا/u'],
        'idea_summary' => ['/^شرحمختصرایده/u', '/^خلاصهطرح/u', '/^شرحمختصرطرح/u'],
    ];

    /** display labels for the company fact sheet */
    private const COMPANY_FACTS = [
        'national_id' => 'شناسه ملی',
        'kb_status' => 'وضعیت دانش‌بنیانی',
        'established' => 'تاریخ تأسیس',
        'website' => 'وب‌سایت',
        'staff' => 'کارکنان و تیم تحقیق و توسعه',
        'tech_center' => 'مرکز فناوری محل استقرار',
        'residency' => 'مدت استقرار در مرکز',
        'prior_funding' => 'تسهیلات/گرنت دریافتی برای این طرح',
    ];

    private const CHECKED = '☑☒✓✔✅■▪◼⬛●◉⊠⊗✗✘✕✖×√\*＊';
    private const UNCHECKED = '☐□▢◻⬜○◯❏❑❒▫';

    /** TRL level by characteristic words of each level (keys are Fa::key forms) */
    private const TRL_WORDS = [
        'مشاهدهاصولپایه' => 1, 'اصولپایه' => 1,
        'تدوینمفهوم' => 2,
        'اثباتمفهوم' => 3,
        'نمونهازمایشگاهی' => 4,
        'شبهعملیاتی' => 5,
        'مهندسیشده' => 6, 'نمونهمهندسی' => 6,
        'محیطعملیاتی' => 7,
        'امادهسازیبرایتولید' => 8, 'امادهسازیتولید' => 8,
        'استفادهکامل' => 9,
    ];

    /** @var array[] collected label/value candidates */
    private $pairs = [];
    /** @var array[] top-level tables with the text that introduced them */
    private $tables = [];

    public static function extract(array $doc, string $originalName = ''): array
    {
        return (new self())->run($doc, $originalName);
    }

    private function run(array $doc, string $originalName): array
    {
        $blocks = $doc['blocks'] ?? [];
        $this->collect($blocks, '');

        $f = [];
        $warnings = [];

        // --- the three fields shown on the assessment form -------------------
        $titles = $this->values('title');
        $f['title'] = $titles[0] ?? '';
        $f['title_alt'] = '';
        foreach ($titles as $t) {
            if (Fa::key($t) !== Fa::key($f['title'])) {
                $f['title_alt'] = $t;
                break;
            }
        }
        $companies = $this->values('company');
        $f['company'] = $companies[0] ?? '';
        $f['company_alt'] = '';
        foreach ($companies as $c) {
            if (Fa::key($c) !== Fa::key($f['company'])) {
                $f['company_alt'] = $c;
                break;
            }
        }
        $outputs = $this->values('expected_output');
        $f['expected_output'] = isset($outputs[0]) ? self::tidyOutput($outputs[0]) : '';

        // --- company facts ------------------------------------------------------
        $f['product_name'] = $this->value('product_name');
        $f['filled_date'] = $this->value('filled_date');
        $facts = [];
        foreach (self::COMPANY_FACTS as $key => $label) {
            $v = $this->value($key);
            if ($v !== '' && !self::isPlaceholder($v)) {
                $facts[] = ['key' => $key, 'label' => $label, 'value' => $v];
            }
        }
        $ceo = $this->value('ceo');
        $f['ceo_name'] = '';
        $f['ceo_phone'] = '';
        if ($ceo !== '') {
            list($f['ceo_name'], $f['ceo_phone']) = self::splitPhone($ceo);
            if ($f['ceo_name'] !== '') {
                $facts[] = ['key' => 'ceo_name', 'label' => 'مدیرعامل', 'value' => $f['ceo_name']];
            }
            if ($f['ceo_phone'] !== '') {
                $facts[] = ['key' => 'ceo_phone', 'label' => 'شماره تماس', 'value' => $f['ceo_phone']];
            }
        }
        $f['company_facts'] = $facts;

        // --- proposal attributes -------------------------------------------------
        $f['trl_declared'] = null;
        $f['trl_text'] = '';
        $trlPair = $this->pair('trl');
        if ($trlPair) {
            list($f['trl_declared'], $f['trl_text']) = self::detectTrl($trlPair['paras']);
        }
        $f['request_type'] = [];
        $reqPair = $this->pair('request_type');
        if ($reqPair) {
            $f['request_type'] = self::selectedOptions($reqPair['paras']);
        }
        $f['idea_summary'] = mb_substr($this->value('idea_summary', true), 0, 4000);

        // --- sections that are tables ---------------------------------------------
        $f['outputs'] = [];
        $f['challenges'] = '';
        $f['team'] = [];
        $f['team_count'] = 0;
        $f['cost_total_raw'] = '';
        $f['cost_total'] = null;
        $f['cost_unit'] = '';
        $f['amount_suggestions'] = [];
        $this->readSections($f);

        if ($f['expected_output'] === '' && $f['outputs']) {
            $f['expected_output'] = implode('؛ ', array_slice($f['outputs'], 0, 6));
            $f['expected_output_from_list'] = 1;
        }

        // --- document type ----------------------------------------------------------
        $docType = self::docType($doc);

        // --- fall-backs and warnings ---------------------------------------------
        $found = 0;
        if ($f['title'] !== '') {
            $found++;
        } else {
            $warnings[] = 'عنوان طرح در فایل پیدا نشد؛ آن را دستی وارد کنید.';
        }
        if ($f['company'] !== '') {
            $found++;
        } else {
            // only worth guessing when the file is clearly a worksheet whose company cell was left blank
            $guess = $f['title'] !== '' ? self::companyFromFilename($originalName) : '';
            if ($guess !== '') {
                $f['company'] = $guess;
                $f['company_from_filename'] = 1;
                $warnings[] = 'نام شرکت در متن فایل خالی بود و از نام فایل برداشته شد؛ آن را بررسی کنید.';
            } else {
                $warnings[] = 'نام شرکت در فایل پیدا نشد؛ آن را دستی وارد کنید.';
            }
        }
        if ($f['expected_output'] !== '') {
            $found++;
        } else {
            $warnings[] = 'خروجی اصلی مورد انتظار طرح در فایل پیدا نشد؛ آن را دستی وارد کنید.';
        }
        if ($f['title_alt'] !== '') {
            $warnings[] = 'در کاربرگ دو عنوان متفاوت برای طرح نوشته شده است؛ عنوان درست را انتخاب کنید.';
        }
        if (!empty($doc['meta']['truncated'])) {
            $warnings[] = 'سند بسیار طولانی بود و فقط بخش ابتدایی آن خوانده شد.';
        }

        return [
            'fields' => self::limit($f),
            'warnings' => $warnings,
            'status' => $found === 3 ? 'ok' : ($found === 0 ? 'failed' : 'partial'),
            'doc_type' => $docType,
        ];
    }

    /**
     * Keep every extracted value to a sane size. A worksheet is a few pages; anything
     * larger is a mis-detected value and must not overflow a database column.
     */
    private static function limit(array $f): array
    {
        $cut = function ($v, int $max): string {
            $v = (string) $v;
            return mb_strlen($v) > $max ? rtrim(mb_substr($v, 0, $max - 1)) . '…' : $v;
        };
        foreach (['title' => 500, 'title_alt' => 500, 'company' => 250, 'company_alt' => 250, 'expected_output' => 5000,
            'product_name' => 300, 'filled_date' => 40, 'ceo_name' => 150, 'ceo_phone' => 40, 'trl_text' => 500,
            'cost_total_raw' => 60, 'cost_unit' => 40] as $key => $max) {
            if (isset($f[$key])) {
                $f[$key] = $cut($f[$key], $max);
            }
        }
        $f['company_facts'] = array_slice($f['company_facts'] ?? [], 0, 30);
        foreach ($f['company_facts'] as &$fact) {
            $fact['value'] = $cut($fact['value'], 500);
        }
        unset($fact);
        foreach (['request_type' => [12, 300], 'outputs' => [30, 1000]] as $key => $lim) {
            $list = [];
            foreach (array_slice($f[$key] ?? [], 0, $lim[0]) as $item) {
                $list[] = $cut($item, $lim[1]);
            }
            $f[$key] = $list;
        }
        if (!empty($f['team']['rows'])) {
            $rows = [];
            foreach (array_slice($f['team']['rows'], 0, 60) as $row) {
                $cells = [];
                foreach (array_slice((array) $row, 0, 16) as $cell) {
                    $cells[] = $cut($cell, 300);
                }
                $rows[] = $cells;
            }
            $f['team']['rows'] = $rows;
            $headers = [];
            foreach (array_slice((array) ($f['team']['headers'] ?? []), 0, 16) as $h) {
                $headers[] = $cut($h, 200);
            }
            $f['team']['headers'] = $headers;
        }
        return $f;
    }

    // ---- collecting label/value pairs -----------------------------------------

    private function collect(array $blocks, string $context): void
    {
        $lastText = $context;
        foreach ($blocks as $b) {
            $type = $b['t'] ?? '';
            if ($type === 'p') {
                $lastText = $b['x'];
                $split = self::splitLabel($b['x']);
                if ($split) {
                    $this->pairs[] = [
                        'key' => Fa::key($split[0]),
                        'label' => $split[0],
                        'paras' => $split[1] === '' ? [] : [['x' => $split[1], 'h' => !empty($b['h'])]],
                        'src' => 'p',
                    ];
                }
            } elseif ($type === 'tbl') {
                $this->tables[] = ['heading' => $lastText, 'rows' => $b['rows']];
                foreach ($b['rows'] as $row) {
                    $cells = [];
                    foreach ($row['c'] as $cell) {
                        if (empty($cell['skip'])) {
                            $cells[] = $cell;
                        }
                    }
                    // label in one cell, value in the cell next to it
                    for ($i = 0; $i + 1 < count($cells); $i++) {
                        $labelOnly = Fa::trim(Fa::clean(self::cellText($cells[$i])), ':：');
                        if ($labelOnly !== '' && mb_strlen($labelOnly) <= 160 && preg_match('/\p{L}{2}/u', $labelOnly)) {
                            $this->pairs[] = [
                                'key' => Fa::key($labelOnly),
                                'label' => $labelOnly,
                                'paras' => self::cellParas($cells[$i + 1]),
                                'src' => 'row',
                            ];
                        }
                    }
                    // label and value inside the same cell
                    foreach ($cells as $cell) {
                        $paras = self::cellParas($cell);
                        if (!$paras) {
                            continue;
                        }
                        $split = self::splitLabel($paras[0]['x']);
                        if ($split) {
                            $rest = array_slice($paras, 1);
                            if ($split[1] !== '') {
                                array_unshift($rest, ['x' => $split[1], 'h' => $paras[0]['h']]);
                            }
                            $this->pairs[] = [
                                'key' => Fa::key($split[0]),
                                'label' => $split[0],
                                'paras' => $rest,
                                'src' => 'cell',
                            ];
                        }
                        // nested tables
                        foreach ($cell['b'] as $inner) {
                            if (($inner['t'] ?? '') === 'tbl') {
                                $this->collect([$inner], $lastText);
                            }
                        }
                    }
                }
            }
        }
    }

    /** "label: value" -> [label, value]; null when the text does not start with a short label. */
    private static function splitLabel(string $text): ?array
    {
        $text = trim(str_replace(["\t", "\u{00A0}"], ' ', $text));
        if (!preg_match('/^([^:：\n]{2,150})[:：](.*)$/us', $text, $m)) {
            return null;
        }
        $label = Fa::clean($m[1]);
        if ($label === '' || !preg_match('/\p{L}{2}/u', $label)) {
            return null;
        }
        return [$label, trim($m[2])];
    }

    /** @return array[] paragraphs of a cell as [['x' => text, 'h' => highlighted], ...] (nested tables flattened) */
    private static function cellParas(array $cell): array
    {
        $out = [];
        foreach ($cell['b'] as $b) {
            if (($b['t'] ?? '') === 'p') {
                $out[] = ['x' => $b['x'], 'h' => !empty($b['h'])];
            } elseif (($b['t'] ?? '') === 'tbl') {
                foreach (explode("\n", DocxReader::text([$b])) as $line) {
                    if (trim($line) !== '') {
                        $out[] = ['x' => str_replace("\t", ' ', $line), 'h' => false];
                    }
                }
            }
        }
        return $out;
    }

    private static function cellText(array $cell): string
    {
        $lines = [];
        foreach (self::cellParas($cell) as $p) {
            $lines[] = $p['x'];
        }
        return implode("\n", $lines);
    }

    /** First pair whose label matches the field (by pattern priority, then document order) and has a value. */
    private function pair(string $field): ?array
    {
        foreach (self::LABELS[$field] as $pattern) {
            foreach ($this->pairs as $p) {
                if ($p['paras'] && preg_match($pattern, $p['key']) && trim(self::joinParas($p['paras'])) !== '') {
                    return $p;
                }
            }
        }
        return null;
    }

    /** All distinct, non-empty values for a field in priority order. */
    private function values(string $field, bool $keepLines = false): array
    {
        $out = [];
        $seen = [];
        foreach (self::LABELS[$field] as $pattern) {
            foreach ($this->pairs as $p) {
                if (!preg_match($pattern, $p['key'])) {
                    continue;
                }
                $v = Fa::trim(Fa::clean(self::joinParas($p['paras']), $keepLines), '\-–—:：');
                if ($v === '' || self::isPlaceholder($v)) {
                    continue;
                }
                $k = Fa::key($v);
                if (isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                $out[] = $v;
            }
        }
        return $out;
    }

    private function value(string $field, bool $keepLines = false): string
    {
        $v = $this->values($field, $keepLines);
        return $v[0] ?? '';
    }

    private static function joinParas(array $paras): string
    {
        $lines = [];
        foreach ($paras as $p) {
            $lines[] = $p['x'];
        }
        return implode("\n", $lines);
    }

    /** Values people type to mean "nothing": ---, ؟؟؟, ندارد is kept (it is information). */
    private static function isPlaceholder(string $v): bool
    {
        return (bool) preg_match('/^[\s\-–—_.?؟…\x{200C}]+$/u', $v);
    }

    /** «خروجی اصلی : پروژه: …» is how the label is often split; drop the stray word. */
    private static function tidyOutput(string $v): string
    {
        $v = (string) preg_replace('/^(پروژه|طرح)\s*[:：]\s*/u', '', $v);
        return trim($v);
    }

    /** "امید نوروزی 09123456789" -> [name, phone] */
    private static function splitPhone(string $value): array
    {
        $latin = Fa::latin($value);
        $phone = '';
        if (preg_match('/(?:\+98|0098|0)?\s?9\d{2}[\s\-]?\d{3}[\s\-]?\d{4}/', $latin, $m, PREG_OFFSET_CAPTURE)) {
            $phone = preg_replace('/[\s\-]/', '', $m[0][0]);
            if (strpos($phone, '9') === 0) {
                $phone = '0' . $phone;
            }
            $latin = substr($latin, 0, $m[0][1]) . ' ' . substr($latin, $m[0][1] + strlen($m[0][0]));
        } elseif (preg_match('/0\d{2,3}[\s\-]?\d{7,8}/', $latin, $m, PREG_OFFSET_CAPTURE)) {
            $phone = preg_replace('/[\s\-]/', '', $m[0][0]);
            $latin = substr($latin, 0, $m[0][1]) . ' ' . substr($latin, $m[0][1] + strlen($m[0][0]));
        }
        $name = Fa::trim(Fa::clean($latin), '\-–—،,\/|:：');
        return [$name, $phone];
    }

    // ---- check-box style options --------------------------------------------

    /**
     * Split text that mixes options and check marks into options.
     * @return array[] [['text' => string, 'state' => 'checked'|'unchecked'|'none'], ...]
     */
    public static function splitOptions(string $text): array
    {
        $text = str_replace(["\t", "\u{00A0}"], ' ', $text);
        // [x] / [ ] / ( ) forms
        $text = (string) preg_replace('/[\[(]\s*[xX✓✔*×]\s*[\])]/u', '☑', $text);
        $text = (string) preg_replace('/[\[(]\s*[\])]/u', '☐', $text);
        $re = '/([' . self::CHECKED . self::UNCHECKED . '])/u';
        $tokens = preg_split($re, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $items = [];
        foreach ($tokens as $tok) {
            if ($tok === '') {
                continue;
            }
            if (preg_match('/^[' . self::CHECKED . ']$/u', $tok)) {
                $items[] = ['m', 'checked'];
            } elseif (preg_match('/^[' . self::UNCHECKED . ']$/u', $tok)) {
                $items[] = ['m', 'unchecked'];
            } else {
                $clean = Fa::trim(Fa::clean($tok), '.،;؛:：\-–—');
                if ($clean !== '' && preg_match('/[\p{L}\p{N}]/u', $clean)) {
                    $items[] = ['t', $clean];
                }
            }
        }
        if (!$items) {
            return [];
        }
        $options = [];
        $n = count($items);
        $markerFirst = $items[0][0] === 'm';
        for ($i = 0; $i < $n; $i++) {
            if ($items[$i][0] !== 't') {
                continue;
            }
            $state = 'none';
            if ($markerFirst) {
                if ($i > 0 && $items[$i - 1][0] === 'm') {
                    $state = $items[$i - 1][1];
                }
            } elseif ($i + 1 < $n && $items[$i + 1][0] === 'm') {
                $state = $items[$i + 1][1];
            }
            $options[] = ['text' => $items[$i][1], 'state' => $state];
        }
        return $options;
    }

    /**
     * Which options are chosen. People mark choices in many ways: a tick, a filled box,
     * an asterisk instead of the empty box, deleting the box, or highlighting the line.
     * @param array[] $paras [['x' => text, 'h' => highlighted], ...]
     * @return array[] the chosen options (each ['text' => ..., 'state' => ...])
     */
    private static function chosen(array $paras): array
    {
        $options = [];
        foreach ($paras as $p) {
            foreach (preg_split('/\n/u', $p['x']) as $line) {
                foreach (self::splitOptions($line) as $o) {
                    $o['h'] = !empty($p['h']);
                    $options[] = $o;
                }
            }
        }
        if (!$options) {
            return [];
        }
        $by = ['checked' => [], 'unchecked' => [], 'none' => []];
        foreach ($options as $o) {
            $by[$o['state']][] = $o;
        }
        if ($by['checked']) {
            return $by['checked'];
        }
        // every option still has its empty box except one: that one was chosen
        if (count($by['unchecked']) >= 2 && count($by['none']) === 1) {
            return $by['none'];
        }
        $highlighted = array_values(array_filter($options, function ($o) {
            return $o['h'];
        }));
        if (count($highlighted) === 1 && count($options) > 1) {
            return $highlighted;
        }
        return [];
    }

    /** @return string[] */
    private static function selectedOptions(array $paras): array
    {
        $out = [];
        foreach (self::chosen($paras) as $o) {
            $out[] = $o['text'];
        }
        return array_values(array_unique($out));
    }

    /** @return array{0:?int,1:string} [level, description] */
    private static function detectTrl(array $paras): array
    {
        $best = null;
        $bestText = '';
        foreach (self::chosen($paras) as $o) {
            $level = self::trlLevel($o['text']);
            if ($level !== null && ($best === null || $level > $best)) {
                $best = $level;
                $bestText = $o['text'];
            }
        }
        return [$best, $bestText];
    }

    private static function trlLevel(string $text): ?int
    {
        $latin = Fa::latin($text);
        if (preg_match('/(?<![0-9])([1-9])\s*TRL|TRL\s*[:：\-]?\s*([1-9])(?![0-9])/iu', $latin, $m)) {
            return (int) ($m[1] !== '' ? $m[1] : $m[2]);
        }
        $key = Fa::key($text);
        foreach (self::TRL_WORDS as $word => $level) {
            if (mb_strpos($key, $word) !== false) {
                return $level;
            }
        }
        return null;
    }

    // ---- table sections ----------------------------------------------------------

    private function readSections(array &$f): void
    {
        foreach ($this->tables as $t) {
            $rows = self::plainRows($t['rows']);
            if (!$rows) {
                continue;
            }
            $hkey = Fa::key($t['heading']);
            $firstKey = Fa::key(implode(' ', $rows[0]));

            if (!$f['team'] && (mb_strpos($hkey, 'مشخصاتتیم') !== false || mb_strpos($firstKey, 'ناماعضا') !== false)) {
                $headers = $rows[0];
                $members = [];
                foreach (array_slice($rows, 1) as $r) {
                    $nonEmpty = 0;
                    foreach (array_slice($r, 1) as $v) {
                        if ($v !== '' && !self::isPlaceholder($v)) {
                            $nonEmpty++;
                        }
                    }
                    if ($nonEmpty >= 1) {
                        $members[] = $r;
                    }
                }
                $f['team'] = ['headers' => $headers, 'rows' => array_slice($members, 0, 40)];
                $f['team_count'] = count($members);
                continue;
            }
            if (!$f['outputs'] && ((mb_strpos($hkey, 'خروجی') !== false && mb_strpos($hkey, 'موردانتظار') !== false)
                || (mb_strpos($firstKey, 'خروجی') !== false && mb_strpos($firstKey, 'موردانتظار') !== false))) {
                foreach (array_slice($rows, 1) as $r) {
                    $v = Fa::clean((string) end($r));
                    if ($v !== '' && !self::isPlaceholder($v) && !preg_match('/^\d+$/', Fa::latin($v))) {
                        $f['outputs'][] = $v;
                    }
                }
                $f['outputs'] = array_slice($f['outputs'], 0, 20);
                continue;
            }
            if ($f['challenges'] === '' && mb_strpos($hkey, 'چالش') !== false) {
                $lines = [];
                foreach ($rows as $r) {
                    foreach ($r as $v) {
                        if ($v !== '') {
                            $lines[] = $v;
                        }
                    }
                }
                $f['challenges'] = mb_substr(Fa::clean(implode("\n", $lines), true), 0, 3000);
                continue;
            }
            if ($f['cost_total_raw'] === '' && (mb_strpos($hkey, 'براوردهزینه') !== false || mb_strpos($firstKey, 'سرفصلهزینه') !== false)) {
                $this->readCosts($rows, $f);
            }
        }
    }

    private function readCosts(array $rows, array &$f): void
    {
        $unitKey = Fa::key(implode(' ', $rows[0]));
        foreach ($rows as $r) {
            $labelIndex = null;
            foreach ($r as $i => $v) {
                if (in_array(Fa::key($v), ['مجموع', 'جمعکل', 'جمع', 'مجموعکل', 'جمعکلهزینهها', 'مجموعهزینهها'], true)) {
                    $labelIndex = $i;
                }
            }
            if ($labelIndex === null) {
                continue;
            }
            for ($i = count($r) - 1; $i > $labelIndex; $i--) {
                $num = Fa::looseNumber(preg_replace('/[^\d.,٫٬۰-۹٠-٩]/u', '', $r[$i]));
                if ($num !== null && $num > 0) {
                    $f['cost_total_raw'] = Fa::clean($r[$i]);
                    $f['cost_total'] = $num;
                    break;
                }
            }
            break;
        }
        if ($f['cost_total'] === null) {
            return;
        }
        $n = (float) $f['cost_total'];
        // [label, factor to billion rials, is a scaled unit (million/billion)]
        $units = [
            'میلیاردریال' => ['میلیارد ریال', 1.0, true],
            'میلیاردتومان' => ['میلیارد تومان', 10.0, true],
            'میلیونتومان' => ['میلیون تومان', 0.01, true],
            'میلیونریال' => ['میلیون ریال', 0.001, true],
            'ریال' => ['ریال', 1e-9, false],
            'تومان' => ['تومان', 1e-8, false],
        ];
        $declared = null;
        foreach ($units as $k => $u) {
            if (mb_strpos($unitKey, $k) !== false) {
                $declared = $u;
                break;
            }
        }
        $f['cost_unit'] = $declared ? $declared[0] : '';

        $asRial = ['label' => 'اگر رقم به ریال باشد', 'value' => round($n / 1e9, 3)];
        $asToman = ['label' => 'اگر رقم به تومان باشد', 'value' => round($n / 1e8, 3)];
        $suggest = [];
        if ($declared && !$declared[2]) {
            // the table asks for plain rials or tomans
            $byTable = ['label' => 'طبق واحد جدول (' . $declared[0] . ')', 'value' => round($n * $declared[1], 3)];
            $suggest = $declared[0] === 'ریال' ? [$byTable, $asToman] : [$byTable, $asRial];
        } elseif ($n >= 10000000) {
            // a full amount was typed, whatever unit the table header asks for
            $suggest = [$asRial, $asToman];
        } elseif ($declared) {
            $suggest[] = ['label' => 'طبق واحد جدول (' . $declared[0] . ')', 'value' => round($n * $declared[1], 3)];
        } else {
            $suggest[] = ['label' => 'اگر رقم به میلیون تومان باشد', 'value' => round($n * 0.01, 3)];
            $suggest[] = ['label' => 'اگر رقم به میلیارد ریال باشد', 'value' => round($n, 3)];
        }
        foreach ($suggest as $sg) {
            if ($sg['value'] >= 0.01 && $sg['value'] <= 100000) {
                $f['amount_suggestions'][] = $sg;
            }
        }
    }

    /** Table rows as arrays of clean cell strings (merged-away cells removed). */
    private static function plainRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $r = [];
            foreach ($row['c'] as $cell) {
                if (!empty($cell['skip'])) {
                    continue;
                }
                $r[] = Fa::clean(self::cellText($cell));
            }
            $out[] = $r;
        }
        return $out;
    }

    // ---- misc ------------------------------------------------------------------

    private static function docType(array $doc): string
    {
        $candidates = [];
        if (!empty($doc['meta']['header'])) {
            $candidates[] = (string) $doc['meta']['header'];
        }
        $i = 0;
        foreach ($doc['blocks'] ?? [] as $b) {
            if (($b['t'] ?? '') === 'p') {
                $candidates[] = $b['x'];
                if (++$i >= 4) {
                    break;
                }
            }
        }
        foreach ($candidates as $c) {
            if (mb_strpos(Fa::unify($c), 'کاربرگ') !== false) {
                $c = Fa::clean(str_replace(['"', '«', '»', '“', '”', '|'], ' ', $c));
                return mb_substr($c, 0, 180);
            }
        }
        return '';
    }

    /** Last resort for the company name: «داوری _ ویرا افق فردا.docx» -> «ویرا افق فردا». */
    public static function companyFromFilename(string $name): string
    {
        $base = preg_replace('/\.[A-Za-z0-9]{2,5}$/', '', $name);
        $base = str_replace(['_', '-', '–', '—', '+', '.', '(', ')'], ' ', (string) $base);
        $words = preg_split('/\s+/u', trim($base), -1, PREG_SPLIT_NO_EMPTY);
        $stop = ['داوری', 'کاربرگ', 'طرح', 'فنی', 'کسبوکار', 'کسبوکاری', 'درخواست', 'فرم', 'شرکت', 'خیام', 'copy', 'final', 'new', 'docx'];
        $kept = [];
        foreach ($words as $w) {
            $k = Fa::key($w);
            if ($k === '' || in_array($k, $stop, true) || preg_match('/^\d+$/', $k)) {
                continue;
            }
            // skip machine generated ids such as 6a92a818e7ff1
            if (preg_match('/^[a-z0-9]+$/', $k) && preg_match('/\d/', $k) && strlen($k) >= 6) {
                continue;
            }
            $kept[] = $w;
        }
        $guess = implode(' ', $kept);
        if (mb_strlen($guess) < 3 || !preg_match('/\p{L}{3}/u', $guess)) {
            return '';
        }
        // a name made only of a short latin token (e.g. "aca") is not a company
        if (preg_match('/^[A-Za-z]{1,4}$/', $guess)) {
            return '';
        }
        return mb_substr($guess, 0, 120);
    }
}
