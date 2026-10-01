<?php

use Modules\Notification\Http\Controllers\Api\V1\NotificationLogController;
use Modules\Notification\Http\Controllers\Api\V1\NotificationController;
use Modules\Notification\Http\Controllers\Api\V1\WhatsAppLogController;
use Modules\Notification\Http\Controllers\Api\V1\WhatsAppMessageController;
use Modules\Notification\Http\Controllers\Api\V1\TenantMailboxController;
use Modules\Notification\Http\Controllers\Api\V1\MailgunInboundController;
use Modules\Notification\Http\Controllers\Api\V1\MailgunEventController;

Route::post('mailgun/inbound', MailgunInboundController::class)
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware('throttle:120,1');
Route::post('mailgun/events', MailgunEventController::class)
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware('throttle:240,1');

Route::prefix('tenant-mailbox')->middleware(['can:admin.notifications.manage', 'throttle:60,1'])->group(function () {
    Route::get('/meta', [TenantMailboxController::class, 'meta']);
    Route::post('/delivery-status/sync', [TenantMailboxController::class, 'syncDeliveryStatus'])->middleware('throttle:10,1');
    Route::get('/', [TenantMailboxController::class, 'index']);
    Route::get('/{reference}', [TenantMailboxController::class, 'show'])->whereUuid('reference');
    Route::patch('/{reference}/read-state', [TenantMailboxController::class, 'updateReadState'])->whereUuid('reference');
    Route::post('/send', [TenantMailboxController::class, 'send'])->middleware('throttle:20,1');
    Route::get('/attachments/{attachment}', [TenantMailboxController::class, 'downloadAttachment'])->whereNumber('attachment');
});

Route::controller(NotificationController::class)
    ->prefix('notifications')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.notifications.index');
        Route::get('/communication-meta', 'communicationMeta')->middleware('can:admin.notifications.manage');
        Route::post('/internal-message', 'internalMessage')->middleware('can:admin.notifications.manage');
        Route::get('/unread-count', 'unreadCount')->middleware('can:admin.notifications.index');
        Route::post('/read-all', 'readAll')->middleware('can:admin.notifications.read');
        Route::delete('/clear', 'clear')->middleware('can:admin.notifications.clear');
        Route::post('/{id}/read', 'read')->middleware('can:admin.notifications.read');
        Route::post('/{id}/dismiss', 'dismiss')->middleware('can:admin.notifications.dismiss');
        Route::get('/channels', 'channels')->middleware('can:admin.notifications.manage');
    });

Route::get('notification-logs', [NotificationLogController::class, 'index'])
    ->middleware('can:admin.notifications.logs');

Route::get('whatsapp-logs', [WhatsAppLogController::class, 'index'])
    ->middleware('can:admin.whatsapp.logs');

Route::get('whatsapp/campaign-summary', [WhatsAppLogController::class, 'campaignSummary'])
    ->middleware('permission:admin.whatsapp.logs|admin.whatsapp.broadcast');

Route::get('whatsapp/meta', [WhatsAppMessageController::class, 'meta'])
    ->middleware('permission:admin.whatsapp.direct_message|admin.whatsapp.broadcast');

Route::post('whatsapp/audience-preview', [WhatsAppMessageController::class, 'audiencePreview'])
    ->middleware('can:admin.whatsapp.broadcast');

Route::post('whatsapp/direct-message', [WhatsAppMessageController::class, 'direct'])
    ->middleware('can:admin.whatsapp.direct_message');
Route::get('whatsapp/integration-status', [WhatsAppMessageController::class, 'integrationStatus'])->middleware('can:admin.settings.edit');
Route::post('whatsapp/integration-validate', [WhatsAppMessageController::class, 'validateIntegration'])->middleware(['can:admin.settings.edit', 'throttle:10,1']);
Route::post('whatsapp/nexmsg/test', [WhatsAppMessageController::class, 'testNexMsg'])->middleware(['can:admin.settings.edit', 'throttle:3,1']);
Route::post('whatsapp/templates/sync', [WhatsAppMessageController::class, 'syncTemplates'])->middleware(['can:admin.settings.edit', 'throttle:10,1']);

Route::post('whatsapp/bulk-message', [WhatsAppMessageController::class, 'bulk'])
    ->middleware('can:admin.whatsapp.broadcast');

Route::post('whatsapp/run-inactive-customer-offer', [WhatsAppMessageController::class, 'runInactiveCustomerOffer'])
    ->middleware('can:admin.whatsapp.campaigns');

Route::post('whatsapp/run-birthday-offer', [WhatsAppMessageController::class, 'runBirthdayOffer'])
    ->middleware('can:admin.whatsapp.campaigns');

Route::post('whatsapp/run-anniversary-offer', [WhatsAppMessageController::class, 'runAnniversaryOffer'])
    ->middleware('can:admin.whatsapp.campaigns');

Route::post('whatsapp/webhook', [WhatsAppMessageController::class, 'webhook'])
    ->withoutMiddleware('api')
    ->middleware(['throttle:100,1', \App\Http\Middleware\VerifyWebhookSignature::class])
    ->name('whatsapp.webhook');
