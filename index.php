<?php

declare(strict_types=1);

/**
 * Prospector — front controller.
 */

require __DIR__ . '/app/bootstrap.php';

use Prospector\Api;
use Prospector\Auth;
use Prospector\Http\Controller;
use Prospector\Http\Outbox;
use Prospector\Http\Workspace;
use Prospector\Support\Request;
use Prospector\Support\View;

$path = Request::path();
$method = Request::method();

// The worker API is token-authenticated and stateless — no session, no CSRF,
// and it must answer before any of the HTML plumbing runs.
if (preg_match('#^/api/([a-z_]+)$#', $path, $apiMatch) === 1) {
    Api::handle($apiMatch[1]);
    exit;
}

Auth::start();

View::share([
    'currentPath' => $path,
    'currentUser' => Auth::user(),
    'csrf' => Auth::csrfToken(),
    'flash' => Controller::takeFlash(),
]);

try {
    // /leads/{id} and /leads/{id}/{action}
    // Hyphens allowed so multi-word actions like dig-apply route cleanly.
    if (preg_match('#^/leads/(\d+)(?:/([a-z-]+))?$#', $path, $m) === 1) {
        $id = (int) $m[1];
        $action = $m[2] ?? '';

        if ($action === '') {
            Controller::lead($id);
        } elseif ($method === 'POST') {
            Controller::leadAction($id, $action);
        } else {
            // Actions are POST-only. A GET here is someone refreshing or going
            // back to an action URL, so show them the lead rather than a 404.
            Controller::redirect('/leads/' . $id);
        }
        exit;
    }

    if (preg_match('#^/runs/(\d+)$#', $path, $m) === 1) {
        Controller::run((int) $m[1]);
        exit;
    }

    match (true) {
        $path === '/' => Auth::check() ? Controller::redirect('/dashboard') : Controller::redirect('/login'),

        $path === '/login' => Controller::login(),
        $path === '/logout' => Controller::logout(),

        $path === '/dashboard' => Controller::dashboard(),

        $path === '/leads' => Controller::leads(),
        $path === '/leads/export' => Controller::leadsExport(),
        $path === '/leads/new' => Controller::leadsNew(),
        $path === '/leads/import' => Controller::leadsImport(),
        // Named with real extensions so the browser and Excel both know what
        // they just downloaded.
        $path === '/leads/sample.csv' => Controller::leadsSample('csv'),
        $path === '/leads/sample.json' => Controller::leadsSample('json'),
        $path === '/leads/bulk' && $method === 'POST' => Controller::leadsBulk(),

        $path === '/runs' => Controller::runs(),
        $path === '/runs/start' && $method === 'POST' => Controller::runStart(),

        // Outreach: cadence copy, review, and sending.
        $path === '/outreach' => Outbox::index(),
        $path === '/outreach/lead' => Outbox::lead(),
        $path === '/outreach/build' && $method === 'POST' => Outbox::build(),
        $path === '/outreach/approve' && $method === 'POST' => Outbox::approve(),
        $path === '/outreach/send' && $method === 'POST' => Outbox::send(),
        $path === '/outreach/step' && $method === 'POST' => Outbox::step(),

        // The GoHighLevel workspace. /ghl is the pipeline board; the old
        // read-only summary lives on at /ghl/summary.
        $path === '/ghl' => Workspace::board(),
        $path === '/ghl/contacts' => Workspace::contacts(),
        $path === '/ghl/contact' => Workspace::contact(),
        $path === '/ghl/inbox' => Workspace::inbox(),
        $path === '/ghl/automations' => Workspace::automations(),
        $path === '/ghl/summary' => Controller::ghl(),

        $path === '/ghl/connect' && $method === 'POST' => Workspace::connectSave(),
        $path === '/ghl/connect' => Workspace::connect(),
        $path === '/ghl/disconnect' && $method === 'POST' => Workspace::disconnect(),
        $path === '/ghl/signature' && $method === 'POST' => Workspace::signature(),

        $path === '/ghl/move' && $method === 'POST' => Workspace::move(),
        $path === '/ghl/status' && $method === 'POST' => Workspace::status(),
        $path === '/ghl/note' && $method === 'POST' => Workspace::note(),
        $path === '/ghl/task' && $method === 'POST' => Workspace::task(),
        $path === '/ghl/send' && $method === 'POST' => Workspace::send(),
        $path === '/ghl/enroll' && $method === 'POST' => Workspace::enroll(),
        $path === '/ghl/rule' && $method === 'POST' => Workspace::rule(),
        $path === '/ghl/sweep' && $method === 'POST' => Workspace::sweep(),

        $path === '/settings' => Controller::settings(),
        $path === '/settings/test/anthropic' && $method === 'POST' => Controller::settingsTest('anthropic'),
        $path === '/settings/test/ghl' && $method === 'POST' => Controller::settingsTest('ghl'),
        $path === '/settings/test/email' && $method === 'POST' => Controller::settingsTest('email'),
        $path === '/settings/test/local' && $method === 'POST' => Controller::settingsTest('local'),

        $path === '/users' => Controller::users(),
        $path === '/users/save' && $method === 'POST' => Controller::usersSave(),
        $path === '/users/delete' && $method === 'POST' => Controller::usersDelete(),

        default => Controller::notFound(),
    };
} catch (\Throwable $e) {
    \Prospector\Support\Background::log('Unhandled error on ' . $path . ': ' . $e->getMessage());

    http_response_code(500);
    View::render('error_standalone', [
        'code' => 500,
        'heading' => 'Something broke',
        'message' => $e->getMessage(),
    ]);
}
