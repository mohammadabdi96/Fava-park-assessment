<?php

use Fava\Domain\FormDef;
use Fava\Domain\Scoring;

/** Scoring parameters as shipped (see app/form_definition.php). */
function cfg_default(): array
{
    return FormDef::get()['defaults'];
}

/** Complete, valid answers of a judge; $over replaces top-level keys. */
function answers_for(string $role, array $scores, array $over = []): array
{
    $a = [
        'scalability' => 'high',
        'priority' => 'high',
        'tier_notes' => '',
        'scores' => [],
        'score_notes' => '',
        'reasons' => ['طرح از نظر اینجانب قابل دفاع است.'],
        'commitment' => true,
        'name' => $role === 'tech' ? 'داور فنی آزمون' : 'داور کسب‌وکاری آزمون',
        'date' => '1405/08/20',
        'requested_amount' => null,
        'q' => [],
    ];
    $i = 0;
    foreach (FormDef::roleCriteria($role) as $id) {
        $a['scores'][$id] = $scores[$i % count($scores)];
        $i++;
    }
    if ($role === 'tech') {
        $a += ['trl' => 6, 'risk_exec' => 3, 'milestone2' => 'تحویل نسخه آزمایشی به سه مشتری'];
    } else {
        $a += ['risk_market' => 5];
    }
    return array_replace($a, $over);
}

group('Scoring rules');

test('tier comes from scalability × strategic priority', function () {
    eq('A', Scoring::tier('high', 'high'));
    eq('C', Scoring::tier('low', 'normal'));
    foreach ([['high', 'medium'], ['high', 'normal'], ['medium', 'high'], ['medium', 'medium'], ['medium', 'normal'], ['low', 'high'], ['low', 'medium']] as $c) {
        eq('B', Scoring::tier($c[0], $c[1]), implode(' × ', $c));
    }
    eq(null, Scoring::tier(null, 'high'));
    eq(null, Scoring::tier('high', ''));
});

test('TRL maps to a risk score of 1, 3 or 5', function () {
    eq([1, 1, 1, 3, 3, 3, 5, 5, 5], array_map([Scoring::class, 'trlRisk'], range(1, 9)));
    eq(null, Scoring::trlRisk(0));
    eq(null, Scoring::trlRisk(10));
    eq(null, Scoring::trlRisk(null));
});

test('risk coefficient runs from 0.7 to 1.3', function () {
    $cfg = cfg_default();
    near(0.7, Scoring::coefficient(1, $cfg));
    near(1.0, Scoring::coefficient(3, $cfg));
    near(1.3, Scoring::coefficient(5, $cfg));
    near(1.1, Scoring::coefficient(11 / 3, $cfg));
});

test('decision thresholds: below 40 reject, 40–59 conditional, 60 and above approve', function () {
    $cfg = cfg_default();
    eq('reject', Scoring::decision(0, $cfg));
    eq('reject', Scoring::decision(39.99, $cfg));
    eq('conditional', Scoring::decision(40, $cfg));
    eq('conditional', Scoring::decision(59.99, $cfg));
    eq('approve', Scoring::decision(60, $cfg));
    eq('approve', Scoring::decision(100, $cfg));
    // floating-point noise right at a threshold must not flip the decision
    eq('approve', Scoring::decision(59.99999999, $cfg));
    eq('conditional', Scoring::decision(39.9999999999, $cfg));
    eq('approve', Scoring::decision(0.1 * 3 * 200, $cfg), '0.1 × 3 × 200 = 60.00000000000001');
});

