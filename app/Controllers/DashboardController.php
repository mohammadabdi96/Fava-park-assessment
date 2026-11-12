<?php

namespace Fava\Controllers;

use Fava\Core\DB;
use Fava\Domain\Activity;
use Fava\Domain\Assignments;
use Fava\Domain\Mailer;
use Fava\Domain\Proposals;
use Fava\Domain\Results;

class DashboardController extends Controller
{
    public function home(array $params): void
    {
        redirect('/admin');
    }

    public function index(array $params): void
    {
        $db = DB::get();
        $cols = 'id, code, title, company, status, extract_status, total_score, system_decision, needs_resolution, updated_at, created_at, due_at';

        // what the admin has to do next, most urgent first
        $todo = [];
        foreach ($db->all("SELECT $cols FROM {proposals} WHERE status = 'completed' ORDER BY computed_at ASC LIMIT 6") as $p) {
            $todo[] = ['p' => $p, 'kind' => $p['needs_resolution'] ? 'resolve' : 'finalize'];
        }
        // being judged, but one judge was never chosen or never received the link: nobody else will notice
        $stuck = "SELECT $cols FROM {proposals} WHERE status = 'judging' AND "
            . "(SELECT COUNT(*) FROM {assignments} a WHERE a.proposal_id = {proposals}.id AND a.status <> 'pending') < 2 ORDER BY id ASC LIMIT 6";
        foreach ($db->all($stuck) as $p) {
            $todo[] = ['p' => $p, 'kind' => 'gap'];
        }
        foreach ($db->all("SELECT $cols FROM {proposals} WHERE status = 'review' ORDER BY id ASC LIMIT 6") as $p) {
            $todo[] = ['p' => $p, 'kind' => 'review'];
        }
        foreach ($db->all("SELECT $cols FROM {proposals} WHERE status = 'ready' ORDER BY id ASC LIMIT 6") as $p) {
            $todo[] = ['p' => $p, 'kind' => 'send'];
        }

        $this->admin('admin/dashboard', [
            'pageTitle' => 'داشبورد',
            'counts' => Proposals::counts(),
            'summary' => Results::summary(),
            'todo' => array_slice($todo, 0, 10),
            'todoTotal' => count($todo),
            'waiting' => Assignments::pending(8),
            'latest' => Proposals::latest(8),
            'activity' => Activity::recent(10),
            'mailReady' => Mailer::configured(),
            'judgeCount' => (int) $db->val('SELECT COUNT(*) FROM {judges} WHERE is_active = 1'),
        ], 'dashboard');
    }
}
