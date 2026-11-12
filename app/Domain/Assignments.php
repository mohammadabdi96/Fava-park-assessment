<?php

namespace Fava\Domain;

use Fava\Core\DB;

/**
 * One assignment = one judge (technical or business) for one proposal, with the
 * private link, the draft and the submitted form.
 */
class Assignments
{
    /** @return array{tech:?array,biz:?array} */
    public static function forProposal(int $proposalId): array
    {
        $out = ['tech' => null, 'biz' => null];
        foreach (DB::get()->all('SELECT * FROM {assignments} WHERE proposal_id = ?', [$proposalId]) as $a) {
            if (isset($a['role']) && array_key_exists($a['role'], $out)) {
                $out[$a['role']] = $a;
            }
        }
        return $out;
    }

    public static function find(int $id): ?array
    {
        return DB::get()->one('SELECT * FROM {assignments} WHERE id = ?', [$id]);
    }

    public static function findByToken(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_\-]{20,64}$/', $token)) {
            return null;
        }
        $row = DB::get()->one('SELECT * FROM {assignments} WHERE token = ?', [$token]);
        // constant-time confirmation on top of the indexed lookup
        return ($row && hash_equals((string) $row['token'], $token)) ? $row : null;
    }

    public static function answers(?array $assignment): array
    {
        return $assignment ? json_in($assignment['answers_json'] ?? '', []) : [];
    }

    public static function draft(?array $assignment): array
    {
        return $assignment ? json_in($assignment['draft_json'] ?? '', []) : [];
    }

    /** Private form link of an assignment (absolute). */
    public static function link(array $assignment): string
    {
        return abs_url('/f/' . $assignment['token']);
    }

    private static function newToken(): string
    {
        do {
            $token = random_token(24); // 192 bits
        } while (DB::get()->val('SELECT COUNT(*) FROM {assignments} WHERE token = ?', [$token]));
        return $token;
    }

    /**
     * Set (or change) the judge of a role.
     *
     * @param array $judge ['id' => ?int, 'name' => string, 'email' => ?string, 'mobile' => ?string]
     * @return array{0:array,1:string} the assignment and what happened: created | updated | replaced
     */
    public static function assign(int $proposalId, string $role, array $judge): array
    {
        $db = DB::get();
        $now = now_utc();
        $existing = $db->one('SELECT * FROM {assignments} WHERE proposal_id = ? AND role = ?', [$proposalId, $role]);
        $data = [
            'judge_id' => !empty($judge['id']) ? (int) $judge['id'] : null,
            'judge_name' => mb_substr((string) $judge['name'], 0, 150),
            'judge_email' => !empty($judge['email']) ? mb_substr((string) $judge['email'], 0, 190) : null,
            'judge_mobile' => !empty($judge['mobile']) ? mb_substr((string) $judge['mobile'], 0, 30) : null,
            'updated_at' => $now,
        ];
        if (!$existing) {
            $data += [
                'proposal_id' => $proposalId,
                'role' => $role,
                'token' => self::newToken(),
                'status' => 'pending',
                'created_at' => $now,
            ];
            $id = $db->insert('assignments', $data);
            Proposals::freezeConfig($proposalId);
            return [self::find($id), 'created'];
        }
        if ($existing['status'] === 'submitted') {
            throw new \RuntimeException('این داور فرم خود را ثبت کرده است؛ برای تغییر داور ابتدا فرم را بازگشایی کنید.');
        }
        $samePerson = ((int) $existing['judge_id'] !== 0 && (int) $existing['judge_id'] === (int) $data['judge_id'])
            || ((int) $existing['judge_id'] === 0 && $data['judge_id'] === null && (string) $existing['judge_name'] === $data['judge_name']);
        $what = 'updated';
        if (!$samePerson) {
            // a different person: the old link must stop working and nothing of the previous judge may remain
            $data += [
                'token' => self::newToken(),
                'status' => 'pending',
                'sent_at' => null, 'last_sent_at' => null, 'sent_via' => null, 'send_count' => 0,
                'reminder_count' => 0, 'last_reminder_at' => null,
                'first_opened_at' => null, 'last_opened_at' => null, 'open_count' => 0,
                'draft_json' => null, 'draft_saved_at' => null,
            ];
            $what = 'replaced';
        }
        $db->update('assignments', $data, 'id = ?', [(int) $existing['id']]);
        return [self::find((int) $existing['id']), $what];
    }

    public static function remove(int $id): void
    {
        DB::get()->delete('assignments', 'id = ?', [$id]);
    }

    /** New link; the old one stops working. Draft and progress are kept. */
    public static function regenerate(int $id): array
    {
        DB::get()->update('assignments', ['token' => self::newToken(), 'updated_at' => now_utc()], 'id = ?', [$id]);
        return self::find($id);
    }

    public static function markSent(int $id, string $via, bool $reminder = false): void
    {
        $a = self::find($id);
        if (!$a) {
            return;
        }
        $now = now_utc();
        $data = ['last_sent_at' => $now, 'updated_at' => $now];
        if ($reminder) {
            $data['reminder_count'] = (int) $a['reminder_count'] + 1;
            $data['last_reminder_at'] = $now;
        } else {
            $data['send_count'] = (int) $a['send_count'] + 1;
        }
        if (empty($a['sent_at'])) {
            $data['sent_at'] = $now;
            $data['sent_via'] = $via;
        } elseif ($via === 'email') {
            $data['sent_via'] = 'email';
        }
        if ($a['status'] === 'pending') {
            $data['status'] = 'sent';
        }
        DB::get()->update('assignments', $data, 'id = ?', [$id]);
    }

    /** @return bool true when this was the first time the judge opened the form */
    public static function markOpened(array $a): bool
    {
        $now = now_utc();
        $first = empty($a['first_opened_at']);
        $data = ['last_opened_at' => $now, 'open_count' => (int) $a['open_count'] + 1];
        if ($first) {
            $data['first_opened_at'] = $now;
        }
        if (empty($a['sent_at'])) {
            // the admin copied the link and passed it on without recording it
            $data['sent_at'] = $now;
            $data['last_sent_at'] = $now;
            $data['sent_via'] = 'manual';
            $data['send_count'] = max(1, (int) $a['send_count']);
        }
        if (in_array($a['status'], ['pending', 'sent'], true)) {
            $data['status'] = 'opened';
        }
        DB::get()->update('assignments', $data, 'id = ?', [(int) $a['id']]);
        return $first;
    }

    /** @return bool true when this was the first saved draft */
    public static function saveDraft(array $a, array $answers): bool
    {
        $now = now_utc();
        $first = empty($a['draft_saved_at']);
        $data = [
            'draft_json' => json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'draft_saved_at' => $now,
            'updated_at' => $now,
        ];
        if (in_array($a['status'], ['pending', 'sent', 'opened'], true)) {
            $data['status'] = 'draft';
        }
        // only while the form is still open – a late autosave must never undo a submission
        DB::get()->update('assignments', $data, "id = ? AND status <> 'submitted'", [(int) $a['id']]);
        return $first;
    }

    /** @return bool false when the form had already been submitted by another request */
    public static function submit(array $a, array $answers, array $metrics, string $ip, string $ua): bool
    {
        $now = now_utc();
        $json = json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $changed = DB::get()->update('assignments', [
            'status' => 'submitted',
            'submitted_at' => $now,
            'answers_json' => $json,
            'draft_json' => $json,
            'draft_saved_at' => $now,
            'metrics_json' => json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'submit_ip' => $ip !== '' ? $ip : null,
            'submit_ua' => $ua !== '' ? mb_substr($ua, 0, 250) : null,
            'updated_at' => $now,
        ], "id = ? AND status <> 'submitted'", [(int) $a['id']]);
        return $changed > 0;
    }

    /** Let the judge edit and send the form again; the answers stay in the form as a draft. */
    public static function reopen(int $id): void
    {
        $a = self::find($id);
        if (!$a || $a['status'] !== 'submitted') {
            return;
        }
        $now = now_utc();
        DB::get()->update('assignments', [
            'status' => 'draft',
            'submitted_at' => null,
            'draft_json' => $a['answers_json'],
            'draft_saved_at' => $now,
            'answers_json' => null,
            'metrics_json' => null,
            'reopened_at' => $now,
            'reopen_count' => (int) $a['reopen_count'] + 1,
            'updated_at' => $now,
        ], 'id = ?', [$id]);
    }

    /** Assignments waiting for the judge, oldest first (dashboard). */
    public static function pending(int $limit = 20): array
    {
        return DB::get()->all(
            "SELECT a.id, a.proposal_id, a.role, a.judge_name, a.status, a.sent_at, a.first_opened_at, a.draft_saved_at, a.last_reminder_at, "
            . "p.title, p.code, p.due_at FROM {assignments} a JOIN {proposals} p ON p.id = a.proposal_id "
            . "WHERE a.status <> 'submitted' AND a.sent_at IS NOT NULL ORDER BY a.sent_at ASC LIMIT " . (int) $limit
        );
    }
}
