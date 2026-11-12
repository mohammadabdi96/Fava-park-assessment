<?php
/**
 * Global helper functions used by controllers and views.
 */

use Fava\Core\Config;
use Fava\Core\Csrf;
use Fava\Core\Fa;
use Fava\Core\Jalali;
use Fava\Core\Request;
use Fava\Core\Session;
use Fava\Domain\Settings;

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

// ---- PHP 7.4 polyfills ---------------------------------------------------
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle)
    {
        return $needle === '' || strpos((string) $haystack, (string) $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle)
    {
        return strncmp((string) $haystack, (string) $needle, strlen((string) $needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle)
    {
        $needle = (string) $needle;
        return $needle === '' || substr((string) $haystack, -strlen($needle)) === $needle;
    }
}

// ---- Output ----------------------------------------------------------------

/** HTML-escape. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Latin digits -> Persian digits (for display only). */
function fa($value): string
{
    return Fa::digits((string) $value);
}

/**
 * Format a number for display with Persian digits.
 * $decimals = null keeps up to $max decimals and drops trailing zeros.
 */
function nf($number, ?int $decimals = null, bool $group = true, int $max = 2): string
{
    if ($number === null || $number === '') {
        return '—';
    }
    $number = (float) $number;
    if ($decimals === null) {
        $s = number_format($number, $max, '.', $group ? ',' : '');
        if (strpos($s, '.') !== false) {
            $s = rtrim(rtrim($s, '0'), '.');
        }
    } else {
        $s = number_format($number, $decimals, '.', $group ? ',' : '');
    }
    return Fa::digits(str_replace(',', '٬', $s));
}

/**
 * Amount in billion rials, e.g. «۲۲ میلیارد ریال».
 * Amounts are calculated and stored to three decimals (one million rials), so that is
 * what is shown: the figure on the screen is exactly the figure the system works with.
 */
function money($amount, ?int $decimals = null): string
{
    if ($amount === null || $amount === '') {
        return '—';
    }
    return nf($amount, $decimals, true, 3) . ' میلیارد ریال';
}

/** Billion rials expressed in tomans, as a helper hint. */
function toman_hint($amount): string
{
    if ($amount === null || $amount === '') {
        return '';
    }
    $millionToman = (float) $amount * 100; // 1 billion rial = 100 million toman
    if ($millionToman >= 1000) {
        return nf($millionToman / 1000, null, true, 3) . ' میلیارد تومان';
    }
    return nf($millionToman, null, true, 1) . ' میلیون تومان';
}

/** UTC datetime string (or timestamp) -> Jalali date in the configured time zone, Persian digits. */
function jdate($value, string $format = 'Y/m/d H:i'): string
{
    if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
        return '—';
    }
    $ts = is_numeric($value) ? (int) $value : strtotime($value . ' UTC');
    if ($ts === false) {
        return '—';
    }
    return Fa::digits(Jalali::format($format, $ts));
}

/** “3 minutes ago” style relative time in Persian. */
function ago($value): string
{
    if (!$value) {
        return '—';
    }
    $ts = is_numeric($value) ? (int) $value : strtotime($value . ' UTC');
    $diff = time() - $ts;
    if ($diff < 0) {
        $diff = 0;
    }
    if ($diff < 60) {
        return 'لحظاتی پیش';
    }
    if ($diff < 3600) {
        return fa((string) floor($diff / 60)) . ' دقیقه پیش';
    }
    if ($diff < 86400) {
        return fa((string) floor($diff / 3600)) . ' ساعت پیش';
    }
    if ($diff < 86400 * 30) {
        return fa((string) floor($diff / 86400)) . ' روز پیش';
    }
    return jdate($ts, 'Y/m/d');
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function str_limit($value, int $limit = 80, string $end = '…'): string
{
    $value = (string) $value;
    if (mb_strlen($value) <= $limit) {
        return $value;
    }
    return rtrim(mb_substr($value, 0, $limit)) . $end;
}

/** Multiline plain text -> escaped HTML with <br>. */
function nl2p($value): string
{
    return nl2br(e(trim((string) $value)));
}

// ---- URLs ------------------------------------------------------------------

/** Application-relative URL (works in a sub-folder, with or without mod_rewrite). */
function url(string $path = '/', array $query = []): string
{
    $path = '/' . ltrim($path, '/');
    $base = Request::basePath();
    if (Config::get('pretty_urls', true)) {
        $u = $base . ($path === '/' ? '/' : $path);
        if ($query) {
            $u .= '?' . http_build_query($query);
        }
        return $u;
    }
    $u = $base . '/index.php?r=' . str_replace('%2F', '/', rawurlencode($path));
    if ($query) {
        $u .= '&' . http_build_query($query);
    }
    return $u;
}

/** Absolute URL – used in e-mails and for links the admin copies. */
function abs_url(string $path = '/', array $query = []): string
{
    $root = '';
    try {
        $root = (string) Settings::get('app_url', '');
    } catch (\Throwable $e) {
        $root = '';
    }
    if ($root === '') {
        return Request::origin() . url($path, $query);
    }
    $root = rtrim($root, '/');
    $path = '/' . ltrim($path, '/');
    if (Config::get('pretty_urls', true)) {
        $u = $root . ($path === '/' ? '/' : $path);
        return $query ? $u . '?' . http_build_query($query) : $u;
    }
    $u = $root . '/index.php?r=' . str_replace('%2F', '/', rawurlencode($path));
    return $query ? $u . '&' . http_build_query($query) : $u;
}

/** Hidden field needed by GET forms when pretty URLs are off. */
function route_field(string $path): string
{
    if (Config::get('pretty_urls', true)) {
        return '';
    }
    return '<input type="hidden" name="r" value="' . e('/' . ltrim($path, '/')) . '" hidden>';
}

/** Static asset URL with a cache-busting version. */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = APP_ROOT . '/assets/' . $path;
    $v = is_file($file) ? substr(md5((string) filemtime($file) . APP_VERSION), 0, 8) : APP_VERSION;
    return Request::basePath() . '/assets/' . $path . '?v=' . $v;
}

function redirect(string $path, array $query = []): void
{
    $target = preg_match('#^https?://#i', $path) ? $path : url($path, $query);
    header('Location: ' . $target, true, 302);
    exit;
}

function back(string $fallback = '/admin'): void
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    $origin = Request::origin();
    if ($ref !== '' && strncmp($ref, $origin, strlen($origin)) === 0) {
        header('Location: ' . $ref, true, 302);
        exit;
    }
    redirect($fallback);
}

