<?php

namespace Fava\Controllers;

use Fava\Core\Request;
use Fava\Domain\Activity;

class ActivityController extends Controller
{
    public function index(array $params): void
    {
        $action = (string) Request::query('action', '');
        $page = $this->pageNumber();
        $perPage = 40;
        list($rows, $total) = Activity::page($page, $perPage, $action);
        $this->admin('admin/activity', [
            'pageTitle' => 'گزارش فعالیت‌ها',
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'action' => $action,
            'labels' => Activity::LABELS,
        ], 'activity');
    }
}
