<?php

namespace Fava\Controllers;

use Fava\Core\Auth;
use Fava\Core\HttpException;
use Fava\Core\Request;
use Fava\Core\Session;
use Fava\Core\View;
use Fava\Domain\Proposals;
use Fava\Domain\Settings;

abstract class Controller
{
    /** Render an admin page inside the panel layout. */
    protected function admin(string $view, array $data = [], string $nav = ''): void
    {
        $data += [
            'nav' => $nav,
            'pageTitle' => '',
            'adminName' => Auth::name(),
            'org' => (string) Settings::get('org_name', 'پارک فاوا'),
            'systemTitle' => (string) Settings::get('system_title', 'سامانه ارزش‌گذاری طرح‌ها'),
            'flashes' => Session::flashes(),
        ];
        View::render($view, $data, 'admin');
    }

    /** Render a stand-alone page (login, installer, judge pages). */
    protected function page(string $view, array $data = [], string $layout = 'plain', int $status = 200): void
    {
        $data += [
            'pageTitle' => '',
            'org' => (string) $this->orgName(),
            'flashes' => Session::flashes(),
        ];
        View::render($view, $data, $layout, $status);
    }

    protected function orgName(): string
    {
        try {
            return (string) Settings::get('org_name', 'پارک فاوا');
        } catch (\Throwable $e) {
            return 'پارک فاوا';
        }
    }

    protected function proposalOr404(array $params): array
    {
        $p = Proposals::find((int) ($params['id'] ?? 0));
        if (!$p) {
            throw new HttpException(404, 'طرح مورد نظر پیدا نشد. ممکن است حذف شده باشد.');
        }
        return $p;
    }

    protected function json($data, int $status = 200): void
    {
        View::json($data, $status);
    }

    /** Flash a message and go back to a proposal page (optionally to one of its sections). */
    protected function toProposal(int $id, string $anchor = ''): void
    {
        header('Location: ' . url('/admin/proposals/' . $id) . ($anchor !== '' ? '#' . $anchor : ''), true, 302);
        exit;
    }

    protected function pageNumber(): int
    {
        return max(1, Request::int('page', 1));
    }
}