// ---- Session-backed helpers --------------------------------------------

function csrf_token(): string
{
    return Csrf::token();
}

function csrf_field(): string
{
    // the "hidden" attribute keeps the field out of CSS spacing rules between form rows
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '" hidden>';
}

function flash(string $type, string $message): void
{
    Session::flash($type, $message);
}

function setting(string $key, $default = null)
{
    return Settings::get($key, $default);
}

/** Previously submitted value (after a validation error). */
function old(string $key, $default = '')
{
    $old = Session::get('_old', []);
    return array_key_exists($key, $old) ? $old[$key] : $default;
}

// ---- Misc ------------------------------------------------------------------

function role_label(string $role): string
{
    return $role === 'tech' ? 'داور فنی' : 'داور کسب‌وکاری';
}

function role_short(string $role): string
{
    return $role === 'tech' ? 'فنی' : 'کسب‌وکاری';
}

function json_out($data): string
{
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_PARTIAL_OUTPUT_ON_ERROR);
}

function json_in($string, $default = [])
{
    if (!is_string($string) || $string === '') {
        return $default;
    }
    $data = json_decode($string, true);
    return $data === null ? $default : $data;
}

function random_token(int $bytes = 24): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

/** Human readable file size with Persian digits. */
function filesize_h($bytes): string
{
    $bytes = (float) $bytes;
    if ($bytes >= 1048576) {
        $mb = round($bytes / 1048576, 1);
        return nf($mb, floor($mb) == $mb ? 0 : 1) . ' مگابایت';
    }
    return nf(max(1, round($bytes / 1024)), 0) . ' کیلوبایت';
}

/** "20M" style php.ini values -> bytes. */
function ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $num = (float) $value;
    switch ($unit) {
        case 'g':
            $num *= 1024;
            // no break
        case 'm':
            $num *= 1024;
            // no break
        case 'k':
            $num *= 1024;
    }
    return (int) $num;
}

// ---- Small UI helpers --------------------------------------------------------

/** Coloured pill. Colours: gray, amber, sky, indigo, violet, emerald, red. */
function badge(string $label, string $color = 'gray', string $extra = ''): string
{
    return '<span class="badge badge-' . e($color) . ($extra !== '' ? ' ' . e($extra) : '') . '">' . e($label) . '</span>';
}

function proposal_badge(string $status): string
{
    $l = \Fava\Domain\Labels::proposal($status);
    return badge($l[0], $l[1]);
}

function assignment_badge(?string $status): string
{
    $l = \Fava\Domain\Labels::assignment($status);
    return badge($l[0], $l[1]);
}

function decision_badge(?string $decision): string
{
    if (!$decision) {
        return '<span class="text-slate-400">—</span>';
    }
    $d = \Fava\Domain\FormDef::decision($decision);
    return badge($d['label'], \Fava\Domain\Labels::DECISION_COLORS[$decision] ?? 'gray');
}

