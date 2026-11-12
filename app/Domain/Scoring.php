<?php

namespace Fava\Domain;

use Fava\Core\Fa;
use Fava\Core\Jalali;

/**
 * All calculations of the assessment form, as pure functions.
 * assets/js/scoring.js mirrors judge() so the judge sees in the browser exactly what
 * the server computes; tests/ScoringTest compares the two implementations.
 */
class Scoring
{
    /** Avoid 59.99999999 vs 60 surprises at the decision thresholds. */
    private static function r6(float $v): float
    {
        return round($v, 6);
    }

    public static function tier(?string $scalability, ?string $priority): ?string
    {
        if (!$scalability || !$priority) {
            return null;
        }
        if ($scalability === 'high' && $priority === 'high') {
            return 'A';
        }
        if ($scalability === 'low' && $priority === 'normal') {
            return 'C';
        }
        return 'B';
    }

    /** TRL 1–3 → 1, 4–6 → 3, 7–9 → 5 */
    public static function trlRisk($trl): ?int
    {
        $trl = (int) $trl;
        if ($trl < 1 || $trl > 9) {
            return null;
        }
        return $trl <= 3 ? 1 : ($trl <= 6 ? 3 : 5);
    }

    public static function coefficient(float $riskAvg, array $cfg): float
    {
        return self::r6($cfg['risk']['base'] + ($riskAvg - 1) * $cfg['risk']['step']);
    }

    public static function decision(float $total, array $cfg): string
    {
        $total = self::r6($total);
        if ($total < $cfg['thresholds']['reject_below']) {
            return 'reject';
        }
        if ($total < $cfg['thresholds']['conditional_below']) {
            return 'conditional';
        }
        return 'approve';
    }

    /** Support amount for a score: 0 when rejected, otherwise base × score/100 × coefficient (capped at base). */
    public static function amount(float $requested, float $ceiling, float $total, float $coeff, array $cfg): array
    {
        $base = min($requested, $ceiling);
        if (self::decision($total, $cfg) === 'reject') {
            return ['base' => $base, 'raw' => 0.0, 'amount' => 0.0, 'capped' => false];
        }
        $raw = self::r6($base * ($total / 100) * $coeff);
        $capped = !empty($cfg['cap_amount']) && $raw > $base;
        return ['base' => $base, 'raw' => $raw, 'amount' => $capped ? $base : $raw, 'capped' => $capped];
    }

    /**
     * Metrics of one judge's form.
     *
     * @param array      $answers   normalised answers (see validate())
     * @param float|null $fixedAmount requested amount set by the admin (overrides the judge's own figure)
     */
    public static function judge(string $role, array $answers, array $cfg, ?float $fixedAmount = null): array
    {
        $def = FormDef::get();
        $dims = [];
        $complete = true;
        $share = 0.0;
        $weightSum = 0.0;
        foreach ($def['roles'][$role]['dims'] as $dim) {
            $ids = array_keys($def['dimensions'][$dim]['criteria']);
            $sum = 0;
            $filled = 0;
            foreach ($ids as $id) {
                $v = isset($answers['scores'][$id]) ? (int) $answers['scores'][$id] : 0;
                if ($v >= 1 && $v <= 5) {
                    $sum += $v;
                    $filled++;
                }
            }
            $dimComplete = $filled === count($ids);
            $avg = count($ids) ? $sum / count($ids) : 0.0; // unanswered criteria count as 0, as in the original form
            $weight = (float) $cfg['weights'][$dim];
            $points = self::r6($avg * $weight * 20);
            $dims[$dim] = [
                'avg' => self::r6($avg),
                'complete' => $dimComplete,
                'weight' => $weight,
                'points' => $points,
                'max' => self::r6($weight * 100),
            ];
            $complete = $complete && $dimComplete;
            $share += $avg * $weight * 20;
            $weightSum += $weight;
        }
        $share = self::r6($share);
        $shareMax = self::r6($weightSum * 100);
        // “if the other judge scores alike” estimate of the total out of 100
        $estimatedTotal = ($complete && $weightSum > 0) ? self::r6($share / $weightSum) : null;
        $estimatedDecision = $estimatedTotal !== null ? self::decision($estimatedTotal, $cfg) : null;

        $tier = self::tier($answers['scalability'] ?? null, $answers['priority'] ?? null);
        $ceiling = $tier ? (float) $cfg['tiers'][$tier]['ceiling'] : null;

        $trl = null;
        $trlRisk = null;
        $riskExec = null;
        $riskMarket = null;
        $riskAvg = null;
        if ($role === 'tech') {
            $trl = isset($answers['trl']) && $answers['trl'] !== null ? (int) $answers['trl'] : null;
            $trlRisk = $trl !== null ? self::trlRisk($trl) : null;
            $riskExec = self::riskValue($answers['risk_exec'] ?? null);
            if ($trlRisk !== null && $riskExec !== null) {
                $riskAvg = ($trlRisk + $riskExec) / 2;
            }
        } else {
            $riskMarket = self::riskValue($answers['risk_market'] ?? null);
            if ($riskMarket !== null) {
                $riskAvg = (float) $riskMarket;
            }
        }
        $coeff = $riskAvg !== null ? self::coefficient($riskAvg, $cfg) : null;

        $requested = $fixedAmount !== null ? $fixedAmount : (isset($answers['requested_amount']) && $answers['requested_amount'] !== null ? (float) $answers['requested_amount'] : null);
        $estimate = null;
        if ($estimatedTotal !== null && $requested !== null && $requested > 0 && $ceiling !== null && $coeff !== null) {
            $estimate = self::amount($requested, $ceiling, $estimatedTotal, $coeff, $cfg)['amount'];
        }

        return [
            'role' => $role,
            'dims' => $dims,
            'complete' => $complete,
            'share' => $share,
            'share_max' => $shareMax,
            'estimated_total' => $estimatedTotal,
            'estimated_decision' => $estimatedDecision,
            'tier' => $tier,
            'ceiling' => $ceiling,
            'trl' => $trl,
            'trl_risk' => $trlRisk,
            'risk_exec' => $riskExec,
            'risk_market' => $riskMarket,
            'risk_avg' => $riskAvg !== null ? self::r6($riskAvg) : null,
            'coeff' => $coeff,
            'requested' => $requested,
            'estimate' => $estimate,
        ];
    }

