<?php

namespace Fava\Docx;

/**
 * Renders the document model produced by DocxReader as clean, escaped HTML,
 * so the admin and the judges can read the worksheet in the browser.
 * (Styling lives in the .docx rules of the stylesheet.)
 */
class DocRenderer
{
    public static function html(array $doc): string
    {
        $blocks = $doc['blocks'] ?? [];
        if (!$blocks) {
            return '<div class="docx"><p class="dx-empty">متنی برای نمایش وجود ندارد.</p></div>';
        }
        $html = '<div class="docx" dir="rtl">' . self::blocks($blocks) . '</div>';
        if (!empty($doc['meta']['truncated'])) {
            $html .= '<p class="dx-note">ادامه سند به دلیل حجم زیاد نمایش داده نشده است؛ فایل اصلی را دانلود کنید.</p>';
        }
        return $html;
    }

    private static function blocks(array $blocks): string
    {
        $out = '';
        foreach ($blocks as $b) {
            $type = $b['t'] ?? '';
            if ($type === 'p') {
                $out .= self::paragraph($b);
            } elseif ($type === 'tbl') {
                $out .= self::table($b);
            }
        }
        return $out;
    }

    private static function paragraph(array $b): string
    {
        if (isset($b['r'])) {
            $inner = '';
            foreach ($b['r'] as $run) {
                $t = self::text((string) $run[0]);
                $flags = (int) $run[1];
                if ($flags & 2) {
                    $t = '<mark>' . $t . '</mark>';
                }
                if ($flags & 1) {
                    $t = '<b>' . $t . '</b>';
                }
                $inner .= $t;
            }
        } else {
            $inner = self::text((string) $b['x']);
            if (!empty($b['h'])) {
                $inner = '<mark>' . $inner . '</mark>';
            }
        }

        $style = $b['s'] ?? null;
        if ($style !== null) {
            $level = $style === 'title' ? 0 : (int) substr($style, 1);
            $tag = $level <= 1 ? 'h3' : ($level === 2 ? 'h4' : 'h5');
            return '<' . $tag . ' class="dx-h dx-h' . $level . '" dir="auto">' . $inner . '</' . $tag . '>';
        }
        $class = '';
        if (!empty($b['b'])) {
            $class .= ' dx-b';
        }
        if (isset($b['l'])) {
            $level = min(4, max(0, (int) $b['l'][0]));
            return '<p class="dx-li dx-l' . $level . trim(' ' . $class) . '" dir="auto"><span class="dx-mk">'
                . htmlspecialchars((string) $b['l'][2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>' . $inner . '</p>';
        }
        return '<p' . ($class !== '' ? ' class="' . trim($class) . '"' : '') . ' dir="auto">' . $inner . '</p>';
    }

    private static function table(array $t): string
    {
        $rows = '';
        foreach ($t['rows'] as $row) {
            $cells = '';
            foreach ($row['c'] as $cell) {
                if (!empty($cell['skip'])) {
                    continue;
                }
                $attrs = '';
                if (!empty($cell['cs']) && $cell['cs'] > 1) {
                    $attrs .= ' colspan="' . (int) $cell['cs'] . '"';
                }
                if (!empty($cell['rs']) && $cell['rs'] > 1) {
                    $attrs .= ' rowspan="' . (int) $cell['rs'] . '"';
                }
                if (!empty($cell['sh'])) {
                    $attrs .= ' class="dx-sh"';
                }
                $inner = self::blocks($cell['b']);
                $cells .= '<td' . $attrs . '>' . ($inner === '' ? '&nbsp;' : $inner) . '</td>';
            }
            if ($cells !== '') {
                $rows .= '<tr' . (!empty($row['hd']) ? ' class="dx-hd"' : '') . '>' . $cells . '</tr>';
            }
        }
        if ($rows === '') {
            return '';
        }
        return '<div class="dx-tw"><table class="dx-t" dir="' . (!empty($t['bidi']) ? 'rtl' : 'ltr') . '"><tbody>'
            . $rows . '</tbody></table></div>';
    }

    /** Escape text; keep line breaks, turn tabs into a wide space and emphasise ticked boxes. */
    private static function text(string $s): string
    {
        $s = htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $s = str_replace(["\r\n", "\n", "\t"], ['<br>', '<br>', '&emsp;'], $s);
        // check boxes are drawn with CSS, so they look the same whatever fonts the reader's device has
        $on = '<span class="dx-cb dx-on" role="img" aria-label="انتخاب‌شده"></span>';
        $off = '<span class="dx-cb" role="img" aria-label="انتخاب‌نشده"></span>';
        return str_replace(['☑', '☒', '☐'], [$on, $on, $off], $s);
    }
}