/** Inline SVG icon (Heroicons outline paths). */
function icon(string $name, string $class = 'w-5 h-5'): string
{
    static $paths = [
        'home' => 'M2.25 12l8.954-8.955a1.126 1.126 0 011.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25',
        'doc' => 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z',
        'users' => 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z',
        'clock' => 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z',
        'cog' => 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z M15 12a3 3 0 11-6 0 3 3 0 016 0z',
        'upload' => 'M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5',
        'download' => 'M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3',
        'check' => 'M4.5 12.75l6 6 9-13.5',
        'check-circle' => 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        'x' => 'M6 18L18 6M6 6l12 12',
        'warn' => 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z',
        'info' => 'M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z',
        'mail' => 'M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75',
        'link' => 'M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244',
        'copy' => 'M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 00-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 01-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 00-3.375-3.375h-1.5a1.125 1.125 0 01-1.125-1.125v-1.5a3.375 3.375 0 00-3.375-3.375H9.75',
        'eye' => 'M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z M15 12a3 3 0 11-6 0 3 3 0 016 0z',
        'print' => 'M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0021 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 00-1.913-.247M6.34 18H5.25A2.25 2.25 0 013 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 011.913-.247m10.5 0a48.536 48.536 0 00-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5zm-3 0h.008v.008H15V10.5z',
        'trash' => 'M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0',
        'refresh' => 'M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99',
        'plus' => 'M12 4.5v15m7.5-7.5h-15',
        'search' => 'M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z',
        'logout' => 'M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75',
        'chart' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
        'send' => 'M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5',
        'pencil' => 'M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10',
        'lock' => 'M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z',
        'scale' => 'M12 3v17.25m0 0c-1.472 0-2.882.265-4.185.75M12 20.25c1.472 0 2.882.265 4.185.75M18.75 4.97A48.416 48.416 0 0012 4.5c-2.291 0-4.545.16-6.75.47m13.5 0c1.01.143 2.01.317 3 .52m-3-.52l2.62 10.726c.122.499-.106 1.028-.589 1.202a5.988 5.988 0 01-2.031.352 5.988 5.988 0 01-2.031-.352c-.483-.174-.711-.703-.59-1.202L18.75 4.971zm-16.5.52c.99-.203 1.99-.377 3-.52m0 0l2.62 10.726c.122.499-.106 1.028-.589 1.202a5.989 5.989 0 01-2.031.352 5.989 5.989 0 01-2.031-.352c-.483-.174-.711-.703-.59-1.202L5.25 4.971z',
        'menu' => 'M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5',
        'chevron' => 'M15.75 19.5L8.25 12l7.5-7.5',
        'flag' => 'M3 3v1.5M3 21v-6m0 0l2.77-.693a9 9 0 016.208.682l.108.054a9 9 0 006.086.71l3.114-.732a48.524 48.524 0 01-.005-10.499l-3.11.732a9 9 0 01-6.085-.711l-.108-.054a9 9 0 00-6.208-.682L3 4.5M3 15V4.5',
        'table' => 'M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 01-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0112 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125M3.375 8.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125m17.25-3.75h-7.5c-.621 0-1.125.504-1.125 1.125m8.625-1.125c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125M12 10.875v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 10.875c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125M13.125 12h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125M20.625 12c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5M12 14.625v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 14.625c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125m0 1.5v-1.5m0 0c0-.621.504-1.125 1.125-1.125m0 0h7.5',
    ];
    $d = $paths[$name] ?? $paths['info'];
    $html = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" class="' . e($class) . '" aria-hidden="true">';
    foreach (explode(' M', $d) as $i => $part) {
        $html .= '<path stroke-linecap="round" stroke-linejoin="round" d="' . ($i === 0 ? '' : 'M') . $part . '"/>';
    }
    return $html . '</svg>';
}

/**
 * Shared hosts often switch PHP functions off (disable_functions). Since PHP 8 a disabled
 * function does not exist at all, so calling it ends the request with a fatal error and
 * «@» does not help. Everything that is merely nice to have goes through these two.
 */
function can_call(string $function): bool
{
    return function_exists($function);
}

function try_ini_set(string $key, string $value): void
{
    if (function_exists('ini_set')) {
        @ini_set($key, $value);
    }
}

/** Environment variable, or false when it is not set or the host does not allow reading it. */
function env_value(string $name)
{
    return function_exists('getenv') ? getenv($name) : false;
}

/** Send the response to the browser and keep working (used for slow follow-up work such as e-mail). */
function finish_request(): void
{
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        @litespeed_finish_request();
    }
}

/** Content-Disposition value that keeps Persian file names intact. */
function content_disposition(string $filename, string $type = 'attachment'): string
{
    $filename = (string) preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/u', '_', $filename);
    // plain-ASCII fallback for old clients; a name without any Latin letter becomes «file.ext»
    $ext = preg_match('/\.[A-Za-z0-9]{1,5}$/', $filename, $m) ? $m[0] : '';
    $stem = $ext !== '' ? substr($filename, 0, -strlen($ext)) : $filename;
    $stem = trim((string) preg_replace('/[^\x20-\x7E]+/', '_', $stem), ' ._');
    $stem = (string) preg_replace('/[_ ]{2,}/', '_', $stem);
    $ascii = (preg_match('/[A-Za-z0-9]/', $stem) ? $stem : 'file') . $ext;
    return $type . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
}
