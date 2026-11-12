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

    /** Latin digits -> Persian digits. */
    public static function digits(string $s): string
    {
        return str_replace(self::EN_DIGITS, self::FA_DIGITS, $s);
    }

    /** Persian and Arabic-Indic digits -> Latin digits. */
    public static function latin(string $s): string
    {
        $s = str_replace(self::FA_DIGITS, self::EN_DIGITS, $s);
        return str_replace(self::AR_DIGITS, self::EN_DIGITS, $s);
    }

    /**
     * Parse a number typed by a person: Persian/Arabic digits, Persian decimal
     * and thousands separators. Returns null when it is not a number.
     */
    public static function number($value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $s = self::latin(trim($value));
        $s = str_replace(['٫', '٬', '،', ' ', "\u{00A0}", "\u{200C}"], ['.', '', '', '', '', ''], $s);
        // 1,234,567 or 1.234.567 -> thousands separators
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
