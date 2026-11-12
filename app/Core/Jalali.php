<?php

namespace Fava\Core;

/**
 * Jalali (Persian) calendar conversion.
 * Arithmetic algorithm after Borkowski, the same one used by the jalaali-js library;
 * valid for Jalali years -61 .. 3177.
 */
class Jalali
{
    public const MONTHS = [
        1 => 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
        'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند',
    ];

    public const WEEKDAYS = [ // index = PHP date('w'): 0 Sunday … 6 Saturday
        'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه',
    ];

    private const BREAKS = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];

    private static function div(int $a, int $b): int
    {
        return intdiv($a, $b);
    }

    private static function mod(int $a, int $b): int
    {
        return $a - intdiv($a, $b) * $b;
    }

    /** @return array{leap:int,gy:int,march:int} */
    private static function jalCal(int $jy): array
    {
        $breaks = self::BREAKS;
        $bl = count($breaks);
        $gy = $jy + 621;
        $leapJ = -14;
        $jp = $breaks[0];
        if ($jy < $jp || $jy >= $breaks[$bl - 1]) {
            throw new \InvalidArgumentException('Invalid Jalali year ' . $jy);
        }
        $jump = 0;
        for ($i = 1; $i < $bl; $i++) {
            $jm = $breaks[$i];
            $jump = $jm - $jp;
            if ($jy < $jm) {
                break;
            }
            $leapJ += self::div($jump, 33) * 8 + self::div(self::mod($jump, 33), 4);
            $jp = $jm;
        }
        $n = $jy - $jp;
        $leapJ += self::div($n, 33) * 8 + self::div(self::mod($n, 33) + 3, 4);
        if (self::mod($jump, 33) === 4 && $jump - $n === 4) {
            $leapJ++;
        }
        $leapG = self::div($gy, 4) - self::div((self::div($gy, 100) + 1) * 3, 4) - 150;
        $march = 20 + $leapJ - $leapG;
        if ($jump - $n < 6) {
            $n = $n - $jump + self::div($jump + 4, 33) * 33;
        }
        $leap = self::mod(self::mod($n + 1, 33) - 1, 4);
        if ($leap === -1) {
            $leap = 4;
        }
        return ['leap' => $leap, 'gy' => $gy, 'march' => $march];
    }

    private static function g2d(int $gy, int $gm, int $gd): int
    {
        $d = self::div(($gy + self::div($gm - 8, 6) + 100100) * 1461, 4)
            + self::div(153 * self::mod($gm + 9, 12) + 2, 5)
            + $gd - 34840408;
        return $d - self::div(self::div($gy + 100100 + self::div($gm - 8, 6), 100) * 3, 4) + 752;
    }

    /** @return int[] [gy, gm, gd] */
    private static function d2g(int $jdn): array
    {
        $j = 4 * $jdn + 139361631;
        $j = $j + self::div(self::div(4 * $jdn + 183187720, 146097) * 3, 4) * 4 - 3908;
        $i = self::div(self::mod($j, 1461), 4) * 5 + 308;
        $gd = self::div(self::mod($i, 153), 5) + 1;
        $gm = self::mod(self::div($i, 153), 12) + 1;
        $gy = self::div($j, 1461) - 100100 + self::div(8 - $gm, 6);
        return [$gy, $gm, $gd];
    }

    private static function j2d(int $jy, int $jm, int $jd): int
    {
        $r = self::jalCal($jy);
        return self::g2d($r['gy'], 3, $r['march']) + ($jm - 1) * 31 - self::div($jm, 7) * ($jm - 7) + $jd - 1;
    }

    /** @return int[] [jy, jm, jd] */
    private static function d2j(int $jdn): array
    {
        $gy = self::d2g($jdn)[0];
        $jy = $gy - 621;
        $r = self::jalCal($jy);
        $jdn1f = self::g2d($gy, 3, $r['march']);
        $k = $jdn - $jdn1f;
        if ($k >= 0) {
            if ($k <= 185) {
                return [$jy, 1 + self::div($k, 31), self::mod($k, 31) + 1];
            }
            $k -= 186;
        } else {
            $jy--;
            $k += 179;
            if ($r['leap'] === 1) {
                $k++;
            }
        }
        return [$jy, 7 + self::div($k, 30), self::mod($k, 30) + 1];
    }

    /** @return int[] [jy, jm, jd] */
    public static function toJalali(int $gy, int $gm, int $gd): array
    {
        return self::d2j(self::g2d($gy, $gm, $gd));
    }

    /** @return int[] [gy, gm, gd] */
    public static function toGregorian(int $jy, int $jm, int $jd): array
    {
        return self::d2g(self::j2d($jy, $jm, $jd));
    }

    public static function isLeapYear(int $jy): bool
    {
        return self::jalCal($jy)['leap'] === 0;
    }

    public static function monthLength(int $jy, int $jm): int
    {
        if ($jm <= 6) {
            return 31;
        }
        if ($jm <= 11) {
            return 30;
        }
        return self::isLeapYear($jy) ? 30 : 29;
    }

    public static function isValid(int $jy, int $jm, int $jd): bool
    {
        return $jy >= 1 && $jy <= 3177 && $jm >= 1 && $jm <= 12 && $jd >= 1 && $jd <= self::monthLength($jy, $jm);
    }

    /**
     * Parse «1405/08/20» (any digits, / - . separators) to [jy, jm, jd]; null when invalid.
     * @return int[]|null
     */
    public static function parse(string $value): ?array
    {
        $value = Fa::latin(trim($value));
        if (!preg_match('#^(\d{4})\s*[/\-.]\s*(\d{1,2})\s*[/\-.]\s*(\d{1,2})$#', $value, $m)) {
            return null;
        }
        $jy = (int) $m[1];
        $jm = (int) $m[2];
        $jd = (int) $m[3];
        return self::isValid($jy, $jm, $jd) ? [$jy, $jm, $jd] : null;
    }

    /** Jalali date string -> 'Y-m-d' Gregorian, or null. */
    public static function toGregorianString(string $value): ?string
    {
        $p = self::parse($value);
        if ($p === null) {
            return null;
        }
        $g = self::toGregorian($p[0], $p[1], $p[2]);
        return sprintf('%04d-%02d-%02d', $g[0], $g[1], $g[2]);
    }

    /**
     * Offset of the display time zone in seconds. Iran abolished DST in 2022; a fixed
     * +03:30 avoids wrong summer times on servers with an outdated tz database.
     */
    public static function offsetSeconds(int $timestamp): int
    {
        static $tzName = null, $tz = null;
        if ($tzName === null) {
            $tzName = 'Asia/Tehran';
            try {
                $tzName = (string) \Fava\Domain\Settings::get('timezone', 'Asia/Tehran');
            } catch (\Throwable $e) {
                $tzName = 'Asia/Tehran';
            }
        }
        if ($tzName === 'Asia/Tehran') {
            return 12600;
        }
        try {
            if ($tz === null) {
                $tz = new \DateTimeZone($tzName);
            }
            return $tz->getOffset(new \DateTime('@' . $timestamp));
        } catch (\Throwable $e) {
            return 12600;
        }
    }

    /**
     * date()-like formatting in the Jalali calendar and the display time zone.
     * Supported: Y y m n d j F l H G i s
     */
    public static function format(string $format, ?int $timestamp = null): string
    {
        $timestamp = $timestamp === null ? time() : $timestamp;
        $local = $timestamp + self::offsetSeconds($timestamp);
        list($jy, $jm, $jd) = self::toJalali((int) gmdate('Y', $local), (int) gmdate('n', $local), (int) gmdate('j', $local));
        $out = '';
        $len = strlen($format);
        for ($i = 0; $i < $len; $i++) {
            $c = $format[$i];
            switch ($c) {
                case 'Y':
                    $out .= $jy;
                    break;
                case 'y':
                    $out .= substr((string) $jy, -2);
                    break;
                case 'm':
                    $out .= sprintf('%02d', $jm);
                    break;
                case 'n':
                    $out .= $jm;
                    break;
                case 'd':
                    $out .= sprintf('%02d', $jd);
                    break;
                case 'j':
                    $out .= $jd;
                    break;
                case 'F':
                    $out .= self::MONTHS[$jm];
                    break;
                case 'l':
                    $out .= self::WEEKDAYS[(int) gmdate('w', $local)];
                    break;
                case 'H':
                case 'G':
                case 'i':
                case 's':
                    $out .= gmdate($c, $local);
                    break;
                case '\\':
                    $i++;
                    $out .= $i < $len ? $format[$i] : '';
                    break;
                default:
                    $out .= $c;
            }
        }
        return $out;
    }

    /** Today's Jalali date as «1405/08/20» (Latin digits). */
    public static function today(): string
    {
        return self::format('Y/m/d');
    }
}
