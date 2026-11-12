<?php

namespace Fava\Domain;

use Fava\Core\DB;

/**
 * Turns the two submitted forms of a proposal into the system result and keeps the
 * proposal's status in step with what has happened.
 */
class Results
{
    /**
     * Recalculate after anything that can change the outcome (a submission, a reopened
     * form, the admin fixing the amount or the tier). Safe to call any number of times.
     *
     * @return array|null the result, or null while a form is still missing
     */
    public static function recompute(int $proposalId): ?array
    {
        $db = DB::get();
        $p = Proposals::find($proposalId);
        if (!$p) {
            return null;
        }
        $as = Assignments::forProposal($proposalId);
        $both = $as['tech'] && $as['biz'] && $as['tech']['status'] === 'submitted' && $as['biz']['status'] === 'submitted';

        if (!$both) {
            if ($p['result_json'] !== null || $p['finalized_at'] !== null) {
                $db->update('proposals', [
                    'result_json' => null, 'total_score' => null, 'system_decision' => null, 'result_tier' => null,
                    'result_amount' => null, 'needs_resolution' => 0, 'computed_at' => null,
                    'final_decision' => null, 'final_amount' => null, 'final_note' => null,
                    'finalized_at' => null, 'finalized_by' => null, 'updated_at' => now_utc(),
                    // the admin chose a tier between two specific opinions; one of them is gone now
                    'tier_override' => null,
                ], 'id = ?', [$proposalId]);
            }
            Proposals::refreshStatus($proposalId);
            return null;
        }

        $cfg = Proposals::config($p);
        $adminAmount = $p['requested_amount'] !== null && (float) $p['requested_amount'] > 0 ? (float) $p['requested_amount'] : null;
        $tierOverride = !empty($p['tier_override']) ? (string) $p['tier_override'] : null;
        $result = Scoring::aggregate(Assignments::answers($as['tech']), Assignments::answers($as['biz']), $cfg, $adminAmount, $tierOverride);

        $db->update('proposals', [
            'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'total_score' => $result['total'],
            'system_decision' => $result['decision'],
            'result_tier' => $result['tier'],
            'result_amount' => $result['amount'],
            'needs_resolution' => $result['needs_resolution'] ? 1 : 0,
            'computed_at' => now_utc(),
            'updated_at' => now_utc(),
        ], 'id = ?', [$proposalId]);
        Proposals::refreshStatus($proposalId);
        return $result;
    }

    public static function of(array $proposal): ?array
    {
        $r = json_in($proposal['result_json'] ?? '', null);
        return is_array($r) ? $r : null;
    }

    /**
     * Record the admin's final decision.
     * @return array errors (field => message)
     */
    public static function finalize(int $proposalId, string $decision, ?float $amount, string $note, ?int $adminId): array
    {
        $p = Proposals::find($proposalId);
        $result = $p ? self::of($p) : null;
        $errors = [];
        if (!$result) {
            $errors['decision'] = 'تا زمانی که هر دو داور فرم خود را ثبت نکرده‌اند، تصمیم نهایی قابل ثبت نیست.';
            return $errors;
        }
        if ($result['needs_resolution']) {
            $errors['decision'] = 'نظر دو داور درباره سطح طرح یا مبلغ درخواستی یکسان نیست. ابتدا آن را در بخش «نتیجه تجمیع دو داوری» مشخص کنید.';
            return $errors;
        }
        if (!in_array($decision, ['approve', 'conditional', 'reject'], true)) {
            $errors['decision'] = 'تصمیم نهایی را انتخاب کنید.';
        }
        if ($decision === 'reject') {
            $amount = 0.0;
        } elseif ($amount === null || $amount < 0) {
            $errors['amount'] = 'مبلغ حمایتی نهایی را وارد کنید.';
        }
        $changed = $decision !== $result['decision'] || ($amount !== null && abs($amount - (float) $result['amount']) > 0.0005);
        if ($changed && trim($note) === '') {
            $errors['note'] = 'چون تصمیم یا مبلغ با محاسبه سامانه تفاوت دارد، دلیل آن را بنویسید.';
        }
        if ($errors) {
            return $errors;
        }
        DB::get()->update('proposals', [
            'final_decision' => $decision,
            'final_amount' => round((float) $amount, 3),
            'final_note' => trim($note) !== '' ? mb_substr(trim($note), 0, 5000) : null,
            'finalized_at' => now_utc(),
            'finalized_by' => $adminId,
            'updated_at' => now_utc(),
        ], 'id = ?', [$proposalId]);
        Proposals::refreshStatus($proposalId);
        return [];
    }

    public static function unfinalize(int $proposalId): void
    {
        DB::get()->update('proposals', [
            'final_decision' => null, 'final_amount' => null, 'final_note' => null,
            'finalized_at' => null, 'finalized_by' => null, 'updated_at' => now_utc(),
        ], 'id = ?', [$proposalId]);
        Proposals::refreshStatus($proposalId);
    }

    /** Figures for the dashboard. */
    public static function summary(): array
    {
        $db = DB::get();
        $out = [
            'decisions' => ['approve' => 0, 'conditional' => 0, 'reject' => 0],
            'final_decisions' => ['approve' => 0, 'conditional' => 0, 'reject' => 0],
            'avg_score' => null,
            'final_amount' => 0.0,
            'system_amount' => 0.0,
            'needs_resolution' => 0,
        ];
        // the outcome that counts: the admin's final decision where there is one, the system's proposal otherwise
        foreach ($db->all('SELECT COALESCE(final_decision, system_decision) AS d, COUNT(*) AS n FROM {proposals} WHERE system_decision IS NOT NULL GROUP BY COALESCE(final_decision, system_decision)') as $r) {
            if (isset($out['decisions'][$r['d']])) {
                $out['decisions'][$r['d']] = (int) $r['n'];
            }
        }
        foreach ($db->all('SELECT final_decision, COUNT(*) AS n FROM {proposals} WHERE final_decision IS NOT NULL GROUP BY final_decision') as $r) {
            if (isset($out['final_decisions'][$r['final_decision']])) {
                $out['final_decisions'][$r['final_decision']] = (int) $r['n'];
            }
        }
        $avg = $db->val('SELECT AVG(total_score) FROM {proposals} WHERE total_score IS NOT NULL');
        $out['avg_score'] = $avg !== null ? round((float) $avg, 1) : null;
        $out['final_amount'] = (float) $db->val('SELECT COALESCE(SUM(final_amount), 0) FROM {proposals} WHERE finalized_at IS NOT NULL');
        $out['system_amount'] = (float) $db->val('SELECT COALESCE(SUM(result_amount), 0) FROM {proposals} WHERE result_amount IS NOT NULL');
        $out['needs_resolution'] = (int) $db->val("SELECT COUNT(*) FROM {proposals} WHERE needs_resolution = 1 AND status = 'completed'");
        return $out;
    }
}
