<?php

namespace Fava\Docx;

use DOMDocument;
use DOMElement;
use DOMNode;
use Fava\Core\Fa;
use Fava\Core\ZipLite;

/**
 * Reads a .docx file into a small, serialisable document model – without Word,
 * LibreOffice or any library – so it works on plain shared hosting.
 *
 * Model
 *   document = ['blocks' => Block[], 'meta' => [...]]
 *   paragraph = ['t' => 'p', 'x' => text,
 *                's' => 'title'|'h1'..'h6'      (heading style, optional)
 *                'l' => [level, 'b'|'n', label] (list item, optional)
 *                'b' => 1                       (whole paragraph bold, optional)
 *                'h' => 1                       (contains highlighted text, optional)
 *                'r' => [[text, flags], ...]]   (runs, only when formatting is mixed; 1 = bold, 2 = highlight)
 *   table     = ['t' => 'tbl', 'bidi' => 0|1, 'rows' => Row[]]
 *   row       = ['c' => Cell[], 'hd' => 1 (header row, optional)]
 *   cell      = ['b' => Block[], 'cs' => colspan?, 'rs' => rowspan?, 'skip' => 1 (merged away)?, 'sh' => 1 (shaded)?]
 */
class DocxReader
{
    private const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const MAX_XML_BYTES = 41943040;   // 40 MB of XML per part
    private const MAX_BLOCKS = 8000;
    private const MAX_DEPTH = 12;

    /** @var array styleId => ['name' => string, 'based' => ?string, 'outline' => ?int, 'numId' => ?string, 'ilvl' => int] */
    private $styles = [];
    /** @var array numId => [ilvl => format] */
    private $numFormats = [];
    /** @var array numId => [ilvl => counter] */
    private $counters = [];
    /** @var int */
    private $blockCount = 0;
    /** @var bool */
    private $truncated = false;
    /** @var array */
    private $stats = ['paragraphs' => 0, 'tables' => 0, 'chars' => 0];

    /**
     * @throws DocxException with a message that can be shown to the admin
     */
    public static function read(string $path): array
    {
        return (new self())->parse($path);
    }

    private function parse(string $path): array
    {
        try {
            $zip = new ZipLite($path);
        } catch (\Throwable $e) {
            throw new DocxException('این فایل یک سند Word (docx) معتبر نیست.');
        }
        try {
            if (!$zip->has('word/document.xml')) {
                throw new DocxException('این فایل یک سند Word (docx) نیست؛ بخش اصلی سند در آن پیدا نشد.');
            }
            $main = $zip->read('word/document.xml', self::MAX_XML_BYTES);
            $stylesXml = $zip->has('word/styles.xml') ? $zip->read('word/styles.xml', self::MAX_XML_BYTES) : null;
            $numXml = $zip->has('word/numbering.xml') ? $zip->read('word/numbering.xml', self::MAX_XML_BYTES) : null;
            $coreXml = $zip->has('docProps/core.xml') ? $zip->read('docProps/core.xml', 1048576) : null;
            $headerFooter = ['header' => [], 'footer' => []];
            foreach ($zip->names() as $name) {
                if (preg_match('#^word/(header|footer)\d*\.xml$#', $name, $m)) {
                    $headerFooter[$m[1]][] = $zip->read($name, 4194304);
                }
            }
        } catch (DocxException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new DocxException('خواندن محتوای فایل ناموفق بود: ' . $e->getMessage());
        } finally {
            $zip->close();
        }

        if ($stylesXml) {
            $this->loadStyles($stylesXml);
        }
        if ($numXml) {
            $this->loadNumbering($numXml);
        }

        $dom = self::loadXml((string) $main);
        if (!$dom || !$dom->documentElement) {
            throw new DocxException('ساختار داخلی سند قابل خواندن نیست (XML نامعتبر).');
        }
        $body = self::child($dom->documentElement, 'body');
        if (!$body) {
            throw new DocxException('بدنه سند پیدا نشد.');
        }
        $blocks = $this->blocks($body, 0);

        $meta = [
            'header' => $this->partText($headerFooter['header']),
            'footer' => $this->partText($headerFooter['footer']),
            'paragraphs' => $this->stats['paragraphs'],
            'tables' => $this->stats['tables'],
            'chars' => $this->stats['chars'],
            'truncated' => $this->truncated ? 1 : 0,
        ];
        if ($coreXml) {
            $meta += $this->coreProps($coreXml);
        }
        return ['blocks' => $blocks, 'meta' => $meta];
    }