test('support amount = min(requested, ceiling) × score/100 × coefficient', function () {
    $cfg = cfg_default();
    $a = Scoring::amount(18.5, 30, 72, 1.1, $cfg);
    near(18.5, $a['base']);
    near(14.652, $a['amount']);
    eq(false, $a['capped']);

    $a = Scoring::amount(50, 15, 72, 1.1, $cfg);
    near(15.0, $a['base'], 'the tier ceiling limits the base');
    near(11.88, $a['amount']);

    $a = Scoring::amount(10, 30, 95, 1.3, $cfg);
    near(12.35, $a['raw']);
    near(10.0, $a['amount'], 'never more than what was requested');
    eq(true, $a['capped']);

    $cfg['cap_amount'] = false;
    near(12.35, Scoring::amount(10, 30, 95, 1.3, $cfg)['amount'], 'cap switched off in the settings');

    $a = Scoring::amount(20, 30, 39.9, 1.3, cfg_default());
    near(0.0, $a['amount'], 'a rejected proposal gets nothing');
});

group('One judge');

test('technical judge: the worked example of the demo', function () {
    $m = Scoring::judge('tech', answers_for('tech', [4, 5, 3, 4, 4]), cfg_default(), 18.5);
    eq(true, $m['complete']);
    near(4.0, $m['dims']['tech']['avg']);
    near(24.0, $m['dims']['tech']['points']);
    near(30.0, $m['dims']['tech']['max']);
    near(16.0, $m['dims']['team']['points']);
    near(40.0, $m['share']);
    near(50.0, $m['share_max']);
    near(80.0, $m['estimated_total']);
    eq('approve', $m['estimated_decision']);
    eq('A', $m['tier']);
    near(30.0, $m['ceiling']);
    eq(3, $m['trl_risk']);
    near(3.0, $m['risk_avg']);
    near(1.0, $m['coeff']);
    near(14.8, $m['estimate']);
});

test('business judge: the worked example of the demo', function () {
    $a = answers_for('biz', [3, 4, 3, 2, 4], ['scalability' => 'medium', 'priority' => 'medium']);
    $m = Scoring::judge('biz', $a, cfg_default(), 18.5);
    near(32.0, $m['share']);
    near(64.0, $m['estimated_total']);
    eq('B', $m['tier']);
    near(15.0, $m['ceiling']);
    near(1.3, $m['coeff']);
    near(12.48, $m['estimate']);
    eq(null, $m['trl']);
    eq(5, $m['risk_market']);
});

test('an unfinished form has a share but no estimate', function () {
    $a = answers_for('tech', [5]);
    unset($a['scores']['team-3']);
    $m = Scoring::judge('tech', $a, cfg_default(), 10.0);
    eq(false, $m['complete']);
    eq(false, $m['dims']['team']['complete']);
    eq(true, $m['dims']['tech']['complete']);
    near(46.0, $m['share'], 'a missing criterion counts as zero, as in the original form');
    eq(null, $m['estimated_total']);
    eq(null, $m['estimated_decision']);
    eq(null, $m['estimate']);

    $m = Scoring::judge('biz', ['scores' => []], cfg_default());
    near(0.0, $m['share']);
    eq(null, $m['tier']);
    eq(null, $m['coeff']);
    eq(null, $m['requested']);
});

test('the admin-fixed amount overrides what the judge typed', function () {
    $a = answers_for('biz', [4], ['requested_amount' => 99.0]);
    near(12.0, Scoring::judge('biz', $a, cfg_default(), 12.0)['requested']);
    near(99.0, Scoring::judge('biz', $a, cfg_default(), null)['requested']);
});

group('Both judges combined');

