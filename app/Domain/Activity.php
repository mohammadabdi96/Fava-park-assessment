<?php

namespace Fava\Domain;

use Fava\Core\Auth;
use Fava\Core\DB;
use Fava\Core\Logger;
use Fava\Core\Request;

/**
 * The audit trail the admin sees in «گزارش فعالیت‌ها» and on every proposal page.
 */
class Activity
{
    public const LABELS = [
        'install' => 'نصب سامانه',
        'login' => 'ورود مدیر',
        'upload' => 'بارگذاری طرح',
        'extract' => 'استخراج اطلاعات',
        'update' => 'ویرایش اطلاعات طرح',
        'review' => 'تأیید اطلاعات طرح',
        'assign' => 'تعیین داور',
        'unassign' => 'حذف داور',
        'link' => 'پیوند داوری',
        'mail' => 'ارسال ایمیل',
        'mail_fail' => 'خطای ارسال ایمیل',
        'open' => 'بازشدن فرم',
        'draft' => 'شروع تکمیل فرم',
        'submit' => 'ثبت فرم داور',
        'reopen' => 'بازگشایی فرم',
        'result' => 'محاسبه نتیجه',
        'resolve' => 'رفع اختلاف',
        'finalize' => 'تصمیم نهایی',
        'unfinalize' => 'لغو تصمیم نهایی',
        'delete' => 'حذف طرح',
        'judge' => 'فهرست داوران',
        'settings' => 'تنظیمات',
    ];

    /**
     * @param string|null $actorType admin | judge | system (default: admin when someone is logged in)
     */
    public static function log(string $action, string $message, ?int $proposalId = null, ?string $actorType = null, ?string $actorName = null): void
    {
        try {
            if ($actorType === null) {
                $actorType = Auth::check() ? 'admin' : 'system';
            }
            if ($actorName === null && $actorType === 'admin') {
                $actorName = Auth::name();
            }
            DB::get()->insert('activity_log', [
                'created_at' => now_utc(),
                'actor_type' => $actorType,
                'actor_name' => $actorName !== null ? mb_substr($actorName, 0, 150) : null,
                'proposal_id' => $proposalId,
                'action' => $action,
                'message' => mb_substr($message, 0, 2000),
                'ip' => Request::ip() ?: null,
            ]);
        } catch (\Throwable $e) {
            // the audit trail must never break the action it describes
            Logger::exception($e, 'activity log');
        }
    }

    public static function forProposal(int $proposalId, int $limit = 100): array
    {
        return DB::get()->all(
            'SELECT * FROM {activity_log} WHERE proposal_id = ? ORDER BY id DESC LIMIT ' . (int) $limit,
            [$proposalId]
        );
    }

    public static function recent(int $limit = 12): array
    {
        return DB::get()->all(
            'SELECT a.*, p.title AS proposal_title, p.code AS proposal_code FROM {activity_log} a '
            . 'LEFT JOIN {proposals} p ON p.id = a.proposal_id ORDER BY a.id DESC LIMIT ' . (int) $limit
        );
    }

    /** @return array{0:array,1:int} rows, total */
    public static function page(int $page, int $perPage, string $action = ''): array
    {
        $db = DB::get();
        $where = '';
        $params = [];
        if ($action !== '' && isset(self::LABELS[$action])) {
            $where = ' WHERE a.action = ?';
            $params[] = $action;
        }
        $total = (int) $db->val('SELECT COUNT(*) FROM {activity_log} a' . $where, $params);
        $offset = max(0, ($page - 1) * $perPage);
        $rows = $db->all(
            'SELECT a.*, p.title AS proposal_title, p.code AS proposal_code FROM {activity_log} a '
            . 'LEFT JOIN {proposals} p ON p.id = a.proposal_id' . $where
            . ' ORDER BY a.id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        );
        return [$rows, $total];
    }

    public static function label(string $action): string
    {
        return self::LABELS[$action] ?? $action;
    }
}
