<?php

namespace Fava\Domain;

use Fava\Core\DB;
use Fava\Core\Fa;

/**
 * The directory of judges the admin picks from when assigning a proposal.
 */
class Judges
{
    public const EXPERTISE = ['tech' => 'فنی', 'biz' => 'کسب‌وکاری', 'both' => 'فنی و کسب‌وکاری'];

    public static function find(int $id): ?array
    {
        return DB::get()->one('SELECT * FROM {judges} WHERE id = ?', [$id]);
    }

    /** All judges with the number of open and submitted forms. */
    public static function all(): array
    {
        $db = DB::get();
        $judges = $db->all('SELECT * FROM {judges} ORDER BY is_active DESC, name ASC');
        $stats = [];
        foreach ($db->all("SELECT judge_id, status, COUNT(*) AS n FROM {assignments} WHERE judge_id IS NOT NULL GROUP BY judge_id, status") as $row) {
            $k = $row['status'] === 'submitted' ? 'submitted' : 'open';
            $stats[(int) $row['judge_id']][$k] = ($stats[(int) $row['judge_id']][$k] ?? 0) + (int) $row['n'];
        }
        foreach ($judges as &$j) {
            $j['open'] = $stats[(int) $j['id']]['open'] ?? 0;
            $j['submitted'] = $stats[(int) $j['id']]['submitted'] ?? 0;
        }
        unset($j);
        return $judges;
    }

    /** Active judges that can take a role. */
    public static function forRole(string $role): array
    {
        return DB::get()->all(
            "SELECT id, name, email, mobile, organization, expertise FROM {judges} WHERE is_active = 1 AND (expertise = ? OR expertise = 'both') ORDER BY name ASC",
            [$role]
        );
    }

    /**
     * Validate and normalise judge input.
     * @return array{0:array,1:array} [clean data, errors]
     */
    public static function validate(array $in): array
    {
        $errors = [];
        $name = Fa::clean((string) ($in['name'] ?? ''));
        $email = mb_strtolower(trim(Fa::latin((string) ($in['email'] ?? ''))));
        $mobile = preg_replace('/[^\d+]/', '', Fa::latin((string) ($in['mobile'] ?? '')));
        $expertise = (string) ($in['expertise'] ?? 'both');
        if ($name === '') {
            $errors['name'] = 'نام داور را وارد کنید.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'نشانی ایمیل معتبر نیست.';
        }
        if (!isset(self::EXPERTISE[$expertise])) {
            $expertise = 'both';
        }
        return [[
            'name' => mb_substr($name, 0, 150),
            'email' => $email !== '' ? mb_substr($email, 0, 190) : null,
            'mobile' => $mobile !== '' ? mb_substr((string) $mobile, 0, 30) : null,
            'organization' => mb_substr(Fa::clean((string) ($in['organization'] ?? '')), 0, 190) ?: null,
            'expertise' => $expertise,
            'notes' => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 2000) ?: null,
        ], $errors];
    }

    public static function create(array $data): int
    {
        $now = now_utc();
        return DB::get()->insert('judges', $data + ['is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
    }

    public static function update(int $id, array $data): void
    {
        DB::get()->update('judges', $data + ['updated_at' => now_utc()], 'id = ?', [$id]);
    }

    public static function findByEmail(string $email): ?array
    {
        return DB::get()->one('SELECT * FROM {judges} WHERE email = ? ORDER BY id ASC LIMIT 1', [mb_strtolower($email)]);
    }

    /** A judge with forms on record is only deactivated, so history stays readable. */
    public static function deleteOrDeactivate(int $id): string
    {
        $db = DB::get();
        $used = (int) $db->val('SELECT COUNT(*) FROM {assignments} WHERE judge_id = ?', [$id]);
        if ($used > 0) {
            $db->update('judges', ['is_active' => 0, 'updated_at' => now_utc()], 'id = ?', [$id]);
            return 'deactivated';
        }
        $db->delete('judges', 'id = ?', [$id]);
        return 'deleted';
    }
}