test('the worked example: 72 points, tier conflict, admin picks tier A', function () {
    $cfg = cfg_default();
    $tech = answers_for('tech', [4, 5, 3, 4, 4]);
    $biz = answers_for('biz', [3, 4, 3, 2, 4], ['scalability' => 'medium', 'priority' => 'medium']);

    $r = Scoring::aggregate($tech, $biz, $cfg, 18.5);
    near(72.0, $r['total']);
    eq('approve', $r['decision']);
    eq(true, $r['tier_conflict']);
    eq('provisional', $r['tier_source']);
    eq('B', $r['tier'], 'the lower tier applies until the admin decides');
    eq(true, $r['needs_resolution']);
    near(11 / 3, $r['risk_avg'], '', 1e-5);
    near(1.1, $r['coeff'], '', 1e-5);
    near(15.0, $r['base']);
    near(11.88, $r['amount']);
    eq('admin', $r['requested_source']);
    eq(false, $r['amount_conflict']);
    eq(false, $r['divergence']);
    eq(['tech', 'market', 'team', 'finance'], array_keys($r['dims']));
    near(100.0, array_sum(array_column($r['dims'], 'max')));

    $r = Scoring::aggregate($tech, $biz, $cfg, 18.5, 'A');
    eq('A', $r['tier']);
    eq('admin', $r['tier_source']);
    eq(false, $r['needs_resolution']);
    near(18.5, $r['base']);
    near(14.652, $r['amount']);
    eq([30.0, 40.0, 30.0], array_map('floatval', array_column($r['payments'], 'pct')));
    near(4.396, $r['payments'][0]['amount']);
    near(5.861, $r['payments'][1]['amount']);
    near(4.395, $r['payments'][2]['amount'], 'the last stage takes what is left: 30% would round to 4.396 and overpay by a million rials');
    near(14.652, array_sum(array_column($r['payments'], 'amount')), '', 1e-9);
});

test('when the judges agree on the tier a stale admin choice is ignored', function () {
    $r = Scoring::aggregate(answers_for('tech', [4]), answers_for('biz', [4]), cfg_default(), 20.0, 'C');
    eq('A', $r['tier']);
    eq('agreed', $r['tier_source']);
    eq(false, $r['tier_conflict']);
    eq(false, $r['needs_resolution']);
});

test('requested amount entered by the judges: agreement, disagreement, admin decision', function () {
    $cfg = cfg_default();
    $tech = answers_for('tech', [4], ['requested_amount' => 20.0]);
    $biz = answers_for('biz', [4], ['requested_amount' => 20.0]);
    $r = Scoring::aggregate($tech, $biz, $cfg, null);
    eq('agreed', $r['requested_source']);
    near(20.0, $r['requested']);
    eq(false, $r['needs_resolution']);

    $biz['requested_amount'] = 25.0;
    $r = Scoring::aggregate($tech, $biz, $cfg, null);
    eq(true, $r['amount_conflict']);
    eq('provisional', $r['requested_source']);
    near(20.0, $r['requested'], 'the lower figure is used provisionally');
    eq(true, $r['needs_resolution']);
    near(20.0, $r['requested_tech']);
    near(25.0, $r['requested_biz']);

    $r = Scoring::aggregate($tech, $biz, $cfg, 22.0);
    eq(false, $r['amount_conflict']);
    eq('admin', $r['requested_source']);
    near(22.0, $r['requested']);
    eq(false, $r['needs_resolution']);
});

test('a rejected proposal gets zero and all payments are zero', function () {
    $r = Scoring::aggregate(answers_for('tech', [2, 1]), answers_for('biz', [2, 1]), cfg_default(), 20.0);
    near(30.2, $r['total']);
    eq('reject', $r['decision']);
    near(0.0, $r['amount']);
    eq([0.0, 0.0, 0.0], array_map('floatval', array_column($r['payments'], 'amount')));
});

test('strongly different views of the two judges are flagged', function () {
    $r = Scoring::aggregate(answers_for('tech', [5]), answers_for('biz', [1]), cfg_default(), 20.0);
    eq('approve', $r['est_tech']);
    eq('reject', $r['est_biz']);
    eq(true, $r['divergence']);
    near(60.0, $r['total']);
    eq('approve', $r['decision']);
});

