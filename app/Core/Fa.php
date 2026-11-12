<?php

namespace Fava\Core;

/**
 * Persian text utilities: digit conversion and normalisation for matching/searching.
 */
class Fa
{
    private const FA_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    private const AR_DIGITS = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    private const EN_DIGITS = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    /**
     * Latin digits -> Persian digits, for display.
     * Digits that belong to a Latin word, address or code stay as they are
     * (3onsor.ir, info2@mail.com, P1405-002, ISO9001), as Persian typography expects.
     */
    public static function digits(string $s): string
    {
        if (!preg_match('/[0-9]/', $s)) {
            return $s;
        }
        if (!preg_match('/[A-Za-z]/', $s)) {
            return str_replace(self::EN_DIGITS, self::FA_DIGITS, $s);
        }
        $out = preg_replace_callback('/[^\s«»"\'()\[\]{}،؛]+/u', function ($m) {
            return preg_match('/[A-Za-z]/', $m[0]) ? $m[0] : str_replace(self::EN_DIGITS, self::FA_DIGITS, $m[0]);
        }, $s);
        // not valid UTF-8: leave the text alone rather than lose it
        return $out === null ? $s : $out;
    }

    /** Persian and Arabic-Indic digits -> Latin digits. */
    public static function latin(string $s): string
    {
        $s = str_replace(self::FA_DIGITS, self::EN_DIGITS, $s);
        return str_replace(self::AR_DIGITS, self::EN_DIGITS, $s);
    }

    /**
     * Parse a number typed into a form field: Persian/Arabic digits are fine, the dot
     * (or «٫», or the traditional «۲/۵») is the decimal mark and commas may only group
     * thousands. Anything ambiguous returns null, so the form asks again instead of
     * silently reading 18,5 as 185.
     */
    public static function number($value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $s = self::latin($value);
        $s = (string) preg_replace('/[\s\x{00A0}\x{200C}\x{200E}\x{200F}]+/u', '', $s);
        $s = str_replace(['٫', '٬', '،'], ['.', ',', ','], $s);
        if (preg_match('/^-?\d+\/\d+$/', $s)) {
            $s = str_replace('/', '.', $s);
        }
        if (strpos($s, ',') !== false) {
            if (!preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $s)) {
                return null;
            }
            $s = str_replace(',', '', $s);
        }
        if (!preg_match('/^-?(\d+\.?\d*|\.\d+)$/', $s)) {
            return null;
        }
        return (float) $s;
    }

    /**
     * Parse a number found in a document, where dots as well as commas are used to
     * group thousands («22.000.000.000», «18.500»). Not for form input.
     */
    public static function looseNumber($value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $s = self::latin(trim($value));
        $s = str_replace(['٫', '٬', '،', ' ', "\u{00A0}", "\u{200C}"], ['.', '', '', '', '', ''], $s);
        if (preg_match('/^-?\d{1,3}([.,]\d{3})+$/', $s)) {
            $s = str_replace([',', '.'], '', $s);
        } else {
            $s = str_replace(',', '', $s);
        }
        if ($s === '' || !is_numeric($s)) {
            return null;
        }
        return (float) $s;
    }

    /** Unify Arabic/Persian letter variants and drop invisible characters (keeps spaces). */
    public static function unify(string $s): string
    {
        static $map = [
            'ي' => 'ی', 'ى' => 'ی', 'ئ' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'ۀ' => 'ه',
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ؤ' => 'و',
            "\u{200C}" => '', "\u{200D}" => '', "\u{200E}" => '', "\u{200F}" => '',
            "\u{202A}" => '', "\u{202B}" => '', "\u{202C}" => '', "\u{202D}" => '', "\u{202E}" => '',
            "\u{2066}" => '', "\u{2067}" => '', "\u{2068}" => '', "\u{2069}" => '',
            "\u{FEFF}" => '', "\u{00AD}" => '', "\u{0640}" => '',
        ];
        $s = strtr($s, $map);
        // Arabic diacritics
        $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $s);
        return self::latin((string) $s);
    }

    /**
     * Matching key: unified letters and digits only, lower-cased.
     * «نام شرکت/ شتاب‌دهنده:» and «نام شركت / شتاب دهنده» give the same key.
     */
    public static function key(string $s): string
    {
        $s = mb_strtolower(self::unify($s));
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $s);
    }

    /** Search-friendly form (unified, lower-case, single spaces). */
    public static function search(string $s): string
    {
        $s = mb_strtolower(self::unify(str_replace("\u{200C}", ' ', $s)));
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /** Tidy a value for display: collapse whitespace, trim. Keeps line breaks when asked. */
    public static function clean(string $s, bool $keepLines = false): string
    {
        $s = str_replace(["\u{00A0}", "\t", "\r"], [' ', ' ', ''], $s);
        if ($keepLines) {
            $lines = [];
            foreach (explode("\n", $s) as $line) {
                $line = trim((string) preg_replace('/[ ]{2,}/u', ' ', $line));
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
            return implode("\n", $lines);
        }
        $s = (string) preg_replace('/\s+/u', ' ', $s);
        return self::trim($s);
    }

    /**
     * Unicode-safe trim. PHP's trim() works on bytes, so a mask with multi-byte characters
     * (half-space, dashes …) can cut a Persian letter in half; this one cannot.
     *
     * @param string $extra additional characters, written as a regex character-class fragment
     */
    public static function trim(string $s, string $extra = ''): string
    {
        $class = '\s\x{00A0}\x{200C}\x{200E}\x{200F}' . $extra;
        return (string) preg_replace('/^[' . $class . ']+|[' . $class . ']+$/u', '', $s);
    }
}