    // ---- XML helpers -------------------------------------------------------

    /** Parse XML defensively: no DTDs, no entities, no network. */
    private static function loadXml(string $xml): ?DOMDocument
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new DocxException('سند شامل ساختار غیرمجاز (DTD) است و پردازش نشد.');
        }
        $prevErrors = libxml_use_internal_errors(true);
        $prevLoader = null;
        if (PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
            $prevLoader = libxml_disable_entity_loader(true);
        }
        $dom = new DOMDocument();
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prevErrors);
        if ($prevLoader !== null) {
            libxml_disable_entity_loader($prevLoader);
        }
        return $ok ? $dom : null;
    }

    private static function isW(DOMNode $n, string $name): bool
    {
        return $n instanceof DOMElement && $n->localName === $name && $n->namespaceURI === self::NS_W;
    }

    private static function child(?DOMElement $parent, string $name): ?DOMElement
    {
        if (!$parent) {
            return null;
        }
        for ($c = $parent->firstChild; $c !== null; $c = $c->nextSibling) {
            if (self::isW($c, $name)) {
                /** @var DOMElement $c */
                return $c;
            }
        }
        return null;
    }

    private static function attr(?DOMElement $el, string $name): ?string
    {
        if (!$el) {
            return null;
        }
        if ($el->hasAttributeNS(self::NS_W, $name)) {
            return $el->getAttributeNS(self::NS_W, $name);
        }
        return $el->hasAttribute($name) ? $el->getAttribute($name) : null;
    }

    /** OOXML on/off property: present without val, or val not 0/false/off/none. */
    private static function isOn(?DOMElement $el): bool
    {
        if (!$el) {
            return false;
        }
        $v = self::attr($el, 'val');
        return $v === null || !in_array(strtolower($v), ['0', 'false', 'off', 'none'], true);
    }

    // ---- styles & numbering ------------------------------------------------

    private function loadStyles(string $xml): void
    {
        try {
            $dom = self::loadXml($xml);
        } catch (\Throwable $e) {
            return;
        }
        if (!$dom || !$dom->documentElement) {
            return;
        }
        for ($st = $dom->documentElement->firstChild; $st !== null; $st = $st->nextSibling) {
            if (!self::isW($st, 'style')) {
                continue;
            }
            /** @var DOMElement $st */
            $id = self::attr($st, 'styleId');
            if ($id === null) {
                continue;
            }
            $pPr = self::child($st, 'pPr');
            $numPr = self::child($pPr, 'numPr');
            $outline = self::attr(self::child($pPr, 'outlineLvl'), 'val');
            $this->styles[$id] = [
                'name' => strtolower((string) self::attr(self::child($st, 'name'), 'val')),
                'based' => self::attr(self::child($st, 'basedOn'), 'val'),
                'outline' => $outline === null ? null : (int) $outline,
                'numId' => self::attr(self::child($numPr, 'numId'), 'val'),
                'ilvl' => (int) (self::attr(self::child($numPr, 'ilvl'), 'val') ?? 0),
            ];
        }
    }

    private function loadNumbering(string $xml): void
    {
        try {
            $dom = self::loadXml($xml);
        } catch (\Throwable $e) {
            return;
        }
        if (!$dom || !$dom->documentElement) {
            return;
        }
        $abstract = [];
        $nums = [];
        for ($n = $dom->documentElement->firstChild; $n !== null; $n = $n->nextSibling) {
            if (self::isW($n, 'abstractNum')) {
                /** @var DOMElement $n */
                $aid = (string) self::attr($n, 'abstractNumId');
                for ($lvl = $n->firstChild; $lvl !== null; $lvl = $lvl->nextSibling) {
                    if (self::isW($lvl, 'lvl')) {
                        /** @var DOMElement $lvl */
                        $abstract[$aid][(int) self::attr($lvl, 'ilvl')] = (string) (self::attr(self::child($lvl, 'numFmt'), 'val') ?? 'decimal');
                    }
                }
            } elseif (self::isW($n, 'num')) {
                /** @var DOMElement $n */
                $nums[(string) self::attr($n, 'numId')] = (string) self::attr(self::child($n, 'abstractNumId'), 'val');
            }
        }
        foreach ($nums as $numId => $aid) {
            $this->numFormats[$numId] = $abstract[$aid] ?? [];
        }
    }

    /** @return array{0:?string,1:?string,2:int} [headingKey, numId, ilvl] resolved through the style chain */
    private function styleInfo(?string $styleId): array
    {
        $heading = null;
        $numId = null;
        $ilvl = 0;
        $guard = 0;
        while ($styleId !== null && isset($this->styles[$styleId]) && $guard++ < 12) {
            $s = $this->styles[$styleId];
            if ($heading === null) {
                if (preg_match('/^heading (\d)$/', $s['name'], $m)) {
                    $heading = 'h' . min(6, max(1, (int) $m[1]));
                } elseif ($s['name'] === 'title') {
                    $heading = 'title';
                } elseif ($s['outline'] !== null && $s['outline'] < 9) {
                    $heading = 'h' . min(6, $s['outline'] + 1);
                }
            }
            if ($numId === null && $s['numId'] !== null) {
                $numId = $s['numId'];
                $ilvl = $s['ilvl'];
            }
            $styleId = $s['based'];
        }
        return [$heading, $numId, $ilvl];
    }

    // ---- block level -------------------------------------------------------

    private function blocks(DOMElement $container, int $depth): array
    {
        $out = [];
        if ($depth > self::MAX_DEPTH) {
            return $out;
        }
        for ($c = $container->firstChild; $c !== null; $c = $c->nextSibling) {
            if (!$c instanceof DOMElement) {
                continue;
            }
            if ($this->blockCount >= self::MAX_BLOCKS) {
                $this->truncated = true;
                break;
            }
            if ($c->namespaceURI === self::NS_W) {
                switch ($c->localName) {
                    case 'p':
                        foreach ($this->paragraph($c, $depth) as $b) {
                            $out[] = $b;
                        }
                        break;
                    case 'tbl':
                        $t = $this->table($c, $depth);
                        if ($t) {
                            $out[] = $t;
                        }
                        break;
                    case 'sdt':
                        $content = self::child($c, 'sdtContent');
                        if ($content) {
                            foreach ($this->blocks($content, $depth + 1) as $b) {
                                $out[] = $b;
                            }
                        }
                        break;
                    case 'customXml':
                    case 'smartTag':
                    case 'ins':
                    case 'moveTo':
                        foreach ($this->blocks($c, $depth + 1) as $b) {
                            $out[] = $b;
                        }
                        break;
                }
            } elseif ($c->localName === 'AlternateContent') {
                $branch = self::alternate($c);
                if ($branch) {
                    foreach ($this->blocks($branch, $depth + 1) as $b) {
                        $out[] = $b;
                    }
                }
            }
        }
        return $out;
    }

    /** mc:AlternateContent holds the same content twice (Choice + Fallback): read one branch only. */
    private static function alternate(DOMElement $ac): ?DOMElement
    {
        $fallback = null;
        for ($c = $ac->firstChild; $c !== null; $c = $c->nextSibling) {
            if ($c instanceof DOMElement) {
                if ($c->localName === 'Choice') {
                    return $c;
                }
                if ($c->localName === 'Fallback') {
                    $fallback = $c;
                }
            }
        }
        return $fallback;
    }

    /** @return array[] the paragraph itself, preceded by paragraphs of any text box anchored in it */
    private function paragraph(DOMElement $p, int $depth): array
    {
        $pPr = self::child($p, 'pPr');
        $styleId = self::attr(self::child($pPr, 'pStyle'), 'val');
        list($heading, $numId, $ilvl) = $this->styleInfo($styleId);

        $outline = self::attr(self::child($pPr, 'outlineLvl'), 'val');
        if ($outline !== null && (int) $outline < 9) {
            $heading = 'h' . min(6, (int) $outline + 1);
        }
        $numPr = self::child($pPr, 'numPr');
        if ($numPr) {
            $nid = self::attr(self::child($numPr, 'numId'), 'val');
            if ($nid !== null) {
                $numId = $nid;
                $ilvl = (int) (self::attr(self::child($numPr, 'ilvl'), 'val') ?? 0);
            }
        }
        if ($numId === '0') {
            $numId = null;
        }

        $runs = [];
        $extra = [];
        $this->inline($p, $runs, $extra, $depth);

        // merge neighbouring runs that share formatting
        $merged = [];
        foreach ($runs as $run) {
            $last = count($merged) - 1;
            if ($last >= 0 && $merged[$last][1] === $run[1]) {
                $merged[$last][0] .= $run[0];
            } else {
                $merged[] = $run;
            }
        }
        $text = '';
        foreach ($merged as $run) {
            $text .= $run[0];
        }

        // list label – counted even for empty paragraphs, as Word does
        $list = null;
        if ($numId !== null) {
            $fmt = $this->numFormats[$numId][$ilvl] ?? 'bullet';
            if ($fmt === 'bullet' || $fmt === 'none') {
                $list = [$ilvl, 'b', '•'];
            } else {
                $n = ($this->counters[$numId][$ilvl] ?? 0) + 1;
                $this->counters[$numId][$ilvl] = $n;
                foreach (array_keys($this->counters[$numId]) as $deeper) {
                    if ($deeper > $ilvl) {
                        unset($this->counters[$numId][$deeper]);
                    }
                }
                $list = [$ilvl, 'n', Fa::digits((string) $n) . '.'];
            }
        }

        $out = $extra;
        if (trim(str_replace(["\u{00A0}", "\u{200C}", "\u{200F}", "\u{200E}"], '', $text)) === '') {
            return $out; // empty paragraphs carry no information
        }

        $block = ['t' => 'p', 'x' => $text];
        if ($heading !== null) {
            $block['s'] = $heading;
        }
        if ($list !== null && $heading === null) {
            $block['l'] = $list;
        }
        $flagsAll = null;
        $mixed = false;
        $anyHighlight = false;
        foreach ($merged as $run) {
            if (trim($run[0]) === '') {
                continue;
            }
            if ($run[1] & 2) {
                $anyHighlight = true;
            }
            if ($flagsAll === null) {
                $flagsAll = $run[1];
            } elseif ($flagsAll !== $run[1]) {
                $mixed = true;
            }
        }
        if ($mixed) {
            $block['r'] = $merged;
        } elseif ($flagsAll !== null && ($flagsAll & 1)) {
            $block['b'] = 1;
        }
        if ($anyHighlight) {
            $block['h'] = 1;
        }

        $this->blockCount++;
        $this->stats['paragraphs']++;
        $this->stats['chars'] += mb_strlen($text);
        $out[] = $block;
        return $out;
    }

    // ---- inline level ------------------------------------------------------

    private function inline(DOMElement $node, array &$runs, array &$extra, int $depth): void
    {
        for ($c = $node->firstChild; $c !== null; $c = $c->nextSibling) {
            if (!$c instanceof DOMElement) {
                continue;
            }
            if ($c->namespaceURI === self::NS_W) {
                switch ($c->localName) {
                    case 'r':
                        $this->run($c, $runs, $extra, $depth);
                        break;
                    case 'hyperlink':
                    case 'smartTag':
                    case 'ins':
                    case 'moveTo':
                    case 'fldSimple':
                    case 'customXml':
                    case 'dir':
                    case 'bdo':
                        $this->inline($c, $runs, $extra, $depth);
                        break;
                    case 'sdt':
                        $content = self::child($c, 'sdtContent');
                        if ($content) {
                            $this->inline($content, $runs, $extra, $depth);
                        }
                        break;
                    // del, moveFrom (deleted text), pPr, bookmarks, proofErr, comments: ignored
                }
            } elseif ($c->localName === 'AlternateContent') {
                $branch = self::alternate($c);
                if ($branch) {
                    $this->inline($branch, $runs, $extra, $depth);
                }
            } elseif ($c->localName === 'oMath' || $c->localName === 'oMathPara') {
                $t = trim($c->textContent);
                if ($t !== '') {
                    $runs[] = [$t, 0];
                }
            }
        }
    }

    private function run(DOMElement $r, array &$runs, array &$extra, int $depth): void
    {
        $rPr = self::child($r, 'rPr');
        $flags = 0;
        if ($rPr) {
            // complex-script bold (bCs) is what actually applies to Persian text
            if (self::isOn(self::child($rPr, 'b')) || self::isOn(self::child($rPr, 'bCs'))) {
                $flags |= 1;
            }
            $hl = self::child($rPr, 'highlight');
            if ($hl && self::isOn($hl)) {
                $flags |= 2;
            } else {
                $fill = strtoupper((string) self::attr(self::child($rPr, 'shd'), 'fill'));
                if ($fill !== '' && $fill !== 'AUTO' && $fill !== 'FFFFFF') {
                    $flags |= 2;
                }
            }
        }
        $text = '';
        for ($c = $r->firstChild; $c !== null; $c = $c->nextSibling) {
            if (!$c instanceof DOMElement) {
                continue;
            }
            if ($c->namespaceURI !== self::NS_W) {
                if ($c->localName === 'AlternateContent') {
                    $branch = self::alternate($c);
                    if ($branch) {
                        $this->textBoxes($branch, $extra, $depth);
                    }
                }
                continue;
            }
            switch ($c->localName) {
                case 't':
                    $text .= $c->textContent;
                    break;
                case 'tab':
                    $text .= "\t";
                    break;
                case 'br':
                case 'cr':
                    $text .= "\n";
                    break;
                case 'noBreakHyphen':
                    $text .= '-';
                    break;
                case 'sym':
                    $text .= self::symbol((string) self::attr($c, 'font'), (string) self::attr($c, 'char'));
                    break;
                case 'fldChar':
                    // legacy form-field check box
                    $box = self::child(self::child($c, 'ffData'), 'checkBox');
                    if ($box) {
                        $checked = self::child($box, 'checked');
                        $on = $checked ? self::isOn($checked) : self::isOn(self::child($box, 'default')) && self::child($box, 'default') !== null;
                        $text .= $on ? '☑' : '☐';
                    }
                    break;
                case 'drawing':
                case 'pict':
                case 'object':
                    $this->textBoxes($c, $extra, $depth);
                    break;
                // instrText, delText, footnoteReference, softHyphen, lastRenderedPageBreak…: ignored
            }
        }
        if ($text !== '') {
            $runs[] = [$text, $flags];
        }
    }

    /** Paragraphs inside text boxes become ordinary blocks placed before their anchor paragraph. */
    private function textBoxes(DOMElement $node, array &$extra, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }
        for ($c = $node->firstChild; $c !== null; $c = $c->nextSibling) {
            if (!$c instanceof DOMElement) {
                continue;
            }
            if ($c->localName === 'txbxContent' && $c->namespaceURI === self::NS_W) {
                foreach ($this->blocks($c, $depth + 1) as $b) {
                    $extra[] = $b;
                }
            } elseif ($c->localName === 'AlternateContent') {
                $branch = self::alternate($c);
                if ($branch) {
                    $this->textBoxes($branch, $extra, $depth + 1);
                }
            } else {
                $this->textBoxes($c, $extra, $depth + 1);
            }
        }
    }

    /** Map check-box glyphs from symbol fonts to ☑ / ☐; anything else is dropped. */
    private static function symbol(string $font, string $charHex): string
    {
        $code = hexdec($charHex) & 0xFF;
        $font = strtolower(trim($font));
        $checked = [];
        $unchecked = [];
        if ($font === 'wingdings') {
            $checked = [0x6E, 0xFC, 0xFB, 0xFD, 0xFE, 0x6C, 0xA4, 0xA7, 0x78, 0x79];
            $unchecked = [0x6F, 0x70, 0x71, 0x72, 0xA8, 0xA1, 0x6D];
        } elseif ($font === 'wingdings 2') {
            $checked = [0x50, 0x51, 0x52, 0x53, 0x54, 0x55, 0x56, 0xA2, 0x98, 0x4F];
            $unchecked = [0xA3, 0x2A, 0x99, 0x30, 0x35];
        } elseif ($font === 'symbol') {
            $unchecked = [0x89, 0x7F, 0xA0];
            $checked = [0xD6, 0xB4];
        } elseif ($font === 'webdings') {
            $checked = [0x61, 0x72, 0x67];
            $unchecked = [0x63];
        }
        if (in_array($code, $checked, true)) {
            return '☑';
        }
        if (in_array($code, $unchecked, true)) {
            return '☐';
        }
        return '';
    }

    // ---- tables --------------------------------------------------------------

    private function table(DOMElement $tbl, int $depth): ?array
    {
        if ($depth > self::MAX_DEPTH) {
            return null;
        }
        $tblPr = self::child($tbl, 'tblPr');
        $bidi = self::isOn(self::child($tblPr, 'bidiVisual')) ? 1 : 0;
        $rows = [];
        $this->tableRows($tbl, $rows, $depth);
        if (!$rows) {
            return null;
        }

        // vertical merges -> rowspans
        $open = []; // grid column => [rowIndex, cellIndex]
        foreach ($rows as $ri => &$row) {
            $col = 0;
            foreach ($row['c'] as $ci => &$cell) {
                $span = $cell['cs'] ?? 1;
                $vm = $cell['vm'] ?? null;
                unset($cell['vm']);
                if ($vm === 'r') {
                    $open[$col] = [$ri, $ci];
                } elseif ($vm === 'c' && isset($open[$col])) {
                    list($ori, $oci) = $open[$col];
                    $rows[$ori]['c'][$oci]['rs'] = ($rows[$ori]['c'][$oci]['rs'] ?? 1) + 1;
                    // keep any text typed into a continued cell
                    foreach ($cell['b'] as $b) {
                        $rows[$ori]['c'][$oci]['b'][] = $b;
                    }
                    $cell['b'] = [];
                    $cell['skip'] = 1;
                } else {
                    unset($open[$col]);
                }
                $col += $span;
            }
            unset($cell);
        }
        unset($row);

        $this->blockCount++;
        $this->stats['tables']++;
        return ['t' => 'tbl', 'bidi' => $bidi, 'rows' => $rows];
    }

    private function tableRows(DOMElement $container, array &$rows, int $depth): void
    {
        for ($c = $container->firstChild; $c !== null; $c = $c->nextSibling) {
            if (!$c instanceof DOMElement || $c->namespaceURI !== self::NS_W) {
                continue;
            }
            if ($c->localName === 'tr') {
                $cells = [];
                $this->tableCells($c, $cells, $depth);
                if ($cells) {
                    $row = ['c' => $cells];
                    if (self::isOn(self::child(self::child($c, 'trPr'), 'tblHeader'))) {
                        $row['hd'] = 1;
                    }
                    $rows[] = $row;
                }
            } elseif ($c->localName === 'sdt') {
                $content = self::child($c, 'sdtContent');
                if ($content) {
                    $this->tableRows($content, $rows, $depth);
                }
            } elseif (in_array($c->localName, ['customXml', 'ins', 'moveTo'], true)) {
                $this->tableRows($c, $rows, $depth);
            }
        }
    }

    private function tableCells(DOMElement $container, array &$cells, int $depth): void
    {
        for ($c = $container->firstChild; $c !== null; $c = $c->nextSibling) {
            if (!$c instanceof DOMElement || $c->namespaceURI !== self::NS_W) {
                continue;
            }
            if ($c->localName === 'tc') {
                $tcPr = self::child($c, 'tcPr');
                $cell = ['b' => $this->blocks($c, $depth + 1)];
                $span = (int) (self::attr(self::child($tcPr, 'gridSpan'), 'val') ?? 1);
                if ($span > 1) {
                    $cell['cs'] = min($span, 63);
                }
                $vMerge = self::child($tcPr, 'vMerge');
                if ($vMerge) {
                    $cell['vm'] = self::attr($vMerge, 'val') === 'restart' ? 'r' : 'c';
                }
                $fill = strtoupper((string) self::attr(self::child($tcPr, 'shd'), 'fill'));
                if ($fill !== '' && $fill !== 'AUTO' && $fill !== 'FFFFFF') {
                    $cell['sh'] = 1;
                }
                $cells[] = $cell;
            } elseif ($c->localName === 'sdt') {
                $content = self::child($c, 'sdtContent');
                if ($content) {
                    $this->tableCells($content, $cells, $depth);
                }
            } elseif (in_array($c->localName, ['customXml', 'ins', 'moveTo'], true)) {
                $this->tableCells($c, $cells, $depth);
            }
        }
    }

    // ---- headers / properties -------------------------------------------------

    /** Plain text of header/footer parts (the worksheet's printed title usually lives there). */
    private function partText(array $xmlParts): string
    {
        $best = '';
        foreach ($xmlParts as $xml) {
            if (!is_string($xml) || $xml === '') {
                continue;
            }
            try {
                $dom = self::loadXml($xml);
            } catch (\Throwable $e) {
                continue;
            }
            if (!$dom || !$dom->documentElement) {
                continue;
            }
            $lines = [];
            foreach ($dom->getElementsByTagNameNS(self::NS_W, 'p') as $p) {
                $t = '';
                foreach ($p->getElementsByTagNameNS(self::NS_W, 't') as $tn) {
                    $t .= $tn->textContent;
                }
                $t = Fa::clean($t);
                if ($t !== '' && !in_array($t, $lines, true)) {
                    $lines[] = $t;
                }
            }
            $text = implode(' | ', $lines);
            if (mb_strlen($text) > mb_strlen($best)) {
                $best = $text;
            }
        }
        return mb_substr($best, 0, 500);
    }

    private function coreProps(string $xml): array
    {
        $out = [];
        try {
            $dom = self::loadXml($xml);
        } catch (\Throwable $e) {
            return $out;
        }
        if (!$dom || !$dom->documentElement) {
            return $out;
        }
        foreach (['created' => 'created', 'modified' => 'modified'] as $key => $tag) {
            $nodes = $dom->getElementsByTagNameNS('http://purl.org/dc/terms/', $tag);
            if ($nodes->length > 0) {
                $ts = strtotime(trim($nodes->item(0)->textContent));
                if ($ts) {
                    $out[$key] = gmdate('Y-m-d H:i:s', $ts);
                }
            }
        }
        return $out;
    }

    // ---- model utilities (shared by extractor and renderer) -------------------

    /** Plain text of a list of blocks; table cells are separated by tabs, paragraphs by new lines. */
    public static function text(array $blocks): string
    {
        $lines = [];
        foreach ($blocks as $b) {
            if (($b['t'] ?? '') === 'p') {
                $lines[] = $b['x'];
            } elseif (($b['t'] ?? '') === 'tbl') {
                foreach ($b['rows'] as $row) {
                    $cells = [];
                    foreach ($row['c'] as $cell) {
                        if (!empty($cell['skip'])) {
                            continue;
                        }
                        $cells[] = str_replace("\n", ' ', self::text($cell['b']));
                    }
                    $lines[] = implode("\t", $cells);
                }
            }
        }
        return implode("\n", $lines);
    }
}
