<?php

use Fava\Controllers\ActivityController;
use Fava\Controllers\AuthController;
use Fava\Controllers\DashboardController;
use Fava\Controllers\ExportController;
use Fava\Controllers\FormController;
use Fava\Controllers\InstallController;
use Fava\Controllers\JudgeController;
use Fava\Controllers\ProposalController;
use Fava\Controllers\SettingsController;
use Fava\Core\Router;

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

$r = new Router();

// ---- first-run installer -------------------------------------------------
$r->get('/install', [InstallController::class, 'index'], 'install');
$r->post('/install', [InstallController::class, 'run'], 'install');
$r->get('/__rewrite_test', [InstallController::class, 'rewriteTest'], 'public');

// ---- judges: private form link (no login, no cookies) ---------------------
$r->get('/f/{token}', [FormController::class, 'show'], 'public');
$r->post('/f/{token}/draft', [FormController::class, 'draft'], 'public');
$r->post('/f/{token}/submit', [FormController::class, 'submit'], 'public');
$r->get('/f/{token}/file', [FormController::class, 'file'], 'public');

// ---- admin ---------------------------------------------------------------
$r->get('/', [DashboardController::class, 'home'], 'public');
$r->get('/admin/login', [AuthController::class, 'form'], 'guest');
$r->post('/admin/login', [AuthController::class, 'login'], 'guest');
$r->post('/admin/logout', [AuthController::class, 'logout']);

$r->get('/admin', [DashboardController::class, 'index']);

$r->get('/admin/proposals', [ProposalController::class, 'index']);
$r->post('/admin/proposals/upload', [ProposalController::class, 'upload']);
$r->get('/admin/proposals/{id}', [ProposalController::class, 'show']);
$r->post('/admin/proposals/{id}/update', [ProposalController::class, 'update']);
$r->post('/admin/proposals/{id}/reextract', [ProposalController::class, 'reextract']);
$r->post('/admin/proposals/{id}/assign/{role}', [ProposalController::class, 'assign']);
$r->post('/admin/proposals/{id}/unassign/{role}', [ProposalController::class, 'unassign']);
$r->post('/admin/proposals/{id}/send', [ProposalController::class, 'send']);
$r->post('/admin/proposals/{id}/mark-sent/{role}', [ProposalController::class, 'markSent']);
$r->post('/admin/proposals/{id}/regenerate/{role}', [ProposalController::class, 'regenerate']);
$r->post('/admin/proposals/{id}/reopen/{role}', [ProposalController::class, 'reopen']);
$r->post('/admin/proposals/{id}/resolve', [ProposalController::class, 'resolve']);
$r->post('/admin/proposals/{id}/finalize', [ProposalController::class, 'finalize']);
$r->post('/admin/proposals/{id}/unfinalize', [ProposalController::class, 'unfinalize']);
$r->post('/admin/proposals/{id}/refresh-config', [ProposalController::class, 'refreshConfig']);
$r->post('/admin/proposals/{id}/delete', [ProposalController::class, 'delete']);
$r->get('/admin/proposals/{id}/file', [ProposalController::class, 'download']);
$r->get('/admin/proposals/{id}/report', [ProposalController::class, 'report']);
$r->get('/admin/proposals/{id}/preview/{role}', [ProposalController::class, 'preview']);

$r->get('/admin/judges', [JudgeController::class, 'index']);
$r->post('/admin/judges/save', [JudgeController::class, 'save']);
$r->post('/admin/judges/{id}/toggle', [JudgeController::class, 'toggle']);
$r->post('/admin/judges/{id}/delete', [JudgeController::class, 'delete']);

$r->get('/admin/activity', [ActivityController::class, 'index']);

$r->get('/admin/settings', [SettingsController::class, 'index']);
$r->post('/admin/settings/general', [SettingsController::class, 'saveGeneral']);
$r->post('/admin/settings/scoring', [SettingsController::class, 'saveScoring']);
$r->post('/admin/settings/mail', [SettingsController::class, 'saveMail']);
$r->post('/admin/settings/test-mail', [SettingsController::class, 'testMail']);
$r->post('/admin/settings/password', [SettingsController::class, 'savePassword']);

$r->get('/admin/export/proposals', [ExportController::class, 'proposals']);
$r->get('/admin/export/scores', [ExportController::class, 'scores']);

return $r;