test('staged payments always add up to the amount, to the last million rials', function () {
    $amounts = array_column(Scoring::payments(14.652, [30, 40, 30]), 'amount');
    eq([4.396, 5.861, 4.395], $amounts);
    eq([5.0, 5.0, 0.0], array_column(Scoring::payments(10.0, [50, 50, 0]), 'amount'), 'a stage of 0% gets nothing; the one before closes the sum');
    eq([0.0, 0.0, 0.001], array_column(Scoring::payments(0.001, [30, 40, 30]), 'amount'), 'one million rials cannot be split');
    eq([0.0, 0.0, 0.0], array_column(Scoring::payments(0.0, [30, 40, 30]), 'amount'));
    eq([0.0, 0.0, 0.0], array_column(Scoring::payments(-5.0, [30, 40, 30]), 'amount'), 'never negative');
    eq([12.0, 0.0, 0.0], array_column(Scoring::payments(12.0, [100, 0, 0]), 'amount'));
    eq([3.0, 3.0, 3.0], array_column(Scoring::payments(10.0, [30, 30, 30]), 'amount'), 'percentages that do not make 100 are not stretched');

    mt_srand(20261111);
    for ($i = 0; $i < 4000; $i++) {
        $amount = mt_rand(0, 60000) / 1000;                       // 0 … 60 billion rials in steps of a million
        $a = mt_rand(0, 1000) / 10;
        $b = mt_rand(0, (int) round((100 - $a) * 10)) / 10;
        $pct = [$a, $b, round(100 - $a - $b, 1)];
        $parts = array_column(Scoring::payments($amount, $pct), 'amount');
        near($amount, array_sum($parts), "sum for $amount split " . implode('/', $pct), 1e-9);
        foreach ($parts as $k => $part) {
            ok($part >= 0, "stage $k of $amount split " . implode('/', $pct) . ' is negative');
            near($amount * $pct[$k] / 100, $part, "stage $k of $amount split " . implode('/', $pct), 0.001 + 1e-9);
            near($part, round($part, 3), 'whole millions of rials', 1e-12);
        }
    }
});

test('extreme scores stay inside 0–100 and payments add up to the amount', function () {
    $cfg = cfg_default();
    $r = Scoring::aggregate(answers_for('tech', [5], ['trl' => 9, 'risk_exec' => 5]), answers_for('biz', [5]), $cfg, 100.0);
    near(100.0, $r['total']);
    near(1.3, $r['coeff']);
    near(30.0, $r['amount'], 'capped at the tier ceiling');
    eq(true, $r['capped']);
    near($r['amount'], array_sum(array_column($r['payments'], 'amount')), '', 1e-9);

    $r = Scoring::aggregate(answers_for('tech', [1], ['trl' => 1, 'risk_exec' => 1]), answers_for('biz', [1], ['risk_market' => 1]), $cfg, 100.0);
    near(20.0, $r['total']);
    near(0.7, $r['coeff']);
});

test('changed parameters from the settings are honoured', function () {
    $cfg = FormDef::mergeConfig(cfg_default(), [
        'tiers' => ['A' => ['ceiling' => 50]],
        'thresholds' => ['reject_below' => 50, 'conditional_below' => 75],
        'weights' => ['tech' => 0.4, 'market' => 0.2, 'team' => 0.2, 'finance' => 0.2],
        'payment' => [50, 25, 25],
    ]);
    $r = Scoring::aggregate(answers_for('tech', [4, 5, 3, 4, 4]), answers_for('biz', [3, 4, 3, 2, 4]), $cfg, 40.0);
    near(73.6, $r['total']);
    eq('conditional', $r['decision']);
    near(50.0, $r['ceiling']);
    eq([50.0, 25.0, 25.0], array_map('floatval', array_column($r['payments'], 'pct')));
});

test('damaged settings cannot break the calculation', function () {
    $base = cfg_default();
    $cfg = FormDef::mergeConfig($base, [
        'tiers' => ['A' => ['ceiling' => -5], 'B' => ['ceiling' => 'abc'], 'X' => ['ceiling' => 1]],
        'weights' => ['tech' => 0.9, 'market' => 0.9, 'team' => 0.1, 'finance' => 0.1],
        'payment' => [50, 60],
        'thresholds' => ['reject_below' => 'x'],
        'risk' => 'nonsense',
    ]);
    eq($base['tiers'], $cfg['tiers']);
    eq($base['weights'], $cfg['weights'], 'weights that do not add up to 1 are refused');
    eq($base['payment'], $cfg['payment']);
    eq($base['thresholds'], $cfg['thresholds']);
    eq($base['risk'], $cfg['risk']);
});