    private static function riskValue($v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        $v = (int) $v;
        return in_array($v, [1, 3, 5], true) ? $v : null;
    }

    /**
     * Combine the two judges' forms into the system result.
     *
     * @param array      $techAnswers / $bizAnswers normalised answers of each judge
     * @param float|null $adminAmount  requested amount fixed by the admin (null = use the judges' figures)
     * @param string|null $tierOverride tier chosen by the admin when the judges disagree
     */
    public static function aggregate(array $techAnswers, array $bizAnswers, array $cfg, ?float $adminAmount = null, ?string $tierOverride = null): array
    {
        $tech = self::judge('tech', $techAnswers, $cfg, $adminAmount);
        $biz = self::judge('biz', $bizAnswers, $cfg, $adminAmount);
        $def = FormDef::get();

        $dims = [];
        foreach ($def['dimensions'] as $dim => $d) {
            $src = $d['role'] === 'tech' ? $tech : $biz;
            $dims[$dim] = $src['dims'][$dim] + ['role' => $d['role']];
        }
        $total = self::r6($tech['share'] + $biz['share']);
        $decision = self::decision($total, $cfg);

        // --- tier ----------------------------------------------------------------
        $tierConflict = $tech['tier'] !== $biz['tier'];
        if (!$tierConflict) {
            // the judges agree: their tier stands (a left-over admin choice is ignored)
            $tier = $tech['tier'];
            $tierSource = 'agreed';
        } elseif ($tierOverride !== null && isset($cfg['tiers'][$tierOverride])) {
            $tier = $tierOverride;
            $tierSource = 'admin';
        } else {
            // provisional: the tier with the lower ceiling, until the admin decides
            $tier = $cfg['tiers'][$tech['tier']]['ceiling'] <= $cfg['tiers'][$biz['tier']]['ceiling'] ? $tech['tier'] : $biz['tier'];
            $tierSource = 'provisional';
        }
        $ceiling = (float) $cfg['tiers'][$tier]['ceiling'];

        // --- requested amount ------------------------------------------------------
        $reqTech = isset($techAnswers['requested_amount']) && $techAnswers['requested_amount'] !== null ? (float) $techAnswers['requested_amount'] : null;
        $reqBiz = isset($bizAnswers['requested_amount']) && $bizAnswers['requested_amount'] !== null ? (float) $bizAnswers['requested_amount'] : null;
        $amountConflict = false;
        if ($adminAmount !== null && $adminAmount > 0) {
            $requested = $adminAmount;
            $requestedSource = 'admin';
        } elseif ($reqTech !== null && $reqBiz !== null && abs($reqTech - $reqBiz) < 0.0005) {
            $requested = $reqTech;
            $requestedSource = 'agreed';
        } else {
            $candidates = array_values(array_filter([$reqTech, $reqBiz], function ($v) {
                return $v !== null && $v > 0;
            }));
            $requested = $candidates ? min($candidates) : 0.0;
            $requestedSource = 'provisional';
            $amountConflict = true;
        }

        // --- risk ----------------------------------------------------------------------
        $risks = [$tech['trl_risk'], $tech['risk_exec'], $biz['risk_market']];
        $riskAvg = self::r6(array_sum($risks) / 3);
        $coeff = self::coefficient($riskAvg, $cfg);

        // --- amount --------------------------------------------------------------------
        $a = self::amount($requested, $ceiling, $total, $coeff, $cfg);
        $payments = [];
        foreach ($cfg['payment'] as $i => $pct) {
            $payments[] = ['pct' => $pct, 'amount' => round($a['amount'] * $pct / 100, 3)];
        }

        $order = ['reject' => 0, 'conditional' => 1, 'approve' => 2];
        $divergence = abs($order[$tech['estimated_decision']] - $order[$biz['estimated_decision']]) >= 2;

        return [
            'dims' => $dims,
            'tech_share' => $tech['share'],
            'biz_share' => $biz['share'],
            'total' => round($total, 2),
            'decision' => $decision,
            'tier_tech' => $tech['tier'],
            'tier_biz' => $biz['tier'],
            'tier' => $tier,
            'tier_conflict' => $tierConflict,
            'tier_source' => $tierSource,
            'ceiling' => $ceiling,
            'requested_tech' => $reqTech,
            'requested_biz' => $reqBiz,
            'requested' => $requested,
            'requested_source' => $requestedSource,
            'amount_conflict' => $amountConflict,
            'trl' => $tech['trl'],
            'trl_risk' => $tech['trl_risk'],
            'risk_exec' => $tech['risk_exec'],
            'risk_market' => $biz['risk_market'],
            'risk_avg' => $riskAvg,
            'coeff' => $coeff,
            'base' => $a['base'],
            'amount_raw' => round($a['raw'], 3),
            'amount' => round($a['amount'], 3),
            'capped' => $a['capped'],
            'payments' => $payments,
            'needs_resolution' => ($tierSource === 'provisional') || $amountConflict,
            'divergence' => $divergence,
            'est_tech' => $tech['estimated_decision'],
            'est_biz' => $biz['estimated_decision'],
            'tech' => $tech,
            'biz' => $biz,
        ];
    }

