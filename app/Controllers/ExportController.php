<?php

namespace Fava\Controllers;

use Fava\Core\DB;
use Fava\Core\Jalali;
use Fava\Domain\FormDef;
use Fava\Domain\Labels;

/**
 * CSV exports for Excel (UTF-8 with BOM, so Persian text opens correctly).
 */
class ExportController extends Controller
{
    private function start(string $name): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: ' . content_disposition($name . '-' . Jalali::format('Ymd') . '.csv'));
        echo "\xEF\xBB\xBF";
    }

    /** One CSV line; cells that Excel would read as a formula are neutralised. */
    private function line(array $cells): void
    {
        $out = [];
        foreach ($cells as $c) {
            $c = $c === null ? '' : (string) $c;
            if ($c !== '' && strpbrk($c[0], "=+-@\t\r") !== false && !is_numeric($c)) {
                $c = "'" . $c;
            }
            $out[] = '"' . str_replace('"', '""', $c) . '"';
        }
        echo implode(',', $out) . "\r\n";
    }

    private function when($utc): string
    {
        return $utc ? Jalali::format('Y/m/d H:i', (int) strtotime($utc . ' UTC')) : '';
    }

    public function proposals(array $params): void
    {
        $db = DB::get();
        $def = FormDef::get();
        $assign = [];
        foreach ($db->all('SELECT proposal_id, role, judge_name, judge_email, status, sent_at, submitted_at FROM {assignments}') as $a) {
            $assign[(int) $a['proposal_id']][$a['role']] = $a;
        }
        $this->start('proposals');
        $this->line([
            'کد', 'عنوان طرح', 'شرکت', 'وضعیت', 'تاریخ بارگذاری', 'مبلغ درخواستی (میلیارد ریال)',
            'داور فنی', 'وضعیت فرم فنی', 'تاریخ ثبت فرم فنی', 'داور کسب‌وکاری', 'وضعیت فرم کسب‌وکاری', 'تاریخ ثبت فرم کسب‌وکاری',
            'امتیاز کل', 'پیشنهاد سامانه', 'سطح', 'مبلغ محاسبه‌شده (میلیارد ریال)',
            'تصمیم نهایی', 'مبلغ نهایی (میلیارد ریال)', 'تاریخ تصمیم نهایی', 'توضیح تصمیم',
        ]);
        $rows = $db->all('SELECT id, code, title, company, status, created_at, requested_amount, total_score, system_decision, result_tier, result_amount, final_decision, final_amount, finalized_at, final_note FROM {proposals} ORDER BY id ASC');
        foreach ($rows as $p) {
            $t = $assign[(int) $p['id']]['tech'] ?? null;
            $b = $assign[(int) $p['id']]['biz'] ?? null;
            $this->line([
                $p['code'], $p['title'], $p['company'], Labels::proposal((string) $p['status'])[0], $this->when($p['created_at']), $p['requested_amount'],
                $t['judge_name'] ?? '', Labels::assignment($t['status'] ?? null)[0], $this->when($t['submitted_at'] ?? null),
                $b['judge_name'] ?? '', Labels::assignment($b['status'] ?? null)[0], $this->when($b['submitted_at'] ?? null),
                $p['total_score'], $p['system_decision'] ? $def['decisions'][$p['system_decision']]['label'] : '',
                $p['result_tier'] ? ($def['defaults']['tiers'][$p['result_tier']]['short'] ?? $p['result_tier']) : '', $p['result_amount'],
                $p['final_decision'] ? $def['decisions'][$p['final_decision']]['label'] : '', $p['final_amount'], $this->when($p['finalized_at']), $p['final_note'],
            ]);
        }
    }

    /** One row per submitted form, with every criterion score. */
    public function scores(array $params): void
    {
        $db = DB::get();
        $def = FormDef::get();
        $criteria = [];
        foreach ($def['dimensions'] as $dim => $d) {
            foreach ($d['criteria'] as $id => $label) {
                $criteria[$id] = $d['label'] . ' — ' . $label;
            }
        }
        $this->start('scores');
        $this->line(array_merge(
            ['کد طرح', 'عنوان طرح', 'شرکت', 'نقش داور', 'نام داور', 'تاریخ ثبت', 'مقیاس‌پذیری', 'اولویت استراتژیک', 'سطح', 'سهم امتیاز'],
            array_values($criteria),
            ['TRL', 'ریسک اجرا', 'ریسک بازار', 'ضریب ریسک داور', 'جمع‌بندی داور']
        ));
        $rows = $db->all(
            "SELECT a.role, a.judge_name, a.submitted_at, a.answers_json, a.metrics_json, p.code, p.title, p.company "
            . "FROM {assignments} a JOIN {proposals} p ON p.id = a.proposal_id WHERE a.status = 'submitted' ORDER BY p.id ASC, a.role DESC"
        );
        foreach ($rows as $r) {
            $a = json_in($r['answers_json'], []);
            $m = json_in($r['metrics_json'], []);
            $cells = [
                $r['code'], $r['title'], $r['company'], role_label($r['role']), $a['name'] ?? $r['judge_name'], $this->when($r['submitted_at']),
                isset($a['scalability']) ? ($def['scalability'][$a['scalability']][0] ?? '') : '',
                isset($a['priority']) ? ($def['priority'][$a['priority']][0] ?? '') : '',
                isset($m['tier']) ? ($def['defaults']['tiers'][$m['tier']]['short'] ?? $m['tier']) : '',
                isset($m['share']) ? round((float) $m['share'], 2) : '',
            ];
            foreach (array_keys($criteria) as $id) {
                $cells[] = $a['scores'][$id] ?? '';
            }
            $cells[] = $a['trl'] ?? '';
            $cells[] = $a['risk_exec'] ?? '';
            $cells[] = $a['risk_market'] ?? '';
            $cells[] = isset($m['coeff']) ? round((float) $m['coeff'], 3) : '';
            $cells[] = isset($a['reasons']) ? implode(' | ', (array) $a['reasons']) : '';
            $this->line($cells);
        }
    }
}
