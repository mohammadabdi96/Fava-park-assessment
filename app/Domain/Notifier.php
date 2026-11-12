<?php

namespace Fava\Domain;

use Fava\Core\DB;
use Fava\Core\Logger;
use Fava\Core\View;

/**
 * Builds the e-mails of the process: the invitation with the private link, the
 * reminder, and the note to the admin when a judge has submitted.
 */
class Notifier
{
    public const PLACEHOLDERS = [
        '{judge}' => 'نام داور',
        '{role}' => 'نقش (داور فنی / داور کسب‌وکاری)',
        '{title}' => 'عنوان طرح',
        '{company}' => 'نام شرکت',
        '{code}' => 'کد رهگیری طرح',
        '{org}' => 'نام سازمان',
        '{deadline}' => 'مهلت تکمیل (اگر تعیین شده باشد)',
        '{link}' => 'پیوند اختصاصی فرم',
    ];

    public static function vars(array $proposal, array $assignment): array
    {
        $deadline = '';
        if (!empty($proposal['due_at'])) {
            $deadline = 'مهلت تکمیل فرم: ' . jdate($proposal['due_at'], 'j F Y') . "\n\n";
        }
        return [
            '{judge}' => (string) $assignment['judge_name'],
            '{role}' => role_label((string) $assignment['role']),
            '{title}' => (string) $proposal['title'],
            '{company}' => (string) $proposal['company'],
            '{code}' => (string) $proposal['code'],
            '{org}' => (string) Settings::get('org_name', 'پارک فاوا'),
            '{deadline}' => $deadline,
            '{link}' => Assignments::link($assignment),
        ];
    }

    public static function fill(string $template, array $vars): string
    {
        $out = strtr($template, $vars);
        // no more than one empty line in a row (an empty {deadline} leaves a gap)
        return trim((string) preg_replace("/\n{3,}/", "\n\n", str_replace(["\r\n", "\r"], "\n", $out)));
    }

    /** @return array{subject:string,text:string,html:string} */
    public static function inviteMessage(array $proposal, array $assignment, bool $reminder = false): array
    {
        $vars = self::vars($proposal, $assignment);
        $link = $vars['{link}'];
        $subject = self::fill((string) Settings::get($reminder ? 'mail_reminder_subject' : 'mail_invite_subject'), $vars);
        $subject = (string) preg_replace('/\s+/u', ' ', $subject);
        $body = self::fill((string) Settings::get($reminder ? 'mail_reminder_body' : 'mail_invite_body'), $vars);

        $text = $body . "\n\n— — —\nپیوند اختصاصی شما برای تکمیل کاربرگ:\n" . $link . "\n";
        $html = View::partial('emails/message', [
            'org' => $vars['{org}'],
            'heading' => $reminder ? 'یادآوری داوری طرح' : 'دعوت به داوری طرح',
            'body' => $body,
            'button' => ['label' => 'مشاهده طرح و تکمیل کاربرگ', 'url' => $link],
            'facts' => [
                'طرح' => $vars['{title}'],
                'شرکت' => $vars['{company}'],
                'نقش شما' => $vars['{role}'],
            ],
            'footnote' => 'اگر دکمه بالا کار نکرد، این نشانی را در مرورگر خود باز کنید:',
            'footlink' => $link,
        ]);
        return ['subject' => $subject, 'text' => $text, 'html' => $html];
    }

    /** Send the invitation (or a reminder) to the judge of an assignment. */
    public static function invite(array $proposal, array $assignment, bool $reminder = false): array
    {
        if (empty($assignment['judge_email'])) {
            return ['ok' => false, 'error' => 'برای این داور نشانی ایمیل ثبت نشده است؛ پیوند را کپی و دستی ارسال کنید.'];
        }
        $msg = self::inviteMessage($proposal, $assignment, $reminder);
        return Mailer::send(
            (string) $assignment['judge_email'],
            (string) $assignment['judge_name'],
            $msg['subject'],
            $msg['text'],
            $msg['html'],
            [
                'kind' => $reminder ? 'reminder' : 'invite',
                'proposal_id' => (int) $proposal['id'],
                'assignment_id' => (int) $assignment['id'],
                'reply_to' => self::adminEmail(),
            ]
        );
    }

    /** Address that receives the system's notes to the admin. */
    public static function adminEmail(): string
    {
        $email = trim((string) Settings::get('notify_email', ''));
        if ($email === '') {
            try {
                $email = (string) DB::get()->val("SELECT email FROM {admins} WHERE email IS NOT NULL AND email <> '' ORDER BY id ASC LIMIT 1");
            } catch (\Throwable $e) {
                $email = '';
            }
        }
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /** Tell the admin that a judge has sent the form (and the result, when both are in). */
    public static function judgeSubmitted(array $proposal, array $assignment, ?array $result): void
    {
        try {
            if (!Settings::get('notify_on_submit', 1) || !Mailer::configured()) {
                return;
            }
            $to = self::adminEmail();
            if ($to === '') {
                return;
            }
            $org = (string) Settings::get('org_name', 'پارک فاوا');
            $role = role_label((string) $assignment['role']);
            $title = (string) $proposal['title'];
            $url = abs_url('/admin/proposals/' . (int) $proposal['id']);
            $facts = ['طرح' => $title, 'شرکت' => (string) $proposal['company'], 'کد رهگیری' => (string) $proposal['code']];
            if ($result) {
                $decision = FormDef::decision($result['decision'])['label'];
                $subject = 'داوری طرح «' . str_limit($title, 60) . '» کامل شد';
                $body = $role . ' (' . $assignment['judge_name'] . ") فرم خود را ثبت کرد و اکنون هر دو داوری این طرح کامل است.\n\n"
                    . 'نتیجه محاسبه سامانه: امتیاز ' . nf($result['total'], 1) . ' از ۱۰۰ — ' . $decision . ".\n"
                    . ($result['needs_resolution'] ? "نظر دو داور در سطح یا مبلغ درخواستی یکسان نیست و باید توسط شما مشخص شود.\n" : '')
                    . "\nبرای بررسی نتیجه و ثبت تصمیم نهایی وارد پنل مدیریت شوید.";
                $facts['امتیاز کل'] = nf($result['total'], 1) . ' از ۱۰۰';
                $facts['پیشنهاد سامانه'] = $decision;
            } else {
                $subject = 'فرم ' . $role . ' برای طرح «' . str_limit($title, 60) . '» ثبت شد';
                $body = $role . ' (' . $assignment['judge_name'] . ") فرم خود را ثبت کرد.\nنتیجه نهایی پس از ثبت فرم داور دیگر محاسبه می‌شود.";
            }
            $html = View::partial('emails/message', [
                'org' => $org,
                'heading' => $result ? 'داوری طرح کامل شد' : 'یک فرم داوری ثبت شد',
                'body' => $body,
                'button' => ['label' => 'مشاهده در پنل مدیریت', 'url' => $url],
                'facts' => $facts,
                'footnote' => '',
                'footlink' => '',
            ]);
            Mailer::send($to, '', $subject, $body . "\n\n" . $url . "\n", $html, [
                'kind' => 'admin_notice',
                'proposal_id' => (int) $proposal['id'],
                'assignment_id' => (int) $assignment['id'],
            ]);
        } catch (\Throwable $e) {
            Logger::exception($e, 'admin notification');
        }
    }
}
