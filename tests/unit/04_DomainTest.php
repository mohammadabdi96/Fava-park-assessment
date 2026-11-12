<?php

use Fava\Core\Auth;
use Fava\Core\Config;
use Fava\Core\Crypto;
use Fava\Core\DB;
use Fava\Core\Schema;
use Fava\Docx\DocxException;
use Fava\Domain\Activity;
use Fava\Domain\Assignments;
use Fava\Domain\FormDef;
use Fava\Domain\Judges;
use Fava\Domain\Proposals;
use Fava\Domain\Results;
use Fava\Domain\Settings;

require_once __DIR__ . '/../support/db.php';

foreach (test_databases() as $dbLabel => $makeConfig) {
    group('Database and workflow — ' . $dbLabel);

    test('the schema installs on an empty database and can be run again safely', function () use ($makeConfig) {
        $db = fresh_app($makeConfig);
        eq(count(Schema::tables()), count(Schema::existingTables($db)));
        Schema::install($db);
        Schema::install($db);
        eq(count(Schema::tables()), count(Schema::existingTables($db)));
        eq(0, (int) $db->val('SELECT COUNT(*) FROM {proposals}'));
        ok($db->serverVersion() !== '');
    });

    test('settings keep Persian text, nested values and fall back to defaults', function () use ($makeConfig) {
        fresh_app($makeConfig);
        eq('پارک فاوا', Settings::get('org_name'));
        eq(20, Settings::get('max_upload_mb'));
        eq('none', Settings::get('mail')['transport']);
        Settings::set('org_name', "پارک علم و فناوری «فاوا» — O'Brien \\ 😀");
        Settings::set('mail', ['transport' => 'smtp', 'host' => 'mail.example.ir']);
        Settings::set('scoring', ['tiers' => ['A' => ['ceiling' => 40]]]);
        Settings::flush();
        eq("پارک علم و فناوری «فاوا» — O'Brien \\ 😀", Settings::get('org_name'));
        eq('smtp', Settings::get('mail')['transport']);
        eq(465, Settings::get('mail')['port'], 'missing mail keys come from the defaults');
        near(40.0, FormDef::currentConfig()['tiers']['A']['ceiling']);
        near(15.0, FormDef::currentConfig()['tiers']['B']['ceiling']);
        eq('fallback', Settings::get('no_such_key', 'fallback'));
    });

    test('uploading a worksheet creates a proposal with a tracking code', function () use ($makeConfig) {
        $db = fresh_app($makeConfig);
        $p = upload_fixture('sample-proposal-1.docx', 'کاربرگ شرکت البرز.docx');
        ok((bool) preg_match('/^P14\d\d-001$/', $p['code']), 'code: ' . $p['code']);
        eq('review', $p['status']);
        eq('ok', $p['extract_status']);
        eq('شرکت داده‌پردازان نوآور البرز', $p['company']);
        eq('کاربرگ شرکت البرز.docx', $p['original_name']);
        eq(null, $p['requested_amount']);
        eq(64, strlen($p['file_hash']));
        ok(is_file(Proposals::filePath($p)), 'the file is stored');
        eq(hash_file('sha256', fixture('sample-proposal-1.docx')), hash_file('sha256', Proposals::filePath($p)));
        ok((bool) preg_match('/^\d{8}-[0-9a-f]{24}\.docx$/', $p['stored_name']), 'stored under a random name: ' . $p['stored_name']);
        eq(6, Proposals::fields($p)['trl_declared']);
        eq(1, count(Proposals::warnings($p)));
        ok(count(Proposals::doc((int) $p['id'])['blocks']) > 10, 'the readable copy of the document is kept');

        $q = upload_fixture('sample-proposal-2.docx');
        ok(substr($q['code'], -3) === '002', 'second code: ' . $q['code']);
        eq(2, (int) $db->val('SELECT COUNT(*) FROM {proposals}'));
    });

    test('the same file is not stored twice', function () use ($makeConfig) {
        $db = fresh_app($makeConfig);
        $p = upload_fixture('sample-proposal-1.docx');
        $again = Proposals::createFromUpload(fixture('sample-proposal-1.docx'), 'another-name.docx', null, false);
        eq('duplicate', $again['status']);
        eq((int) $p['id'], $again['id']);
        eq($p['code'], $again['duplicate']['code']);
        eq(1, (int) $db->val('SELECT COUNT(*) FROM {proposals}'));
        eq(1, count(glob(Proposals::uploadDir() . '/*.docx')));
    });

    test('wrong files are refused and leave nothing behind', function () use ($makeConfig) {
        $db = fresh_app($makeConfig);
        $before = count(glob(Proposals::uploadDir() . '/*') ?: []);
        $e = throws(function () {
            Proposals::createFromUpload(fixture('not-a-docx.docx'), 'x.docx', null, false);
        }, DocxException::class);
        contains('معتبر نیست', $e->getMessage());
        throws(function () {
            Proposals::createFromUpload(fixture('evil-doctype.docx'), 'x.docx', null, false);
        }, DocxException::class);
        $e = throws(function () {
            Proposals::createFromUpload(fixture('sample-proposal-1.docx'), 'old-format.doc', null, false);
        }, DocxException::class);
        contains('Save As', $e->getMessage());
        $e = throws(function () {
            Proposals::createFromUpload(fixture('sample-proposal-1.docx'), 'shell.php', null, false);
        }, DocxException::class);
        contains('docx', $e->getMessage());
        $empty = tmp_dir() . '/empty.docx';
        touch($empty);
        throws(function () use ($empty) {
            Proposals::createFromUpload($empty, 'empty.docx', null, false);
        }, DocxException::class);
        eq(0, (int) $db->val('SELECT COUNT(*) FROM {proposals}'));
        eq($before, count(glob(Proposals::uploadDir() . '/*') ?: []));
    });

    test('file names from the browser are cleaned', function () {
        eq('کاربرگ.docx', Proposals::cleanName('C:\\Users\\علی\\Desktop\\کاربرگ.docx'));
        eq('passwd.docx', Proposals::cleanName('../../etc/passwd.docx'));
        eq('ab.docx', Proposals::cleanName("a\x00b\r\n.docx"));
        eq('file.docx', Proposals::cleanName(''));
        eq(250, mb_strlen(Proposals::cleanName(str_repeat('ن', 400) . '.docx')));
    });

    test('status follows the facts: review → ready → judging → completed → finalized', function () use ($makeConfig) {
        fresh_app($makeConfig);
        $p = upload_fixture('sample-proposal-1.docx');
        $id = (int) $p['id'];
        eq('review', Proposals::refreshStatus($id));

        review_proposal($id, 18.5);
        eq('ready', Proposals::find($id)['status']);

        list($tech, $what) = Assignments::assign($id, 'tech', ['id' => null, 'name' => 'دکتر رضا فنی‌پور', 'email' => 'tech@example.ir', 'mobile' => null]);
        eq('created', $what);
        eq('pending', $tech['status']);
        Assignments::assign($id, 'biz', ['id' => null, 'name' => 'مهندس مریم بازارگان', 'email' => null, 'mobile' => '09120000000']);
        eq('ready', Proposals::refreshStatus($id), 'assigned but not yet sent');

        Assignments::markSent((int) $tech['id'], 'email');
        eq('judging', Proposals::refreshStatus($id));
        eq('sent', Assignments::find((int) $tech['id'])['status']);

        $p = submit_form($id, 'tech', [4, 5, 3, 4, 4]);
        eq('judging', $p['status']);
        eq(null, $p['result_json'], 'no result while one form is missing');

        $p = submit_form($id, 'biz', [3, 4, 3, 2, 4], ['scalability' => 'medium', 'priority' => 'medium']);
        eq('completed', $p['status']);
        near(72.0, (float) $p['total_score']);
        eq('approve', $p['system_decision']);
        eq('B', $p['result_tier']);
        near(11.88, (float) $p['result_amount']);
        eq(1, (int) $p['needs_resolution']);

        eq(['decision'], array_keys(Results::finalize($id, 'approve', 11.88, '', null)), 'a tier conflict must be resolved first');

        DB::get()->update('proposals', ['tier_override' => 'A'], 'id = ?', [$id]);
        $r = Results::recompute($id);
        eq('A', $r['tier']);
        near(14.652, $r['amount']);
        eq(0, (int) Proposals::find($id)['needs_resolution']);

        eq(['note'], array_keys(Results::finalize($id, 'conditional', 12.0, '', null)), 'a different decision needs a reason');
        eq(['amount'], array_keys(Results::finalize($id, 'approve', null, 'x', null)));
        eq(['decision'], array_keys(Results::finalize($id, 'maybe', 1.0, 'x', null)));
        eq([], Results::finalize($id, 'approve', 14.652, '', null), 'the system result can be confirmed without a note');
        $p = Proposals::find($id);
        eq('finalized', $p['status']);
        eq('approve', $p['final_decision']);
        near(14.652, (float) $p['final_amount']);

        Results::unfinalize($id);
        $p = Proposals::find($id);
        eq('completed', $p['status']);
        eq(null, $p['final_decision']);

        eq([], Results::finalize($id, 'reject', 99.0, 'بودجه امسال تمام شده است.', null));
        $p = Proposals::find($id);
        near(0.0, (float) $p['final_amount'], 'a rejection always stores zero');
        eq('بودجه امسال تمام شده است.', $p['final_note']);
    });

    test('the dashboard figures count the decision that stands', function () use ($makeConfig) {
        fresh_app($makeConfig);
        $s = Results::summary();
        eq(['approve' => 0, 'conditional' => 0, 'reject' => 0], $s['decisions']);
        eq(null, $s['avg_score']);
        near(0.0, $s['final_amount']);

        // first proposal: the system says «approve» (90), the admin confirms it
        $a = (int) upload_fixture('sample-proposal-1.docx')['id'];
        review_proposal($a, 18.5);
        Assignments::assign($a, 'tech', ['id' => null, 'name' => 'الف', 'email' => null, 'mobile' => null]);
        Assignments::assign($a, 'biz', ['id' => null, 'name' => 'ب', 'email' => null, 'mobile' => null]);
        submit_form($a, 'tech', [4, 5, 3, 4, 4]);
        submit_form($a, 'biz', [5]);
        $s = Results::summary();
        eq(['approve' => 1, 'conditional' => 0, 'reject' => 0], $s['decisions'], 'the system proposal counts until the admin decides');
        near(0.0, $s['final_amount'], 'nothing is final yet');
        near(18.315, $s['system_amount']);
        eq([], Results::finalize($a, 'approve', 18.315, '', null));

        // second proposal: the system says «approve» (72), the admin makes it conditional with less money
        $b = (int) upload_fixture('sample-proposal-2.docx')['id'];
        review_proposal($b, 10.0);
        Assignments::assign($b, 'tech', ['id' => null, 'name' => 'الف', 'email' => null, 'mobile' => null]);
        Assignments::assign($b, 'biz', ['id' => null, 'name' => 'ب', 'email' => null, 'mobile' => null]);
        submit_form($b, 'tech', [4, 5, 3, 4, 4]);
        $p = submit_form($b, 'biz', [3, 4, 3, 2, 4]);
        eq('approve', $p['system_decision']);
        eq(0, (int) $p['needs_resolution']);
        eq([], Results::finalize($b, 'conditional', 5.0, 'نیازمند تکمیل مدارک', null));

        $s = Results::summary();
        eq(['approve' => 1, 'conditional' => 1, 'reject' => 0], $s['decisions'], 'the admin\'s decision replaces the system\'s proposal');
        eq(['approve' => 1, 'conditional' => 1, 'reject' => 0], $s['final_decisions']);
        near(81.0, (float) $s['avg_score']);
        near(23.315, $s['final_amount']);
        eq(0, $s['needs_resolution']);

        Results::unfinalize($b);
        $s = Results::summary();
        eq(['approve' => 2, 'conditional' => 0, 'reject' => 0], $s['decisions'], 'back to the system proposal when the decision is withdrawn');
        near(18.315, $s['final_amount']);
    });

    test('a tier chosen by the admin does not outlive the opinions it settled', function () use ($makeConfig) {
        fresh_app($makeConfig);
        $id = (int) upload_fixture('sample-proposal-1.docx')['id'];
        review_proposal($id, 18.5);
        Assignments::assign($id, 'tech', ['id' => null, 'name' => 'الف', 'email' => null, 'mobile' => null]);
        Assignments::assign($id, 'biz', ['id' => null, 'name' => 'ب', 'email' => null, 'mobile' => null]);
        submit_form($id, 'tech', [4, 5, 3, 4, 4]);
        submit_form($id, 'biz', [3, 4, 3, 2, 4], ['scalability' => 'medium', 'priority' => 'medium']);
        DB::get()->update('proposals', ['tier_override' => 'A'], 'id = ?', [$id]);
        eq('A', Results::recompute($id)['tier']);

        // the business judge is asked to correct the form and now says tier C
        $as = Assignments::forProposal($id);
        Assignments::reopen((int) $as['biz']['id']);
        eq(null, Results::recompute($id), 'no result while a form is open');
        $p = Proposals::find($id);
        eq(null, $p['tier_override'], 'the earlier choice is dropped together with the result');
        eq('judging', $p['status']);

        $p = submit_form($id, 'biz', [3, 4, 3, 2, 4], ['scalability' => 'low', 'priority' => 'normal']);
        eq('C', $p['result_tier'], 'provisional lower tier again, not the stale choice');
        eq(1, (int) $p['needs_resolution'], 'the admin is asked again');
    });

    test('each judge gets a private link that only works for that role', function () use ($makeConfig) {
        fresh_app($makeConfig);
        $id = (int) upload_fixture('sample-proposal-1.docx')['id'];
        review_proposal($id);
        list($tech) = Assignments::assign($id, 'tech', ['id' => null, 'name' => 'الف', 'email' => null, 'mobile' => null]);
        list($biz) = Assignments::assign($id, 'biz', ['id' => null, 'name' => 'ب', 'email' => null, 'mobile' => null]);
        ok((bool) preg_match('/^[A-Za-z0-9_\-]{32}$/', $tech['token']), 'token: ' . $tech['token']);
        ok($tech['token'] !== $biz['token']);
        eq('tech', Assignments::findByToken($tech['token'])['role']);
        eq('biz', Assignments::findByToken($biz['token'])['role']);
        eq('https://assess.example.ir/f/' . $tech['token'], Assignments::link($tech));
        foreach ([strtolower($tech['token']), strtoupper($tech['token'])] as $variant) {
            if ($variant !== $tech['token']) {
                eq(null, Assignments::findByToken($variant), 'links are case-sensitive, also on a case-insensitive database');
            }
        }
        eq(null, Assignments::findByToken('short'));
        eq(null, Assignments::findByToken(str_repeat('a', 32)));
        eq(null, Assignments::findByToken("' OR 1=1 --aaaaaaaaaaaaaaaaaaaa"));
        eq(null, Assignments::findByToken(substr($tech['token'], 0, 31) . '%'));
    });

    test('replacing a judge kills the old link and wipes the old draft', function () use ($makeConfig) {
        fresh_app($makeConfig);
        $id = (int) upload_fixture('sample-proposal-1.docx')['id'];
        review_proposal($id);
        list($a) = Assignments::assign($id, 'tech', ['id' => null, 'name' => 'داور اول', 'email' => 'first@example.ir', 'mobile' => null]);
        Assignments::markSent((int) $a['id'], 'email');
        Assignments::markOpened(Assignments::find((int) $a['id']));
        Assignments::saveDraft(Assignments::find((int) $a['id']), ['name' => 'داور اول', 'scores' => ['tech-1' => 5]]);
        eq('draft', Assignments::find((int) $a['id'])['status']);

        list($same, $what) = Assignments::assign($id, 'tech', ['id' => null, 'name' => 'داور اول', 'email' => 'new-address@example.ir', 'mobile' => null]);
        eq('updated', $what, 'same person, corrected e-mail: link and draft stay');
        eq($a['token'], $same['token']);
        eq('draft', $same['status']);
        eq('new-address@example.ir', $same['judge_email']);

        list($b, $what) = Assignments::assign($id, 'tech', ['id' => null, 'name' => 'داور دوم', 'email' => null, 'mobile' => null]);
        eq('replaced', $what);
        ok($b['token'] !== $a['token']);
        eq(null, Assignments::findByToken($a['token']), 'the old link is dead');
        eq('pending', $b['status']);
        eq(null, $b['draft_json']);
        eq(null, $b['sent_at']);
        eq(0, (int) $b['open_count']);
        eq((int) $a['id'], (int) $b['id']);

        $c = Assignments::regenerate((int) $b['id']);
        ok($c['token'] !== $b['token']);
        eq(null, Assignments::findByToken($b['token']));
    });

    test('opening, saving and submitting a form; a late autosave cannot undo the submission', function () use ($makeConfig) {
        fresh_app($makeConfig);
        $id = (int) upload_fixture('sample-proposal-1.docx')['id'];
        review_proposal($id);
        list($a) = Assignments::assign($id, 'biz', ['id' => null, 'name' => 'ب', 'email' => null, 'mobile' => null]);
        $aid = (int) $a['id'];

        eq(true, Assignments::markOpened($a), 'first opening');
        $a = Assignments::find($aid);
        eq('opened', $a['status']);
        eq('manual', $a['sent_via'], 'a link that was copied counts as sent by hand');
        eq(false, Assignments::markOpened($a));
        eq(2, (int) Assignments::find($aid)['open_count']);
        eq('judging', Proposals::refreshStatus($id));

        eq(true, Assignments::saveDraft(Assignments::find($aid), ['name' => 'پیش‌نویس']));
        $a = Assignments::find($aid);
        eq('draft', $a['status']);
        eq(['name' => 'پیش‌نویس'], Assignments::draft($a));
        eq([], Assignments::answers($a));

        $stale = $a; // the browser tab still holds this state
        $answers = \Fava\Domain\Scoring::normalize('biz', answers_for('biz', [4]));
        eq(true, Assignments::submit($a, $answers, ['share' => 40], '10.0.0.1', 'UA'));
        eq(false, Assignments::submit($a, $answers, ['share' => 40], '10.0.0.1', 'UA'), 'double click: only one submission');
        Assignments::saveDraft($stale, ['name' => 'autosave that arrived late']);
        $a = Assignments::find($aid);
        eq('submitted', $a['status']);
        eq($answers['name'], Assignments::answers($a)['name']);
        eq($answers['name'], Assignments::draft($a)['name'], 'the late autosave was ignored');
        eq('10.0.0.1', $a['submit_ip']);

        Assignments::reopen($aid);
        $a = Assignments::find($aid);
        eq('draft', $a['status']);
        eq(null, $a['submitted_at']);
        eq(null, $a['answers_json']);
        eq($answers['name'], Assignments::draft($a)['name'], 'the judge finds the previous answers in the form');
        eq(1, (int) $a['reopen_count']);
    });

    test('reopening a form withdraws the result and the final decision', function () use ($makeConfig) {
        fresh_app($makeConfig);
        $id = (int) upload_fixture('sample-proposal-1.docx')['id'];
        review_proposal($id, 20.0);
        Assignments::assign($id, 'tech', ['id' => null, 'name' => 'الف', 'email' => null, 'mobile' => null]);
        Assignments::assign($id, 'biz', ['id' => null, 'name' => 'ب', 'email' => null, 'mobile' => null]);
        submit_form($id, 'tech', [4]);
        $p = submit_form($id, 'biz', [4]);
        eq('completed', $p['status']);
        eq(0, (int) $p['needs_resolution']);
        eq([], Results::finalize($id, $p['system_decision'], (float) $p['result_amount'], '', null));

        Assignments::reopen((int) Assignments::forProposal($id)['biz']['id']);
        eq(null, Results::recompute($id));
        $p = Proposals::find($id);
        eq('judging', $p['status']);
        eq(null, $p['result_json']);
        eq(null, $p['total_score']);
        eq(null, $p['final_decision']);
        eq(null, $p['finalized_at']);

        $p = submit_form($id, 'biz', [2]);
        eq('completed', $p['status']);
        near(60.0, (float) $p['total_score']);
    });

    test('scoring parameters are frozen per proposal when the first judge is assigned', function () use ($makeConfig) {
        fresh_app($makeConfig);
        $id = (int) upload_fixture('sample-proposal-1.docx')['id'];
        review_proposal($id, 50.0);
        eq(null, Proposals::find($id)['config_json']);
        Assignments::assign($id, 'tech', ['id' => null, 'name' => 'الف', 'email' => null, 'mobile' => null]);
        ok(Proposals::find($id)['config_json'] !== null, 'frozen at assignment');

        Settings::set('scoring', ['tiers' => ['A' => ['ceiling' => 99]], 'thresholds' => ['reject_below' => 90, 'conditional_below' => 95]]);
        Settings::flush();
        near(99.0, FormDef::currentConfig()['tiers']['A']['ceiling']);
        near(30.0, Proposals::config(Proposals::find($id))['tiers']['A']['ceiling'], 'this proposal keeps the rules it started with');

        $other = (int) upload_fixture('sample-proposal-2.docx')['id'];
        near(99.0, Proposals::config(Proposals::find($other))['tiers']['A']['ceiling'], 'a new proposal uses the new rules');

        Proposals::freezeConfig($id, true);
        near(99.0, Proposals::config(Proposals::find($id))['tiers']['A']['ceiling'], 'unless the admin refreshes them');
    });

    test('judges enter the amount when the admin left it open', function () use ($makeConfig) {
        fresh_app($makeConfig);
        $id = (int) upload_fixture('sample-proposal-1.docx')['id'];
        review_proposal($id, null);
        Assignments::assign($id, 'tech', ['id' => null, 'name' => 'الف', 'email' => null, 'mobile' => null]);
        Assignments::assign($id, 'biz', ['id' => null, 'name' => 'ب', 'email' => null, 'mobile' => null]);
        throws(function () use ($id) {
            submit_form($id, 'tech', [4]);
        }, \RuntimeException::class, 'without an amount the form is incomplete');
        submit_form($id, 'tech', [4], ['requested_amount' => '۲۰']);
        $p = submit_form($id, 'biz', [4], ['requested_amount' => '25.5']);
        eq(1, (int) $p['needs_resolution']);
        $r = Results::of($p);
        eq(true, $r['amount_conflict']);
        near(20.0, $r['requested']);

        Proposals::saveDetails($id, ['requested_amount' => 22.0]);
        $r = Results::recompute($id);
        eq(false, $r['amount_conflict']);
        near(22.0, $r['requested']);
        eq(0, (int) Proposals::find($id)['needs_resolution']);
    });

    test('searching and filtering the list (Persian letter variants, digits, wildcards)', function () use ($makeConfig) {
        fresh_app($makeConfig);
        $a = upload_fixture('sample-proposal-1.docx');
        $b = upload_fixture('sample-proposal-2.docx');
        $c = upload_fixture('sample-proposal-3.docx');
        review_proposal((int) $b['id']);

        $search = function (array $filter) {
            list($rows, $total) = Proposals::page($filter, 1, 20);
            eq($total, count($rows));
            return array_map('intval', array_column($rows, 'id'));
        };
        eq([(int) $c['id'], (int) $b['id'], (int) $a['id']], $search([]), 'newest first');
        eq([(int) $a['id']], $search(['q' => 'انرژی']));
        eq([(int) $a['id']], $search(['q' => 'انرژي ساختمان']), 'Arabic ي instead of Persian ی');
        eq([(int) $b['id']], $search(['q' => 'كيت تشخيص']), 'Arabic ك');
        eq([(int) $c['id']], $search(['q' => 'آوای کویر']));
        eq([(int) $b['id']], $search(['q' => $b['code']]));
        eq([(int) $b['id']], $search(['q' => \Fava\Core\Fa::digits(substr($b['code'], -3))]), 'Persian digits');
        eq([], $search(['q' => 'چیزی‌که‌نیست']));
        eq([], $search(['q' => '%']), 'a wildcard is searched literally');
        eq([], $search(['q' => '_']));
        eq([], $search(['q' => "' OR '1'='1"]));
        eq([(int) $b['id']], $search(['status' => 'ready']));
        eq([(int) $c['id'], (int) $a['id']], $search(['status' => 'review']));
        eq([(int) $c['id'], (int) $a['id']], $search(['status' => 'attention']));
        eq(3, count($search(['status' => 'bogus'])));
        list($rows, $total) = Proposals::page([], 2, 2);
        eq(3, $total);
        eq(1, count($rows));
        eq(['review' => 2, 'ready' => 1, 'judging' => 0, 'completed' => 0, 'finalized' => 0, 'total' => 3], Proposals::counts());
        ok(array_key_exists('tech', $rows[0]) && array_key_exists('biz', $rows[0]));
    });

    test('reading the file again never overwrites what the admin confirmed', function () use ($makeConfig) {
        fresh_app($makeConfig);
        $id = (int) upload_fixture('sample-proposal-1.docx')['id'];
        Proposals::saveDetails($id, ['title' => 'عنوان موقت']);
        Proposals::reextract($id);
        eq('طراحی و ساخت سامانه هوشمند پایش و بهینه‌سازی مصرف انرژی ساختمان‌های اداری', Proposals::find($id)['title'], 'not reviewed yet: refreshed from the file');

        Proposals::saveDetails($id, ['title' => 'عنوان اصلاح‌شده مدیر', 'reviewed_at' => now_utc()]);
        $r = Proposals::reextract($id);
        eq('ok', $r['status']);
        eq('عنوان اصلاح‌شده مدیر', Proposals::find($id)['title']);
        list($rows) = Proposals::page(['q' => 'اصلاح‌شده'], 1, 5);
        eq(1, count($rows), 'the search index follows the edited title');
    });

    test('deleting a proposal removes its file, its document copy and its links', function () use ($makeConfig) {
        $db = fresh_app($makeConfig);
        $p = upload_fixture('sample-proposal-1.docx');
        $keep = upload_fixture('sample-proposal-2.docx');
        $id = (int) $p['id'];
        review_proposal($id);
        list($a) = Assignments::assign($id, 'tech', ['id' => null, 'name' => 'الف', 'email' => null, 'mobile' => null]);
        $file = Proposals::filePath($p);
        ok(is_file($file));
        Proposals::delete($id);
        eq(null, Proposals::find($id));
        eq(false, is_file($file));
        eq(null, Assignments::findByToken($a['token']));
        eq(0, (int) $db->val('SELECT COUNT(*) FROM {proposal_docs} WHERE proposal_id = ?', [$id]));
        ok(is_file(Proposals::filePath($keep)), 'other proposals are untouched');
        $again = upload_fixture('sample-proposal-1.docx');
        ok((int) $again['id'] !== $id, 'the same file can be uploaded again after deletion');
        ok($again['code'] !== $keep['code']);
    });

    test('the judges directory: validation, reuse by e-mail, safe deletion', function () use ($makeConfig) {
        fresh_app($makeConfig);
        list($data, $errors) = Judges::validate(['name' => '  دکتر   رضا فنی‌پور ', 'email' => ' Reza@Example.IR ', 'mobile' => '۰۹۱۲-۰۰۰ ۰۰۰۱', 'expertise' => 'tech']);
        eq([], $errors);
        eq('دکتر رضا فنی‌پور', $data['name']);
        eq('reza@example.ir', $data['email']);
        eq('09120000001', $data['mobile']);
        list(, $errors) = Judges::validate(['name' => '', 'email' => 'not-an-email']);
        eq(['name', 'email'], array_keys($errors));
        list($data2) = Judges::validate(['name' => 'x', 'expertise' => 'hacker']);
        eq('both', $data2['expertise']);

        $jid = Judges::create($data);
        eq($jid, (int) Judges::findByEmail('REZA@example.ir')['id']);
        eq([$jid], array_map('intval', array_column(Judges::forRole('tech'), 'id')));
        eq([], Judges::forRole('biz'));

        $pid = (int) upload_fixture('sample-proposal-1.docx')['id'];
        review_proposal($pid);
        Assignments::assign($pid, 'tech', ['id' => $jid, 'name' => $data['name'], 'email' => $data['email'], 'mobile' => null]);
        eq('deactivated', Judges::deleteOrDeactivate($jid), 'a judge with history is kept');
        eq(0, (int) Judges::find($jid)['is_active']);
        eq([], Judges::forRole('tech'));
        $other = Judges::create(Judges::validate(['name' => 'بدون سابقه'])[0]);
        eq('deleted', Judges::deleteOrDeactivate($other));
        eq(null, Judges::find($other));
    });

    test('too many wrong passwords lock the login for a while', function () use ($makeConfig) {
        $db = fresh_app($makeConfig);
        $db->insert('admins', ['username' => 'admin', 'password_hash' => password_hash('Correct-Horse-9', PASSWORD_DEFAULT), 'name' => 'مدیر', 'created_at' => now_utc()]);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        try {
            eq(false, Auth::isLocked('203.0.113.9'));
            for ($i = 0; $i < 6; $i++) {
                eq(false, Auth::attempt($i % 2 ? 'admin' : 'nobody', 'wrong-' . $i));
            }
            eq(true, Auth::isLocked('203.0.113.9'));
            eq(false, Auth::isLocked('203.0.113.10'), 'other visitors are not affected');
            $db->run('UPDATE {login_attempts} SET created_at = ?', [gmdate('Y-m-d H:i:s', time() - 16 * 60)]);
            eq(false, Auth::isLocked('203.0.113.9'), 'the lock ends after 15 minutes');
        } finally {
            unset($_SERVER['REMOTE_ADDR']);
        }
    });

    test('password recovery through storage/reset-admin.txt', function () use ($makeConfig) {
        $db = fresh_app($makeConfig);
        $db->insert('admins', ['username' => 'boss', 'password_hash' => password_hash('old-password', PASSWORD_DEFAULT), 'name' => 'مدیر', 'created_at' => now_utc()]);
        eq(null, Auth::applyResetFile(), 'no file, nothing happens');
        $file = STORAGE_DIR . '/reset-admin.txt';
        file_put_contents($file, "short\n");
        contains('حداقل ۸', (string) Auth::applyResetFile());
        eq(false, is_file($file), 'the file is always removed');
        ok(password_verify('old-password', (string) $db->val('SELECT password_hash FROM {admins}')));
        file_put_contents($file, "New-Secret-2026\r\nignored second line\n");
        contains('boss', (string) Auth::applyResetFile());
        eq(false, is_file($file));
        ok(password_verify('New-Secret-2026', (string) $db->val('SELECT password_hash FROM {admins}')));
    });

    test('the activity log records who did what and never breaks the action', function () use ($makeConfig) {
        $db = fresh_app($makeConfig);
        $id = (int) upload_fixture('sample-proposal-1.docx')['id'];
        Activity::log('upload', 'فایل بارگذاری شد.', $id);
        Activity::log('submit', 'فرم ثبت شد.', $id, 'judge', 'دکتر رضا');
        Activity::log('note', str_repeat('ط', 5000));
        $rows = Activity::forProposal($id);
        eq(2, count($rows));
        eq('judge', $rows[0]['actor_type'], 'newest first');
        eq('دکتر رضا', $rows[0]['actor_name']);
        eq('system', $rows[1]['actor_type']);
        list($page, $total) = Activity::page(1, 10);
        eq(3, $total);
        eq(2000, mb_strlen($page[0]['message']));
        list(, $n) = Activity::page(1, 10, 'submit');
        eq(1, $n);
        $db->pdo->exec('DROP TABLE ' . $db->table('activity_log'));
        Activity::log('upload', 'جدول وجود ندارد', $id); // must not throw
        ok(true);
    });

    test('transactions roll back completely', function () use ($makeConfig) {
        $db = fresh_app($makeConfig);
        throws(function () use ($db) {
            $db->tx(function (DB $db) {
                $db->insert('judges', ['name' => 'موقت', 'created_at' => now_utc(), 'updated_at' => now_utc()]);
                $db->tx(function (DB $db) {
                    $db->insert('judges', ['name' => 'تو در تو', 'created_at' => now_utc(), 'updated_at' => now_utc()]);
                });
                throw new \RuntimeException('boom');
            });
        }, \RuntimeException::class);
        eq(0, (int) $db->val('SELECT COUNT(*) FROM {judges}'));
        $id = $db->tx(function (DB $db) {
            return $db->insert('judges', ['name' => 'ماندگار', 'created_at' => now_utc(), 'updated_at' => now_utc()]);
        });
        eq('ماندگار', $db->val('SELECT name FROM {judges} WHERE id = ?', [$id]));
    });

    test('values come back with the right types', function () use ($makeConfig) {
        $db = fresh_app($makeConfig);
        $id = (int) upload_fixture('sample-proposal-1.docx')['id'];
        Proposals::saveDetails($id, ['requested_amount' => 18.525, 'admin_notes' => "یادداشت با ' و \" و \\ و 😀"]);
        $p = Proposals::find($id);
        near(18.525, (float) $p['requested_amount']);
        eq("یادداشت با ' و \" و \\ و 😀", $p['admin_notes']);
        eq(null, $p['due_at']);
        eq(1, (int) $p['share_doc']);
        ok((bool) preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string) $p['created_at']), 'created_at: ' . $p['created_at']);
    });
}