    // ---- input handling --------------------------------------------------------------

    private static function text($v, int $max = 5000): string
    {
        if (!is_string($v)) {
            return '';
        }
        $v = str_replace(["\r\n", "\r"], "\n", $v);
        $v = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
        return mb_substr(trim($v), 0, $max);
    }

    /**
     * Bring whatever the browser sent into the canonical shape, dropping anything
     * that does not belong to the role. Used for drafts and before validation.
     */
    public static function normalize(string $role, array $in): array
    {
        $def = FormDef::get();
        $out = [
            'scalability' => isset($in['scalability']) && isset($def['scalability'][$in['scalability']]) ? (string) $in['scalability'] : null,
            'priority' => isset($in['priority']) && isset($def['priority'][$in['priority']]) ? (string) $in['priority'] : null,
            'tier_notes' => self::text($in['tier_notes'] ?? ''),
            'scores' => [],
            'score_notes' => self::text($in['score_notes'] ?? ''),
            'reasons' => [],
            'commitment' => !empty($in['commitment']) && $in['commitment'] !== 'false' && $in['commitment'] !== '0',
            'name' => self::text($in['name'] ?? '', 150),
            'date' => Fa::latin(self::text($in['date'] ?? '', 20)),
            'requested_amount' => null,
            'q' => [],
        ];
        $parsedDate = Jalali::parse($out['date']);
        if ($parsedDate !== null) {
            $out['date'] = sprintf('%04d/%02d/%02d', $parsedDate[0], $parsedDate[1], $parsedDate[2]);
        }
        $scores = isset($in['scores']) && is_array($in['scores']) ? $in['scores'] : [];
        foreach (FormDef::roleCriteria($role) as $id) {
            $v = isset($scores[$id]) && is_scalar($scores[$id]) ? (int) Fa::latin((string) $scores[$id]) : 0;
            if ($v >= 1 && $v <= 5) {
                $out['scores'][$id] = $v;
            }
        }
        if (isset($in['requested_amount']) && $in['requested_amount'] !== '' && $in['requested_amount'] !== null) {
            $n = Fa::number(is_scalar($in['requested_amount']) ? (string) $in['requested_amount'] : '');
            if ($n !== null && $n > 0 && $n <= 1000000) {
                $out['requested_amount'] = round($n, 3);
            }
        }
        if (isset($in['reasons']) && is_array($in['reasons'])) {
            foreach (array_slice($in['reasons'], 0, 40) as $r) {
                $r = self::text($r, 2000);
                if ($r !== '') {
                    $out['reasons'][] = $r;
                }
            }
        }
        $q = isset($in['q']) && is_array($in['q']) ? $in['q'] : [];
        foreach ($def['roles'][$role]['questions'] as $key) {
            $out['q'][$key] = self::text($q[$key] ?? '');
        }
        if ($role === 'tech') {
            $trl = isset($in['trl']) && is_scalar($in['trl']) ? (int) Fa::latin((string) $in['trl']) : 0;
            $out['trl'] = $trl >= 1 && $trl <= 9 ? $trl : null;
            $out['risk_exec'] = self::riskValue(isset($in['risk_exec']) && is_scalar($in['risk_exec']) ? Fa::latin((string) $in['risk_exec']) : null);
            $out['milestone2'] = self::text($in['milestone2'] ?? '');
        } else {
            $out['risk_market'] = self::riskValue(isset($in['risk_market']) && is_scalar($in['risk_market']) ? Fa::latin((string) $in['risk_market']) : null);
        }
        return $out;
    }

