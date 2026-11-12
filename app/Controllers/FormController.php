<?php

namespace Fava\Controllers;

use Fava\Core\HttpException;
use Fava\Core\Jalali;
use Fava\Core\Request;
use Fava\Core\View;
use Fava\Docx\DocRenderer;
use Fava\Domain\Activity;
use Fava\Domain\Assignments;
use Fava\Domain\FormDef;
use Fava\Domain\Notifier;
use Fava\Domain\Proposals;
use Fava\Domain\Results;
use Fava\Domain\Scoring;
use Fava\Domain\Settings;

/**
 * The judge's side: the private link opens the assessment form of one proposal,
 * locked to the judge's role. No login and no cookies – the link is the key.
 */
class FormController extends Controller
{
    private const MAX_BODY = 400000; // bytes; a complete form is a few KB

    /** @return array{0:array,1:array}|null assignment, proposal */
    private function find(array $params): ?array
    {
        $a = Assignments::findByToken((string) ($params['token'] ?? ''));
        if (!$a) {
            return null;
        }
        $p = Proposals::find((int) $a['proposal_id']);
        if (!$p || empty($p['reviewed_at'])) {
            return null;
        }
        return [$a, $p];
    }

    private function invalid(): void
    {
        if (Request::wantsJson() || Request::isPost()) {
            View::json(['ok' => false, 'error' => 'این پیوند معتبر نیست یا باطل شده است.'], 404);
            return;
        }
        View::render('form/invalid', [
            'pageTitle' => 'پیوند نامعتبر',
            'org' => $this->orgName(),
            'flashes' => [],
            'support' => (string) Settings::get('support_contact', ''),
        ], 'plain', 404);
    }

    /** Everything the form view needs (also used for the admin's preview). */
    public static function formData(array $p, string $role, ?array $a, bool $preview): array
    {
        $cfg = Proposals::config($p);
        $fixed = $p['requested_amount'] !== null && (float) $p['requested_amount'] > 0 ? (float) $p['requested_amount'] : null;
        $answers = Scoring::normalize($role, (!$preview && $a) ? Assignments::draft($a) : []);
        if ($answers['name'] === '' && $a) {
            $answers['name'] = (string) $a['judge_name'];
        }
        // «تاریخ تکمیل فرم» is the day the form is sent, so it always starts as today
        $answers['date'] = Jalali::today();

        $urls = null;
        $fileUrl = null;
        if ($preview) {
            $fileUrl = !empty($p['share_file']) ? url('/admin/proposals/' . (int) $p['id'] . '/file') : null;
        } elseif ($a) {
            $base = '/f/' . $a['token'];
            $urls = ['draft' => url($base . '/draft'), 'submit' => url($base . '/submit')];
            $fileUrl = !empty($p['share_file']) ? url($base . '/file') : null;
        }

        return [
            'pageTitle' => (string) Settings::get('form_title', 'کاربرگ ارزش‌گذاری استراتژیک طرح'),
            'org' => (string) Settings::get('org_name', 'پارک فاوا'),
            'p' => $p,
            'fields' => Proposals::fields($p),
            'role' => $role,
            'def' => FormDef::get(),
            'cfg' => $cfg,
            'client' => FormDef::clientConfig($role, $cfg),
            'answers' => $answers,
            'fixedAmount' => $fixed,
            'preview' => $preview,
            'urls' => $urls,
            'fileUrl' => $fileUrl,
            'docHtml' => !empty($p['share_doc']) ? DocRenderer::html(Proposals::doc((int) $p['id'])) : null,
            'savedAt' => (!$preview && $a) ? $a['draft_saved_at'] : null,
            'reopened' => !$preview && $a && !empty($a['reopened_at']),
            'support' => (string) Settings::get('support_contact', ''),
            'storageKey' => (!$preview && $a) ? 'fava-form-' . substr(hash('sha256', (string) $a['token']), 0, 16) : '',
        ];
    }

    public function show(array $params): void
    {
        $found = $this->find($params);
        if (!$found) {
            $this->invalid();
            return;
        }
        list($a, $p) = $found;
        $role = (string) $a['role'];

        if ($a['status'] === 'submitted') {
            $cfg = Proposals::config($p);
            View::render('form/submitted', [
                'pageTitle' => 'فرم شما ثبت شد',
                'org' => (string) Settings::get('org_name', 'پارک فاوا'),
                'p' => $p,
                'a' => $a,
                'role' => $role,
                'def' => FormDef::get(),
                'cfg' => $cfg,
                'answers' => Assignments::answers($a),
                'metrics' => json_in($a['metrics_json'] ?? '', []),
                'fixedAmount' => $p['requested_amount'] !== null && (float) $p['requested_amount'] > 0 ? (float) $p['requested_amount'] : null,
                'justSent' => Request::query('done') === '1',
                'support' => (string) Settings::get('support_contact', ''),
                'preview' => false,
            ], 'form');
            return;
        }

        View::render('form/show', self::formData($p, $role, $a, false), 'form');
    }

