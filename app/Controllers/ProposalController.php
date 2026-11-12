<?php

namespace Fava\Controllers;

use Fava\Core\Auth;
use Fava\Core\DB;
use Fava\Core\Fa;
use Fava\Core\HttpException;
use Fava\Core\Jalali;
use Fava\Core\Logger;
use Fava\Core\Request;
use Fava\Core\Session;
use Fava\Core\View;
use Fava\Docx\DocRenderer;
use Fava\Docx\DocxException;
use Fava\Domain\Activity;
use Fava\Domain\Assignments;
use Fava\Domain\FormDef;
use Fava\Domain\Judges;
use Fava\Domain\Labels;
use Fava\Domain\Mailer;
use Fava\Domain\Notifier;
use Fava\Domain\Proposals;
use Fava\Domain\Results;
use Fava\Domain\Settings;

class ProposalController extends Controller
{
    // ---- list & upload -----------------------------------------------------------

    public function index(array $params): void
    {
        $filter = [
            'q' => (string) Request::query('q', ''),
            'status' => (string) Request::query('status', ''),
            'decision' => (string) Request::query('decision', ''),
        ];
        $page = $this->pageNumber();
        $perPage = 20;
        list($rows, $total) = Proposals::page($filter, $page, $perPage);
        $this->admin('admin/proposals', [
            'pageTitle' => 'طرح‌ها',
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'filter' => $filter,
            'counts' => Proposals::counts(),
            'maxUpload' => Settings::maxUploadBytes(),
        ], 'proposals');
    }

    public function upload(array $params): void
    {
        $files = self::uploadedFiles('files');
        if (!$files) {
            flash('error', 'هیچ فایلی انتخاب نشده است.');
            back('/admin/proposals');
        }
        $max = Settings::maxUploadBytes();
        $created = [];
        $problems = [];
        foreach (array_slice($files, 0, 30) as $f) {
            $name = Proposals::cleanName((string) $f['name']);
            if ($f['error'] !== UPLOAD_ERR_OK) {
                $problems[] = '«' . $name . '»: ' . self::uploadError((int) $f['error'], $max);
                continue;
            }
            if ((int) $f['size'] > $max) {
                $problems[] = '«' . $name . '»: حجم فایل (' . filesize_h($f['size']) . ') بیشتر از حد مجاز (' . filesize_h($max) . ') است.';
                continue;
            }
            if (!is_uploaded_file((string) $f['tmp_name'])) {
                $problems[] = '«' . $name . '»: فایل به‌درستی دریافت نشد.';
                continue;
            }
            try {
                $r = Proposals::createFromUpload((string) $f['tmp_name'], $name, Auth::id());
            } catch (DocxException $e) {
                $problems[] = '«' . $name . '»: ' . $e->getMessage();
                continue;
            } catch (\Throwable $e) {
                Logger::exception($e, 'upload ' . $name);
                $problems[] = '«' . $name . '»: پردازش فایل با خطا روبه‌رو شد. ' . (\Fava\Core\Config::get('debug') ? $e->getMessage() : '');
                continue;
            }
            if ($r['duplicate']) {
                $problems[] = '«' . $name . '»: این فایل قبلاً بارگذاری شده است (طرح ' . fa($r['duplicate']['code']) . ').';
                continue;
            }
            $created[] = $r;
            $label = Labels::EXTRACT[$r['status']][0] ?? $r['status'];
            Activity::log('upload', 'فایل «' . $name . '» بارگذاری شد؛ ' . $label . '.', $r['id']);
        }

        foreach ($problems as $msg) {
            flash('error', $msg);
        }
        if (count($created) === 1) {
            $r = $created[0];
            if ($r['status'] === 'ok') {
                flash('success', 'فایل خوانده شد و اطلاعات طرح استخراج شد. لطفاً آن را بررسی و تأیید کنید.');
            } elseif ($r['status'] === 'partial') {
                flash('warning', 'فایل خوانده شد، اما بخشی از اطلاعات پیدا نشد. موارد خالی را خودتان تکمیل کنید.');
            } else {
                flash('warning', 'فایل ذخیره شد، اما ساختار آن با کاربرگ درخواست حمایت هم‌خوان نبود. اطلاعات طرح را دستی وارد کنید.');
            }
            $this->toProposal($r['id']);
        }
        if ($created) {
            flash('success', fa((string) count($created)) . ' طرح بارگذاری شد. اطلاعات هر طرح را بررسی و تأیید کنید.');
            redirect('/admin/proposals', ['status' => 'review']);
        }
        back('/admin/proposals');
    }