    /**
     * Validate a final submission.
     * @return array field => message (empty when the form is complete)
     */
    public static function validate(string $role, array $a, array $cfg, ?float $fixedAmount = null): array
    {
        $def = FormDef::get();
        $errors = [];
        if (($a['scalability'] ?? null) === null) {
            $errors['scalability'] = 'سطح مقیاس‌پذیری را انتخاب کنید.';
        }
        if (($a['priority'] ?? null) === null) {
            $errors['priority'] = 'اولویت استراتژیک را انتخاب کنید.';
        }
        $missing = 0;
        foreach (FormDef::roleCriteria($role) as $id) {
            if (!isset($a['scores'][$id])) {
                $missing++;
            }
        }
        if ($missing > 0) {
            $errors['scores'] = Fa::digits((string) $missing) . ' معیار امتیازدهی بدون امتیاز مانده است.';
        }
        if ($role === 'tech') {
            if (($a['trl'] ?? null) === null) {
                $errors['trl'] = 'سطح آمادگی فناوری (TRL) را انتخاب کنید.';
            }
            if (($a['risk_exec'] ?? null) === null) {
                $errors['risk_exec'] = 'ریسک اجرا را انتخاب کنید.';
            }
        } elseif (($a['risk_market'] ?? null) === null) {
            $errors['risk_market'] = 'ریسک بازار را انتخاب کنید.';
        }
        if ($fixedAmount === null && (($a['requested_amount'] ?? null) === null || $a['requested_amount'] <= 0)) {
            $errors['requested_amount'] = 'مبلغ درخواستی شرکت را (به میلیارد ریال) وارد کنید.';
        }
        if (empty($a['reasons'])) {
            $errors['reasons'] = 'در بخش جمع‌بندی حداقل یک بولت با متن لازم است.';
        }
        if (empty($a['commitment'])) {
            $errors['commitment'] = 'تأیید تعهدنامه الزامی است.';
        }
        if (($a['name'] ?? '') === '') {
            $errors['name'] = 'نام و نام خانوادگی داور را وارد کنید.';
        }
        if (Jalali::parse((string) ($a['date'] ?? '')) === null) {
            $errors['date'] = 'تاریخ تکمیل فرم را به شکل ۱۴۰۵/۰۸/۲۰ وارد کنید.';
        }
        if ($role === 'tech' && !$errors) {
            $m = self::judge($role, $a, $cfg, $fixedAmount);
            if ($m['estimated_decision'] !== 'reject' && ($a['milestone2'] ?? '') === '') {
                $errors['milestone2'] = 'تعریف مایلستون فنی مرحله ۲ برای طرح‌های تأییدشده یا مشروط الزامی است.';
            }
        }
        return $errors;
    }
}