group('What a judge may send');

test('normalize keeps only what belongs to the role', function () {
    $in = [
        'scalability' => 'high', 'priority' => 'bogus',
        'scores' => ['tech-1' => '۵', 'tech-2' => 9, 'tech-3' => 0, 'market-1' => 4, 'evil' => 3, 'team-1' => '3'],
        'trl' => '۷', 'risk_exec' => '5', 'risk_market' => 5,
        'requested_amount' => '۱۸٫۵',
        'reasons' => ['  دلیل یک ', '', ['array'], 'دلیل دو'],
        'commitment' => 'true', 'name' => "  رضا \x07فنی‌پور ", 'date' => '۱۴۰۵/۸/۵',
        'q' => ['q1' => 'نظر', 'q4' => 'نباید بماند'],
        'unknown' => 'x', 'milestone2' => 'm',
    ];
    $a = Scoring::normalize('tech', $in);
    eq('high', $a['scalability']);
    eq(null, $a['priority']);
    eq(['tech-1' => 5, 'team-1' => 3], $a['scores']);
    eq(7, $a['trl']);
    eq(5, $a['risk_exec']);
    ok(!array_key_exists('risk_market', $a));
    ok(!array_key_exists('unknown', $a));
    near(18.5, $a['requested_amount']);
    eq(['دلیل یک', 'دلیل دو'], $a['reasons']);
    eq(true, $a['commitment']);
    eq('رضا فنی‌پور', $a['name']);
    eq('1405/08/05', $a['date']);
    eq(['q1' => 'نظر', 'q2' => '', 'q3' => ''], $a['q']);

    $b = Scoring::normalize('biz', $in);
    eq(['market-1' => 4], $b['scores']);
    eq(5, $b['risk_market']);
    ok(!array_key_exists('trl', $b));
    ok(!array_key_exists('milestone2', $b));
    eq(['q4' => 'نباید بماند', 'q5' => ''], $b['q']);
});

test('normalize survives hostile input', function () {
    $a = Scoring::normalize('tech', ['scores' => 'x', 'reasons' => 'y', 'q' => 5, 'name' => ['a'], 'trl' => [], 'requested_amount' => ['1'], 'commitment' => '0', 'date' => str_repeat('1', 500)]);
    eq([], $a['scores']);
    eq([], $a['reasons']);
    eq('', $a['name']);
    eq(null, $a['trl']);
    eq(null, $a['requested_amount']);
    eq(false, $a['commitment']);
    ok(strlen($a['date']) <= 20);
    $a = Scoring::normalize('biz', ['requested_amount' => '18,5', 'reasons' => array_fill(0, 100, str_repeat('ب', 5000))]);
    eq(null, $a['requested_amount'], 'an ambiguous amount is dropped, not guessed');
    eq(40, count($a['reasons']));
    eq(2000, mb_strlen($a['reasons'][0]));
    $a = Scoring::normalize('biz', ['requested_amount' => '-4']);
    eq(null, $a['requested_amount']);
});

test('a complete form passes validation', function () {
    eq([], Scoring::validate('tech', Scoring::normalize('tech', answers_for('tech', [4])), cfg_default(), 18.5));
    eq([], Scoring::validate('biz', Scoring::normalize('biz', answers_for('biz', [4])), cfg_default(), 18.5));
});

test('an empty form lists every required item', function () {
    $cfg = cfg_default();
    $e = Scoring::validate('tech', Scoring::normalize('tech', []), $cfg, null);
    eq(['scalability', 'priority', 'scores', 'trl', 'risk_exec', 'requested_amount', 'reasons', 'commitment', 'name', 'date', 'milestone2'], array_keys($e));
    contains('۱۰ معیار', $e['scores']);
    $e = Scoring::validate('biz', Scoring::normalize('biz', []), $cfg, 12.0);
    eq(['scalability', 'priority', 'scores', 'risk_market', 'reasons', 'commitment', 'name', 'date'], array_keys($e), 'no amount needed when the admin fixed it');
});

