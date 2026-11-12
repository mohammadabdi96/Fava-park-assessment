<?php

namespace Fava\Domain;

/**
 * Persian labels and colours of the statuses, in one place.
 */
class Labels
{
    /** Process steps of a proposal, in order. */
    public const PROPOSAL = [
        'review' => ['بررسی اطلاعات', 'amber', 'اطلاعات استخراج‌شده از فایل باید توسط مدیر بررسی و تأیید شود.'],
        'ready' => ['آماده ارسال', 'sky', 'اطلاعات تأیید شده است؛ داوران را تعیین و پیوندها را ارسال کنید.'],
        'judging' => ['در حال داوری', 'indigo', 'پیوند برای داوران ارسال شده و در انتظار ثبت فرم‌ها است.'],
        'completed' => ['در انتظار تصمیم', 'violet', 'هر دو داور فرم را ثبت کرده‌اند؛ نتیجه را بررسی و تصمیم نهایی را ثبت کنید.'],
        'finalized' => ['نهایی‌شده', 'emerald', 'تصمیم نهایی ثبت شده است.'],
    ];

    public const ASSIGNMENT = [
        'none' => ['تعیین نشده', 'gray'],
        'pending' => ['ارسال نشده', 'gray'],
        'sent' => ['ارسال شده', 'sky'],
        'opened' => ['مشاهده شده', 'indigo'],
        'draft' => ['در حال تکمیل', 'amber'],
        'submitted' => ['ثبت شده', 'emerald'],
    ];

    public const EXTRACT = [
        'ok' => ['استخراج کامل', 'emerald'],
        'partial' => ['استخراج ناقص', 'amber'],
        'failed' => ['استخراج ناموفق', 'red'],
    ];

    public const DECISION_COLORS = ['approve' => 'emerald', 'conditional' => 'amber', 'reject' => 'red'];

    public static function proposal(string $status): array
    {
        return self::PROPOSAL[$status] ?? [$status, 'gray', ''];
    }

    public static function assignment(?string $status): array
    {
        return self::ASSIGNMENT[$status ?? 'none'] ?? [$status, 'gray'];
    }

    public static function stepIndex(string $status): int
    {
        $i = array_search($status, array_keys(self::PROPOSAL), true);
        return $i === false ? 0 : (int) $i;
    }

    public static function tierShort(?string $tier, array $cfg): string
    {
        if (!$tier || !isset($cfg['tiers'][$tier])) {
            return '—';
        }
        return $cfg['tiers'][$tier]['short'] ?? $tier;
    }
}
