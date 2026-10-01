<?php

use Modules\Printer\Http\Controllers\Api\V1\AgentPollController;
use Modules\Printer\Http\Controllers\Api\V1\PrintAgentController;
use Modules\Printer\Http\Controllers\Api\V1\PrintAgentPairingController;
use Modules\Printer\Http\Controllers\Api\V1\PrinterAssignmentController;
use Modules\Printer\Http\Controllers\Api\V1\PrintJobController;
use Modules\Printer\Http\Controllers\Api\V1\PrinterController;
use Modules\Printer\Http\Middleware\ValidateAgentSignature;

Route::controller(PrintAgentPairingController::class)
    ->prefix('agent-pairings')
    ->withoutMiddleware('auth')
    ->group(function () {
        Route::post('/', 'create')->middleware('throttle:5,1');
        Route::get('/{pairing}/status', 'status')->middleware('throttle:30,1');
    });

Route::middleware('tenant.feature:printer')->group(function () {
Route::controller(PrinterController::class)
    ->prefix('printers')
    ->group(function () {
        Route::get('/', 'index')->middleware('permission:admin.printers.index|admin.orders.print');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.printers.edit|admin.printers.create');
        Route::post('/{id}/test-print', 'testPrint')->middleware('permission:admin.printers.edit|admin.orders.print');
        Route::get('/{id}', 'show')->middleware('permission:admin.printers.show|admin.printers.edit');
        Route::post('/', 'store')->middleware('can:admin.printers.create');
        Route::put('/{id}', 'update')->middleware('can:admin.printers.edit');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.printers.destroy');
    });

Route::controller(PrintAgentController::class)
    ->prefix('print-agents')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.print_agents.index');
        Route::get('/scripts/{platform}', 'downloadScript')->middleware('can:admin.print_agents.index');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.print_agents.edit|admin.print_agents.create');
        Route::get('/{id}', 'show')->middleware('permission:admin.print_agents.show|admin.print_agents.edit');
        Route::get('/{id}/telemetry', 'telemetry')->middleware('can:admin.print_agents.index');
        Route::get('/{id}/logs', 'logs')->middleware('can:admin.print_agents.index');
        Route::get('/{id}/commands', 'agentCommands')->middleware('can:admin.print_agents.index');
        Route::post('/{id}/commands', 'issueCommand')->middleware('can:admin.print_agents.edit');
        Route::get('/{id}/support', 'supportStatus')->middleware('can:admin.print_agents.index');
        Route::get('/{id}/voice-health', 'voiceHealth')->middleware('can:admin.print_agents.index');
        Route::get('/{id}/voice-diagnostics', 'voiceDiagnostics')->middleware('can:admin.print_agents.index');
        Route::get('/{id}/voice-test-results', 'voiceTestResults')->middleware('can:admin.print_agents.index');
        Route::get('/{id}/voice-export', 'voiceExportBundle')->middleware('can:admin.print_agents.index');
        Route::post('/', 'store')->middleware('can:admin.print_agents.create');
        Route::put('/{id}', 'update')->middleware('can:admin.print_agents.edit');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.print_agents.destroy');
    });

Route::post('print-agent-pairings/claim', [PrintAgentPairingController::class, 'claim'])
    ->middleware(['can:admin.print_agents.edit', 'throttle:10,1']);

Route::controller(PrintJobController::class)
    ->prefix('print-jobs')
    ->group(function () {
        Route::get('/', 'index')->middleware('permission:admin.print_jobs.index|admin.orders.print');
        Route::get('/summary', 'summary')->middleware('permission:admin.print_jobs.index|admin.orders.print');
        Route::get('/diagnostics', 'diagnostics')->middleware('permission:admin.print_jobs.index|admin.orders.print');
        Route::post('/{id}/retry', 'retry')->middleware('permission:admin.print_jobs.retry|admin.orders.print');
    });

Route::controller(PrinterAssignmentController::class)
    ->prefix('printer-assignments')
    ->group(function () {
        Route::get('/', 'show')->middleware('can:admin.printer_assignments.index');
        Route::put('/', 'update')->middleware('can:admin.printer_assignments.edit');
    });

Route::middleware(ValidateAgentSignature::class)
    ->withoutMiddleware('auth')
    ->controller(AgentPollController::class)
    ->prefix("agents/{agent_id}")
    ->group(function () {
        Route::get('setup', 'setup')->middleware('throttle:10,1');
        Route::post('poll', 'poll');
        Route::post('jobs/{job_id}', 'job');
        Route::post('jobs/{job_id}/status', 'jobStatus');
        Route::post('report', 'report');
        Route::post('heartbeat', 'heartbeat');
        Route::post('telemetry', 'telemetry');
        // Rate-limit log uploads: 20 requests/min per agent to prevent log flooding
        Route::post('logs', 'uploadLogs')->middleware('throttle:20,1');
        Route::post('incident', 'reportIncident')->middleware('throttle:10,1');
        // Rate-limit voice health uploads: 5 requests/min (60s loop + some tolerance)
        Route::post('voice/health', 'reportVoiceHealth')->middleware('throttle:5,1');
        // Rate-limit voice diagnostics: 3 requests/min (triggered on-demand)
        Route::post('voice/diagnostics', 'reportVoiceDiagnostics')->middleware('throttle:3,1');
        // Rate-limit voice test results: 10 requests/min
        Route::post('voice/test-result', 'reportVoiceTestResult')->middleware('throttle:10,1');
        // Rate-limit voice alerts: 20 requests/min (5 alert types × 4/min tolerance)
        Route::post('voice/alert', 'reportVoiceAlert')->middleware('throttle:20,1');
        Route::post('voice/export-bundle', 'receiveExportBundle')->middleware('throttle:5,1');
        Route::get('commands', 'commands');
        Route::post('commands/{command_id}/ack', 'commandAck');
        Route::post('broadcasting/auth', 'broadcastingAuth');
        Route::post('verify', 'verify');
        Route::post('test-print', 'testPrint');
    });
});