test('milestone 2 is required from the technical judge unless the estimate is a rejection', function () {
    $cfg = cfg_default();
    $a = Scoring::normalize('tech', answers_for('tech', [4], ['milestone2' => '']));
    eq(['milestone2'], array_keys(Scoring::validate('tech', $a, $cfg, 18.5)));
    $a = Scoring::normalize('tech', answers_for('tech', [1, 2], ['milestone2' => '']));
    eq([], Scoring::validate('tech', $a, $cfg, 18.5), 'estimate 30 → reject band');
    $a = Scoring::normalize('tech', answers_for('tech', [2], ['milestone2' => '']));
    eq(['milestone2'], array_keys(Scoring::validate('tech', $a, $cfg, 18.5)), 'estimate exactly 40 → conditional');
});

test('wrong dates and missing amounts are reported', function () {
    $cfg = cfg_default();
    $a = Scoring::normalize('biz', answers_for('biz', [3], ['date' => '1405/12/30']));
    eq(['date'], array_keys(Scoring::validate('biz', $a, $cfg, 10.0)));
    $a = Scoring::normalize('biz', answers_for('biz', [3]));
    eq(['requested_amount'], array_keys(Scoring::validate('biz', $a, $cfg, null)));
    $a = Scoring::normalize('biz', answers_for('biz', [3], ['requested_amount' => '12.5']));
    eq([], Scoring::validate('biz', $a, $cfg, null));
});

group('Browser copy of the rules (assets/js/scoring.js)');