    /** @return array[] flat list of uploaded files for a (possibly multiple) file field */
    private static function uploadedFiles(string $field): array
    {
        if (empty($_FILES[$field])) {
            return [];
        }
        $f = $_FILES[$field];
        $out = [];
        if (is_array($f['name'])) {
            foreach ($f['name'] as $i => $name) {
                if ((int) $f['error'][$i] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $out[] = ['name' => $name, 'tmp_name' => $f['tmp_name'][$i], 'error' => (int) $f['error'][$i], 'size' => (int) $f['size'][$i]];
            }
        } elseif ((int) $f['error'] !== UPLOAD_ERR_NO_FILE) {
            $out[] = ['name' => $f['name'], 'tmp_name' => $f['tmp_name'], 'error' => (int) $f['error'], 'size' => (int) $f['size']];
        }
        return $out;
    }

    private static function uploadError(int $code, int $max): string
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'حجم فایل بیشتر از حد مجاز سرور (' . filesize_h($max) . ') است.';
            case UPLOAD_ERR_PARTIAL:
                return 'بارگذاری فایل کامل نشد؛ دوباره تلاش کنید.';
            default:
                return 'سرور نتوانست فایل را ذخیره کند (کد ' . $code . '). با پشتیبانی هاست تماس بگیرید.';
        }
    }

    // ---- detail --------------------------------------------------------------------

    public function show(array $params): void
    {
        $p = $this->proposalOr404($params);
        $id = (int) $p['id'];
        $as = Assignments::forProposal($id);
        $cfg = Proposals::config($p);
        $links = [];
        foreach (['tech', 'biz'] as $role) {
            $links[$role] = $as[$role] ? Assignments::link($as[$role]) : null;
        }
        $this->admin('admin/proposal', [
            'pageTitle' => str_limit((string) $p['title'], 60) ?: 'طرح ' . $p['code'],
            'p' => $p,
            'fields' => Proposals::fields($p),
            'warnings' => Proposals::warnings($p),
            'as' => $as,
            'links' => $links,
            'answers' => ['tech' => Assignments::answers($as['tech']), 'biz' => Assignments::answers($as['biz'])],
            'cfg' => $cfg,
            'def' => FormDef::get(),
            'result' => Results::of($p),
            'judges' => ['tech' => Judges::forRole('tech'), 'biz' => Judges::forRole('biz')],
            'activity' => Activity::forProposal($id, 60),
            'mailReady' => Mailer::configured(),
            'mails' => DB::get()->all('SELECT * FROM {mail_log} WHERE proposal_id = ? ORDER BY id DESC LIMIT 8', [$id]),
            'docHtml' => DocRenderer::html(Proposals::doc($id)),
            'configChanged' => !empty($p['config_json']) && json_encode(FormDef::currentConfig()) !== json_encode($cfg),
        ], 'proposals');
    }

    public function update(array $params): void
    {
        $p = $this->proposalOr404($params);
        $id = (int) $p['id'];
        $confirm = Request::str('action') === 'confirm';

        $title = Fa::clean(Request::str('title'));
        $company = Fa::clean(Request::str('company'));
        $output = Fa::clean(Request::str('expected_output'), true);
        $amountRaw = Request::str('requested_amount');
        $dueRaw = Request::str('due_date');
        $errors = [];

        if ($title === '') {
            $errors[] = 'عنوان طرح نمی‌تواند خالی باشد.';
        }
        if ($confirm && $company === '') {
            $errors[] = 'نام شرکت/تیم را وارد کنید.';
        }
        $amount = null;
        if ($amountRaw !== '') {
            $amount = Fa::number($amountRaw);
            if ($amount === null || $amount <= 0 || $amount > 1000000) {
                $errors[] = 'مبلغ درخواستی باید عددی بزرگ‌تر از صفر و به میلیارد ریال باشد.';
                $amount = null;
            } else {
                $amount = round($amount, 3);
            }
        }
        $due = null;
        if ($dueRaw !== '') {
            $g = Jalali::toGregorianString($dueRaw);
            if ($g === null) {
                $errors[] = 'مهلت داوری را به شکل ۱۴۰۵/۰۹/۱۵ وارد کنید.';
            } else {
                $due = $g . ' 20:29:59'; // end of that day in Tehran, stored in UTC
            }
        }
        $oldAmount = $p['requested_amount'] !== null ? (float) $p['requested_amount'] : null;
        $amountChanged = ($amount === null) !== ($oldAmount === null) || ($amount !== null && abs($amount - $oldAmount) > 0.0005);
        if ($amountChanged && !empty($p['finalized_at'])) {
            $errors[] = 'تصمیم نهایی این طرح ثبت شده است؛ برای تغییر مبلغ درخواستی ابتدا تصمیم نهایی را لغو کنید.';
        }
        if ($errors) {
            foreach ($errors as $m) {
                flash('error', $m);
            }
            Session::keepInput($_POST);
            $this->toProposal($id, 'info');
        }

        $data = [
            'title' => $title,
            'company' => mb_substr($company, 0, 250),
            'expected_output' => $output,
            'requested_amount' => $amount,
            'due_at' => $due,
            'admin_notes' => Request::str('admin_notes') !== '' ? mb_substr(Request::str('admin_notes'), 0, 5000) : null,
            'share_doc' => Request::bool('share_doc') ? 1 : 0,
            'share_file' => Request::bool('share_file') ? 1 : 0,
        ];
        $firstConfirm = $confirm && empty($p['reviewed_at']);
        if ($firstConfirm) {
            $data['reviewed_at'] = now_utc();
        }
        Proposals::saveDetails($id, $data);
        Proposals::refreshStatus($id);
        if ($amountChanged && !empty($p['result_json'])) {
            Results::recompute($id);
        }

        if ($firstConfirm) {
            Activity::log('review', 'اطلاعات طرح بررسی و تأیید شد.', $id);
            flash('success', 'اطلاعات طرح تأیید شد. اکنون داوران را تعیین کنید.');
            $this->toProposal($id, 'judges');
        }
        Activity::log('update', 'اطلاعات طرح ویرایش شد.', $id);
        flash('success', 'تغییرات ذخیره شد.');
        $this->toProposal($id, 'info');
    }

    public function reextract(array $params): void
    {
        $p = $this->proposalOr404($params);
        $id = (int) $p['id'];
        try {
            $r = Proposals::reextract($id);
            Activity::log('extract', 'فایل دوباره خوانده شد؛ ' . (Labels::EXTRACT[$r['status']][0] ?? $r['status']) . '.', $id);
            flash('success', empty($p['reviewed_at'])
                ? 'فایل دوباره خوانده شد و اطلاعات استخراج‌شده به‌روز شد.'
                : 'فایل دوباره خوانده شد. عنوان، شرکت و خروجی که قبلاً تأیید کرده‌اید تغییر نکرد.');
        } catch (\Throwable $e) {
            Logger::exception($e, 'reextract');
            flash('error', 'خواندن دوباره فایل ناموفق بود: ' . $e->getMessage());
        }
        $this->toProposal($id, 'info');
    }

    // ---- judges ----------------------------------------------------------------------

    public function assign(array $params): void
    {
        $p = $this->proposalOr404($params);
        $id = (int) $p['id'];
        $role = (string) $params['role'];
        if (empty($p['reviewed_at'])) {
            flash('error', 'ابتدا اطلاعات طرح را بررسی و تأیید کنید.');
            $this->toProposal($id, 'info');
        }
        if (!empty($p['finalized_at'])) {
            flash('error', 'تصمیم نهایی این طرح ثبت شده است و داوران آن قابل تغییر نیستند.');
            $this->toProposal($id, 'judges');
        }

        $judgeId = Request::int('judge_id');
        if ($judgeId > 0) {
            $j = Judges::find($judgeId);
            if (!$j) {
                flash('error', 'داور انتخاب‌شده پیدا نشد.');
                $this->toProposal($id, 'judges');
            }
            $judge = ['id' => (int) $j['id'], 'name' => $j['name'], 'email' => $j['email'], 'mobile' => $j['mobile']];
        } else {
            list($data, $errors) = Judges::validate([
                'name' => Request::str('name'),
                'email' => Request::str('email'),
                'mobile' => Request::str('mobile'),
                'expertise' => $role,
            ]);
            if ($errors) {
                flash('error', (string) reset($errors));
                Session::keepInput($_POST);
                $this->toProposal($id, 'judges');
            }
            $judge = ['id' => null, 'name' => $data['name'], 'email' => $data['email'], 'mobile' => $data['mobile']];
            if (Request::bool('save_judge')) {
                $existing = $data['email'] ? Judges::findByEmail($data['email']) : null;
                $judge['id'] = $existing ? (int) $existing['id'] : Judges::create($data);
            }
        }

        try {
            list($a, $what) = Assignments::assign($id, $role, $judge);
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
            $this->toProposal($id, 'judges');
        }
        Proposals::refreshStatus($id);
        $label = role_label($role);
        if ($what === 'created') {
            Activity::log('assign', $label . ': ' . $judge['name'] . ' تعیین شد و پیوند اختصاصی ساخته شد.', $id);
            flash('success', $label . ' تعیین شد. اکنون پیوند را ایمیل کنید یا آن را کپی و برای داور بفرستید.');
        } elseif ($what === 'replaced') {
            Activity::log('assign', $label . ' به ' . $judge['name'] . ' تغییر کرد؛ پیوند قبلی باطل و پیوند تازه ساخته شد.', $id);
            flash('success', $label . ' تغییر کرد. پیوند قبلی دیگر کار نمی‌کند؛ پیوند تازه را برای داور جدید بفرستید.');
        } else {
            Activity::log('assign', 'مشخصات ' . $label . ' به‌روز شد.', $id);
            flash('success', 'مشخصات ' . $label . ' به‌روز شد.');
        }

        $as = Assignments::forProposal($id);
        if ($as['tech'] && $as['biz']) {
            $sameId = $as['tech']['judge_id'] && $as['tech']['judge_id'] == $as['biz']['judge_id'];
            $sameMail = $as['tech']['judge_email'] && $as['tech']['judge_email'] === $as['biz']['judge_email'];
            if ($sameId || $sameMail) {
                flash('warning', 'توجه: داور فنی و داور کسب‌وکاری این طرح یک نفر هستند.');
            }
        }
        $this->toProposal($id, 'judges');
    }

    public function unassign(array $params): void
    {
        list($p, $a) = $this->assignmentOrBack($params);
        $id = (int) $p['id'];
        if ($a['status'] === 'submitted') {
            flash('error', 'این داور فرم خود را ثبت کرده است و قابل حذف نیست. در صورت نیاز ابتدا فرم را بازگشایی کنید.');
            $this->toProposal($id, 'judges');
        }
        Assignments::remove((int) $a['id']);
        Proposals::refreshStatus($id);
        Activity::log('unassign', role_label($a['role']) . ' (' . $a['judge_name'] . ') حذف شد و پیوند او باطل شد.', $id);
        flash('success', role_label($a['role']) . ' حذف شد.');
        $this->toProposal($id, 'judges');
    }

    public function send(array $params): void
    {
        $p = $this->proposalOr404($params);
        $id = (int) $p['id'];
        $which = Request::str('role');
        $reminder = Request::str('kind') === 'reminder';
        if (empty($p['reviewed_at'])) {
            flash('error', 'ابتدا اطلاعات طرح را بررسی و تأیید کنید.');
            $this->toProposal($id, 'info');
        }
        if (!Mailer::configured()) {
            flash('error', 'ارسال ایمیل هنوز پیکربندی نشده است. در «تنظیمات › ایمیل» مشخصات صندوق ایمیل را وارد کنید، یا پیوند را کپی و دستی بفرستید.');
            $this->toProposal($id, 'judges');
        }
        $as = Assignments::forProposal($id);
        $roles = $which === 'all' ? ['tech', 'biz'] : (in_array($which, ['tech', 'biz'], true) ? [$which] : []);
        $sent = 0;
        foreach ($roles as $role) {
            $a = $as[$role];
            if (!$a || $a['status'] === 'submitted') {
                continue;
            }
            $label = role_label($role);
            $r = Notifier::invite($p, $a, $reminder);
            if ($r['ok']) {
                Assignments::markSent((int) $a['id'], 'email', $reminder);
                Activity::log('mail', ($reminder ? 'یادآوری' : 'دعوت‌نامه و پیوند') . ' برای ' . $label . ' (' . $a['judge_name'] . ') به ' . $a['judge_email'] . ' ایمیل شد.', $id);
                $sent++;
            } else {
                Activity::log('mail_fail', 'ارسال ایمیل به ' . $label . ' ناموفق بود: ' . $r['error'], $id);
                flash('error', $label . ': ' . $r['error']);
            }
        }
        Proposals::refreshStatus($id);
        if ($sent > 0) {
            flash('success', $sent === 1 ? 'ایمیل ارسال شد.' : 'ایمیل برای هر دو داور ارسال شد.');
        } elseif (!$roles || (!$as['tech'] && !$as['biz'])) {
            flash('error', 'داوری برای ارسال انتخاب نشده است.');
        }
        $this->toProposal($id, 'judges');
    }

    public function markSent(array $params): void
    {
        list($p, $a) = $this->assignmentOrBack($params);
        $id = (int) $p['id'];
        if ($a['status'] !== 'submitted') {
            Assignments::markSent((int) $a['id'], 'manual');
            Proposals::refreshStatus($id);
            Activity::log('link', 'پیوند ' . role_label($a['role']) . ' به‌صورت دستی برای ' . $a['judge_name'] . ' فرستاده شد (ثبت توسط مدیر).', $id);
            flash('success', 'ثبت شد که پیوند را برای داور فرستاده‌اید.');
        }
        $this->toProposal($id, 'judges');
    }

    public function regenerate(array $params): void
    {
        list($p, $a) = $this->assignmentOrBack($params);
        $id = (int) $p['id'];
        if ($a['status'] === 'submitted') {
            flash('error', 'فرم این داور ثبت شده است و نیازی به پیوند تازه ندارد.');
            $this->toProposal($id, 'judges');
        }
        Assignments::regenerate((int) $a['id']);
        Activity::log('link', 'پیوند ' . role_label($a['role']) . ' دوباره ساخته شد؛ پیوند قبلی باطل است.', $id);
        flash('warning', 'پیوند تازه ساخته شد و پیوند قبلی دیگر کار نمی‌کند. پیوند جدید را برای داور بفرستید.');
        $this->toProposal($id, 'judges');
    }

    public function reopen(array $params): void
    {
        list($p, $a) = $this->assignmentOrBack($params);
        $id = (int) $p['id'];
        if (!empty($p['finalized_at'])) {
            flash('error', 'تصمیم نهایی ثبت شده است؛ برای بازگشایی فرم ابتدا تصمیم نهایی را لغو کنید.');
            $this->toProposal($id, 'result');
        }
        if ($a['status'] === 'submitted') {
            Assignments::reopen((int) $a['id']);
            Results::recompute($id);
            Activity::log('reopen', 'فرم ' . role_label($a['role']) . ' (' . $a['judge_name'] . ') برای اصلاح بازگشایی شد؛ نتیجه طرح تا ثبت دوباره فرم محاسبه نمی‌شود.', $id);
            flash('success', 'فرم بازگشایی شد. داور با همان پیوند قبلی می‌تواند پاسخ‌ها را اصلاح و دوباره ثبت کند.');
        }
        $this->toProposal($id, 'judges');
    }

    /** @return array{0:array,1:array} proposal, assignment */
    private function assignmentOrBack(array $params): array
    {
        $p = $this->proposalOr404($params);
        $as = Assignments::forProposal((int) $p['id']);
        $a = $as[(string) $params['role']] ?? null;
        if (!$a) {
            flash('error', 'برای این نقش داوری تعیین نشده است.');
            $this->toProposal((int) $p['id'], 'judges');
        }
        return [$p, $a];
    }

    // ---- result ----------------------------------------------------------------------

    public function resolve(array $params): void
    {
        $p = $this->proposalOr404($params);
        $id = (int) $p['id'];
        $result = Results::of($p);
        if (!$result) {
            flash('error', 'هنوز نتیجه‌ای برای این طرح محاسبه نشده است.');
            $this->toProposal($id, 'result');
        }
        if (!empty($p['finalized_at'])) {
            flash('error', 'تصمیم نهایی ثبت شده است؛ برای تغییر ابتدا آن را لغو کنید.');
            $this->toProposal($id, 'result');
        }
        $cfg = Proposals::config($p);
        $data = [];
        $notes = [];
        if ($result['tier_conflict']) {
            $tier = Request::str('tier');
            if (!isset($cfg['tiers'][$tier])) {
                flash('error', 'سطح طرح را انتخاب کنید.');
                $this->toProposal($id, 'result');
            }
            $data['tier_override'] = $tier;
            $notes[] = 'سطح طرح: ' . ($cfg['tiers'][$tier]['short'] ?? $tier);
        }
        $amountRaw = Request::str('requested_amount');
        if ($amountRaw !== '') {
            $amount = Fa::number($amountRaw);
            if ($amount === null || $amount <= 0 || $amount > 1000000) {
                flash('error', 'مبلغ درخواستی باید عددی بزرگ‌تر از صفر و به میلیارد ریال باشد.');
                $this->toProposal($id, 'result');
            }
            $data['requested_amount'] = round($amount, 3);
            $notes[] = 'مبلغ درخواستی: ' . money($amount);
        } elseif ($result['amount_conflict']) {
            flash('error', 'مبلغ درخواستی شرکت را وارد کنید.');
            $this->toProposal($id, 'result');
        }
        if ($data) {
            DB::get()->update('proposals', $data + ['updated_at' => now_utc()], 'id = ?', [$id]);
            $new = Results::recompute($id);
            Activity::log('resolve', 'اختلاف نظر داوران توسط مدیر مشخص شد (' . implode('، ', $notes) . ').'
                . ($new ? ' امتیاز ' . nf($new['total'], 1) . '، مبلغ محاسبه‌شده ' . money($new['amount']) . '.' : ''), $id);
            flash('success', 'ثبت شد و نتیجه دوباره محاسبه شد.');
        }
        $this->toProposal($id, 'result');
    }

    public function finalize(array $params): void
    {
        $p = $this->proposalOr404($params);
        $id = (int) $p['id'];
        $decision = Request::str('decision');
        $amountRaw = Request::str('amount');
        $amount = $amountRaw === '' ? null : Fa::number($amountRaw);
        $note = Request::str('note');
        $errors = Results::finalize($id, $decision, $amount, $note, Auth::id());
        if ($errors) {
            foreach ($errors as $m) {
                flash('error', $m);
            }
            Session::keepInput($_POST);
            $this->toProposal($id, 'final');
        }
        $label = FormDef::decision($decision)['label'];
        Activity::log('finalize', 'تصمیم نهایی: ' . $label . ($decision !== 'reject' ? ' با مبلغ ' . money($amount) : '') . '.', $id);
        flash('success', 'تصمیم نهایی ثبت شد.');
        $this->toProposal($id, 'final');
    }

    public function unfinalize(array $params): void
    {
        $p = $this->proposalOr404($params);
        $id = (int) $p['id'];
        if (!empty($p['finalized_at'])) {
            Results::unfinalize($id);
            Activity::log('unfinalize', 'تصمیم نهایی لغو شد و طرح به مرحله «در انتظار تصمیم» برگشت.', $id);
            flash('success', 'تصمیم نهایی لغو شد.');
        }
        $this->toProposal($id, 'final');
    }

    public function refreshConfig(array $params): void
    {
        $p = $this->proposalOr404($params);
        $id = (int) $p['id'];
        if (!empty($p['finalized_at'])) {
            flash('error', 'تصمیم نهایی ثبت شده است؛ پارامترهای امتیازدهی این طرح قابل تغییر نیست.');
            $this->toProposal($id, 'result');
        }
        Proposals::freezeConfig($id, true);
        Results::recompute($id);
        Activity::log('settings', 'پارامترهای امتیازدهی این طرح با تنظیمات فعلی سامانه هماهنگ شد.', $id);
        flash('success', 'پارامترهای امتیازدهی این طرح به‌روز شد.');
        $this->toProposal($id, 'result');
    }

    public function delete(array $params): void
    {
        $p = $this->proposalOr404($params);
        Proposals::delete((int) $p['id']);
        Activity::log('delete', 'طرح ' . $p['code'] . ' «' . str_limit((string) $p['title'], 80) . '» به همراه فایل و فرم‌های آن حذف شد.');
        flash('success', 'طرح حذف شد.');
        redirect('/admin/proposals');
    }

    // ---- files & print ---------------------------------------------------------------

    public function download(array $params): void
    {
        $p = $this->proposalOr404($params);
        self::sendDocx($p);
    }

    public static function sendDocx(array $p): void
    {
        $file = Proposals::filePath($p);
        if (!is_file($file)) {
            throw new HttpException(404, 'فایل این طرح روی سرور پیدا نشد.');
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Length: ' . filesize($file));
        header('Content-Disposition: ' . content_disposition((string) $p['original_name']));
        header('X-Content-Type-Options: nosniff');
        readfile($file);
    }

    public function report(array $params): void
    {
        $p = $this->proposalOr404($params);
        $id = (int) $p['id'];
        $as = Assignments::forProposal($id);
        View::render('admin/report', [
            'pageTitle' => 'گزارش ارزش‌گذاری ' . $p['code'],
            'org' => (string) Settings::get('org_name', 'پارک فاوا'),
            'p' => $p,
            'fields' => Proposals::fields($p),
            'as' => $as,
            'answers' => ['tech' => Assignments::answers($as['tech']), 'biz' => Assignments::answers($as['biz'])],
            'cfg' => Proposals::config($p),
            'def' => FormDef::get(),
            'result' => Results::of($p),
        ], 'print');
    }

    /** What the judge of a role will see (nothing is saved). */
    public function preview(array $params): void
    {
        $p = $this->proposalOr404($params);
        $role = (string) $params['role'];
        $as = Assignments::forProposal((int) $p['id']);
        $data = FormController::formData($p, $role, $as[$role], true);
        View::render('form/show', $data, 'form');
    }
}