    private function body(): array
    {
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length > self::MAX_BODY) {
            throw new HttpException(413, 'حجم اطلاعات فرم بیش از حد مجاز است.');
        }
        return Request::json();
    }

    /** Autosave, and the «opened» signal sent by the page once it is really displayed in a browser. */
    public function draft(array $params): void
    {
        $found = $this->find($params);
        if (!$found) {
            $this->invalid();
            return;
        }
        list($a, $p) = $found;
        $id = (int) $p['id'];
        if ($a['status'] === 'submitted') {
            View::json(['ok' => false, 'submitted' => true, 'error' => 'این فرم قبلاً ثبت نهایی شده است.'], 409);
            return;
        }
        $body = $this->body();
        $role = (string) $a['role'];
        $who = (string) $a['judge_name'];
        $changed = false;

        if (!empty($body['open'])) {
            if (Assignments::markOpened($a)) {
                Activity::log('open', role_label($role) . ' (' . $who . ') پیوند را باز کرد.', $id, 'judge', $who);
                $changed = true;
            }
            $a = Assignments::find((int) $a['id']) ?: $a;
        }
        if (isset($body['answers']) && is_array($body['answers'])) {
            $answers = Scoring::normalize($role, $body['answers']);
            if (Assignments::saveDraft($a, $answers)) {
                Activity::log('draft', role_label($role) . ' (' . $who . ') تکمیل فرم را شروع کرد.', $id, 'judge', $who);
                $changed = true;
            }
        }
        if ($changed) {
            Proposals::refreshStatus($id);
        }
        $a = Assignments::find((int) $a['id']) ?: $a;
        // 'rev' lets the browser tell whether the copy it keeps locally is still based on this draft
        View::json(['ok' => true, 'saved_at' => jdate(time(), 'H:i'), 'rev' => (string) ($a['draft_saved_at'] ?? '')]);
    }

    public function submit(array $params): void
    {
        $found = $this->find($params);
        if (!$found) {
            $this->invalid();
            return;
        }
        list($a, $p) = $found;
        $id = (int) $p['id'];
        $role = (string) $a['role'];
        $who = (string) $a['judge_name'];
        $done = url('/f/' . $a['token'], ['done' => 1]);

        if ($a['status'] === 'submitted') {
            View::json(['ok' => true, 'already' => true, 'redirect' => $done]);
            return;
        }
        $body = $this->body();
        $cfg = Proposals::config($p);
        $fixed = $p['requested_amount'] !== null && (float) $p['requested_amount'] > 0 ? (float) $p['requested_amount'] : null;
        $answers = Scoring::normalize($role, isset($body['answers']) && is_array($body['answers']) ? $body['answers'] : []);
        if ($fixed !== null) {
            $answers['requested_amount'] = null; // the admin's figure is the one that counts
        }
        $errors = Scoring::validate($role, $answers, $cfg, $fixed);
        if ($errors) {
            Assignments::saveDraft($a, $answers);
            View::json([
                'ok' => false,
                'errors' => $errors,
                'error' => 'فرم هنوز کامل نیست. موارد مشخص‌شده را تکمیل کنید.',
            ], 422);
            return;
        }

        $metrics = Scoring::judge($role, $answers, $cfg, $fixed);
        if (!Assignments::submit($a, $answers, $metrics, Request::ip(), Request::userAgent())) {
            View::json(['ok' => true, 'already' => true, 'redirect' => $done]);
            return;
        }
        Activity::log(
            'submit',
            role_label($role) . ' (' . $answers['name'] . ') فرم را ثبت کرد؛ سهم امتیاز ' . nf($metrics['share'], 1) . ' از ' . nf($metrics['share_max'], 0) . '.',
            $id,
            'judge',
            $who
        );
        $result = Results::recompute($id);
        if ($result) {
            Activity::log(
                'result',
                'هر دو فرم ثبت شد. امتیاز کل ' . nf($result['total'], 1) . ' از ۱۰۰؛ پیشنهاد سامانه: ' . FormDef::decision($result['decision'])['label']
                . '؛ مبلغ محاسبه‌شده ' . money($result['amount']) . '.'
                . ($result['needs_resolution'] ? ' نظر داوران در سطح یا مبلغ یکسان نیست و باید توسط مدیر مشخص شود.' : ''),
                $id,
                'system'
            );
        }

        View::json(['ok' => true, 'redirect' => $done]);
        // the judge already has the answer; the note to the admin may take a few seconds
        finish_request();
        $a = Assignments::find((int) $a['id']) ?: $a;
        Notifier::judgeSubmitted(Proposals::find($id) ?: $p, $a, $result);
    }

    /** The original Word file, when the admin allows judges to download it. */
    public function file(array $params): void
    {
        $found = $this->find($params);
        if (!$found) {
            $this->invalid();
            return;
        }
        list(, $p) = $found;
        if (empty($p['share_file'])) {
            throw new HttpException(403, 'دریافت فایل این طرح برای داوران فعال نیست.');
        }
        ProposalController::sendDocx($p);
    }
}