test('PHP and JavaScript give the same result for 3000 random forms', function () {
    $node = trim((string) @shell_exec('command -v node 2>/dev/null'));
    if ($node === '') {
        skip('node is not installed');
    }
    mt_srand(20261111);
    $pick = function (array $options) {
        return $options[mt_rand(0, count($options) - 1)];
    };
    $cases = [];
    $expected = [];
    for ($i = 0; $i < 3000; $i++) {
        $role = $i % 2 ? 'tech' : 'biz';
        $cfg = cfg_default();
        if ($i % 5 === 0) {
            // random but valid parameters, as an admin could set them
            $w = [mt_rand(5, 60), mt_rand(5, 60), mt_rand(5, 60), mt_rand(5, 60)];
            $sum = array_sum($w);
            $cfg['weights'] = ['tech' => $w[0] / $sum, 'market' => $w[1] / $sum, 'team' => $w[2] / $sum, 'finance' => $w[3] / $sum];
            $cfg['thresholds'] = ['reject_below' => mt_rand(20, 50), 'conditional_below' => mt_rand(51, 85)];
            $cfg['tiers']['A']['ceiling'] = mt_rand(20, 60);
            $cfg['tiers']['B']['ceiling'] = mt_rand(10, 20);
            $cfg['tiers']['C']['ceiling'] = mt_rand(2, 10);
            $cfg['risk'] = ['base' => mt_rand(50, 90) / 100, 'step' => mt_rand(5, 20) / 100];
            $cfg['cap_amount'] = (bool) mt_rand(0, 1);
        }
        $a = [
            'scalability' => $pick(['high', 'medium', 'low', null]),
            'priority' => $pick(['high', 'medium', 'normal', null]),
            'scores' => [],
            'requested_amount' => $pick([null, 5.0, 12.5, 18.5, 33.333, 80.0, 0.75]),
        ];
        foreach (FormDef::roleCriteria($role) as $id) {
            if (mt_rand(0, 19) > 0) {
                $a['scores'][$id] = mt_rand(1, 5);
            }
        }
        if ($role === 'tech') {
            $a['trl'] = $pick([null, 1, 2, 3, 4, 5, 6, 7, 8, 9]);
            $a['risk_exec'] = $pick([null, 1, 3, 5]);
        } else {
            $a['risk_market'] = $pick([null, 1, 3, 5]);
        }
        $fixed = $pick([null, null, 9.0, 18.5, 41.2]);
        $total = mt_rand(0, 10000) / 100;
        $amountArgs = [mt_rand(1, 800) / 10, (float) mt_rand(5, 60), $total, mt_rand(50, 150) / 100];
        $cases[] = [
            'cfg' => FormDef::clientConfig($role, $cfg),
            'answers' => $a + ['scores' => (object) []],
            'fixed' => $fixed,
            'amount' => $amountArgs,
            'total' => $total,
        ];
        // an empty PHP array would become [] instead of {} in JSON
        if (!$a['scores']) {
            $cases[$i]['answers']['scores'] = (object) [];
        }
        $expected[] = [
            'judge' => Scoring::judge($role, $a, $cfg, $fixed),
            'amount' => Scoring::amount($amountArgs[0], $amountArgs[1], $amountArgs[2], $amountArgs[3], $cfg),
            'decision' => Scoring::decision($total, $cfg),
        ];
    }
    $file = tmp_dir() . '/cases.json';
    file_put_contents($file, json_encode($cases, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    $json = (string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/../js/judge.js') . ' ' . escapeshellarg($file) . ' 2>&1');
    $actual = json_decode($json, true);
    ok(is_array($actual) && count($actual) === count($expected), 'node did not return results: ' . mb_substr($json, 0, 400));

    $compare = function ($e, $a, string $path) use (&$compare) {
        if (is_array($e)) {
            if (!is_array($a)) {
                throw new TestFailure($path . ': PHP has ' . show($e) . ', JS has ' . show($a));
            }
            foreach ($e as $k => $v) {
                if (!array_key_exists($k, $a)) {
                    throw new TestFailure($path . '.' . $k . ' is missing in the JS result');
                }
                $compare($v, $a[$k], $path . '.' . $k);
            }
            return;
        }
        TestState::$assertions++;
        if (is_float($e) || is_int($e)) {
            if (!is_numeric($a) || (float) $e !== (float) $a) {
                throw new TestFailure($path . ': PHP ' . show($e) . ' ≠ JS ' . show($a));
            }
            return;
        }
        if ($e !== $a) {
            throw new TestFailure($path . ': PHP ' . show($e) . ' ≠ JS ' . show($a));
        }
    };
    foreach ($expected as $i => $e) {
        $compare($e, $actual[$i], 'case ' . $i);
    }
});

test('rounding is identical in PHP and in the browser copy', function () {
    $node = trim((string) @shell_exec('command -v node 2>/dev/null'));
    if ($node === '') {
        skip('node is not installed');
    }
    $values = [1.005, 2.675, 14.652, 11.88, 4.3956, 5.8608, 0.5, 1.5, 2.5, 72.0, 59.995, 0.1 + 0.2, 1234.5678, 18.2190085, 59.9999995, 0.0005, 14.6525];
    near(1.01, Scoring::round(1.005, 2), 'half rounds up even when the double is a hair below');
    near(2.68, Scoring::round(2.675, 2));
    near(14.653, Scoring::round(14.6525, 3));
    near(3.0, Scoring::round(2.5, 0));
    near(60.0, Scoring::round(59.9999995, 6));
    $script = 'var S=require(' . json_encode(realpath(__DIR__ . '/../../assets/js/scoring.js')) . ');'
        . 'console.log(JSON.stringify(' . json_encode($values) . '.map(function(v){return [S.round(v,0),S.round(v,1),S.round(v,2),S.round(v,3)];})));';
    $out = json_decode((string) shell_exec(escapeshellarg($node) . ' -e ' . escapeshellarg($script)), true);
    ok(is_array($out), 'node failed');
    foreach ($values as $i => $v) {
        foreach ([0, 1, 2, 3] as $d) {
            eq(Scoring::round($v, $d), (float) $out[$i][$d], 'round(' . $v . ', ' . $d . ')');
        }
    }
});