group('Secrets');

test('the SMTP password is stored encrypted and tampering is detected', function () {
    Config::set('app_key', str_repeat('a', 64));
    $sealed = Crypto::seal('S3cret-پسورد');
    if (!function_exists('openssl_encrypt')) {
        eq('S3cret-پسورد', $sealed);
        skip('OpenSSL is not available; the password is stored as it is');
    }
    ok(strpos($sealed, 'enc:v1:') === 0);
    lacks('S3cret', $sealed);
    eq('S3cret-پسورد', Crypto::open($sealed));
    ok(Crypto::seal('S3cret-پسورد') !== $sealed, 'a fresh IV every time');
    eq('', Crypto::seal(''));
    eq('plain-old-value', Crypto::open('plain-old-value'), 'values saved before encryption still work');

    $raw = base64_decode(substr($sealed, 7));
    $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
    eq('', Crypto::open('enc:v1:' . base64_encode($raw)), 'a modified value is rejected');
    eq('', Crypto::open('enc:v1:not-base64!!'));
    Config::set('app_key', str_repeat('b', 64));
    eq('', Crypto::open($sealed), 'another installation key cannot open it');
    Config::set('app_key', str_repeat('k', 64));
});

test('random tokens are URL-safe and do not repeat', function () {
    $seen = [];
    for ($i = 0; $i < 2000; $i++) {
        $t = random_token(24);
        if (!preg_match('/^[A-Za-z0-9_\-]{32}$/', $t) || isset($seen[$t])) {
            throw new TestFailure('bad token: ' . $t);
        }
        $seen[$t] = 1;
    }
    TestState::$assertions += 2000;
});

test('the error log never holds passwords or complete judge links', function () {
    $file = STORAGE_DIR . '/logs/app-' . gmdate('Y-m') . '.log';
    @unlink($file);
    $token = str_repeat('Ab3_', 8);
    $connect = function (string $user, string $password) {
        throw new \RuntimeException('could not connect');
    };
    try {
        $connect('dbuser', 'Very-Secret-1');
    } catch (\Throwable $e) {
        \Fava\Core\Logger::exception($e, 'POST /f/' . $token . '/submit');
    }
    $log = (string) file_get_contents($file);
    contains('RuntimeException: could not connect', $log);
    contains('04_DomainTest.php', $log, 'the place of the error is recorded');
    lacks('Very-Secret-1', $log, 'function arguments are not written');
    lacks($token, $log);
    contains('/f/Ab3_Ab…/submit', $log, 'enough of the link to recognise it, not enough to use it');
    @unlink($file);
});
