<?php

use Modules\Order\Http\Controllers\Api\V1\DeliveryProviderApiLogController;
use Modules\Order\Http\Controllers\Api\V1\DeliveryWalletController;
use Modules\Order\Http\Controllers\Api\V1\DeliveryWalletFundingController;
use Modules\Saas\Http\Controllers\Api\V1\CustomerAppBootstrapController;
use Modules\Saas\Http\Controllers\Api\V1\CustomerAppBuildController;
use Modules\Saas\Http\Controllers\Api\V1\CustomerAppBuildWorkerController;
use Modules\Saas\Http\Controllers\Api\V1\CustomerAppControlCenterController;
use Modules\Saas\Http\Controllers\Api\V1\CustomerAppSessionController;
use Modules\Saas\Http\Controllers\Api\V1\PublicTenantSignupController;
use Modules\Saas\Http\Controllers\Api\V1\SaasAlertController;
use Modules\Saas\Http\Controllers\Api\V1\SaasBillingWebhookController;
use Modules\Saas\Http\Controllers\Api\V1\SaasClientConfigController;
use Modules\Saas\Http\Controllers\Api\V1\SaasCommunicationController;
use Modules\Saas\Http\Controllers\Api\V1\SaasContentCatalogController;
use Modules\Saas\Http\Controllers\Api\V1\SaasControlPlaneController;
use Modules\Saas\Http\Controllers\Api\V1\SaasCustomerSuccessController;
use Modules\Saas\Http\Controllers\Api\V1\SaasDeviceCenterController;
use Modules\Saas\Http\Controllers\Api\V1\SaasInfrastructureController;
use Modules\Saas\Http\Controllers\Api\V1\SaasLaunchReadinessController;
use Modules\Saas\Http\Controllers\Api\V1\SaasOnboardingInviteController;
use Modules\Saas\Http\Controllers\Api\V1\SaasOnboardingRequestController;
use Modules\Saas\Http\Controllers\Api\V1\SaasOperationsCenterController;
use Modules\Saas\Http\Controllers\Api\V1\SaasOperationsController;
use Modules\Saas\Http\Controllers\Api\V1\SaasSecurityController;
use Modules\Saas\Http\Controllers\Api\V1\SaasServerControlController;
use Modules\Saas\Http\Controllers\Api\V1\SaasWhatsAppOrderingController;
use Modules\Saas\Http\Controllers\Api\V1\SubscriptionPlanController;
use Modules\Saas\Http\Controllers\Api\V1\TenantAppsController;
use Modules\Saas\Http\Controllers\Api\V1\TenantBillingController;
use Modules\Saas\Http\Controllers\Api\V1\TenantController;
use Modules\Saas\Http\Controllers\Api\V1\TenantSubscriptionController;
use Modules\Saas\Http\Controllers\Api\V1\TenantWorkspaceController;
use Modules\Saas\Http\Middleware\AuthenticateCustomerAppBuildWorker;
use Modules\Saas\Http\Middleware\EnsurePlatformActor;

Route::get('/saas/customer-app/associations/android', [\Modules\Saas\Http\Controllers\CustomerAppAssociationController::class, 'android'])
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class]);
Route::get('/saas/customer-app/associations/apple', [\Modules\Saas\Http\Controllers\CustomerAppAssociationController::class, 'apple'])
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class]);

Route::controller(CustomerAppBuildWorkerController::class)
    ->prefix('saas/customer-app-build-worker')
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware([AuthenticateCustomerAppBuildWorker::class, 'throttle:customer-app-build-worker'])
    ->group(function () {
        Route::post('/claim', 'claim');
        Route::post('/builds/{uuid}/heartbeat', 'heartbeat')->whereUuid('uuid');
        Route::post('/builds/{uuid}/transition', 'transition')->whereUuid('uuid');
        Route::post('/builds/{uuid}/fail', 'fail')->whereUuid('uuid');
        Route::post('/builds/{uuid}/complete', 'complete')->whereUuid('uuid');
    });

Route::post('/saas/customer-app/bootstrap', CustomerAppBootstrapController::class)
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware('throttle:customer-app-bootstrap');

Route::post('/saas/customer-app/session', CustomerAppSessionController::class)
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware('throttle:customer-app-bootstrap');

Route::post('/saas/signup', [PublicTenantSignupController::class, 'store'])
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware('throttle:3,1');

Route::get('/saas/onboarding/invites/{token}', [SaasOnboardingInviteController::class, 'show'])
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware('throttle:30,1');

Route::post('/saas/onboarding/invites/{token}/complete', [SaasOnboardingInviteController::class, 'complete'])
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware('throttle:5,1');

Route::get('/saas/client-config/{slug}', [SaasClientConfigController::class, 'show'])
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware('throttle:30,1');

Route::get('/saas/client-config/{slug}/signed', [SaasClientConfigController::class, 'signed'])
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware(['signed:relative', 'throttle:30,1'])
    ->name('api.v1.saas.client-config.signed');

Route::get('/saas/client-config/{slug}/activation', [SaasClientConfigController::class, 'activation'])
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware('throttle:30,1');

Route::post('/saas/client-config/activation-key', [SaasClientConfigController::class, 'activationKey'])
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware('throttle:5,1');

Route::post('/saas/client-config/activation/redeem', [SaasClientConfigController::class, 'redeem'])
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware('throttle:5,1');

Route::post('/saas/billing/webhooks/razorpay', [SaasBillingWebhookController::class, 'razorpay'])
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware('throttle:120,1');

Route::post('/saas/billing/webhooks/stripe', [SaasBillingWebhookController::class, 'stripe'])
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware('throttle:120,1');

Route::middleware(EnsurePlatformActor::class)->group(function () {
    Route::controller(SaasOperationsController::class)
        ->prefix('saas')
        ->group(function () {
            Route::get('/dashboard', 'dashboard')->middleware('can:admin.saas.index');
            Route::post('/onboarding/domain-check', 'domainCheck')->middleware('can:admin.saas.create');
            Route::post('/onboarding/provision', 'provision')->middleware('can:admin.saas.create');
            Route::get('/onboarding/invites', [SaasOnboardingInviteController::class, 'index'])->middleware('can:admin.saas.index');
            Route::post('/onboarding/invites', [SaasOnboardingInviteController::class, 'create'])->middleware('can:admin.saas.create');
            Route::post('/onboarding/invites/{invite}/resend', [SaasOnboardingInviteController::class, 'resend'])->middleware('can:admin.saas.create');
            Route::post('/onboarding/invites/{invite}/revoke', [SaasOnboardingInviteController::class, 'revoke'])->middleware('can:admin.saas.manage');
            Route::get('/onboarding/provision/{uuid}', 'provisioningStatus')->middleware('can:admin.saas.index');
            Route::post('/onboarding/provision/{uuid}/retry', 'retryProvisioning')->middleware('can:admin.saas.manage');
            Route::post('/onboarding/provision/{uuid}/resume', 'resumeProvisioning')->middleware('can:admin.saas.manage');
            Route::post('/onboarding/provision/{uuid}/cancel', 'cancelProvisioning')->middleware('can:admin.saas.manage');
            Route::get('/delivery-jobs/{uuid}', 'deliveryStatus')->middleware('can:admin.saas.index');
            Route::post('/tenants/{tenant}/activate', 'activate')->middleware('can:admin.saas.manage');
            Route::post('/tenants/{tenant}/suspend', 'suspend')->middleware('can:admin.saas.manage');
            Route::post('/tenants/{tenant}/backup', 'backup')->middleware('can:admin.saas.manage');
            Route::post('/tenants/{tenant}/complete-onboarding', 'completeOnboarding')->middleware('can:admin.saas.manage');
            Route::get('/tenants/{tenant}/access', 'tenantAccess')->middleware('can:admin.saas.index');
            Route::post('/tenants/{tenant}/sync-permissions', 'syncTenantPermissions')->middleware('can:admin.saas.manage');
            Route::put('/tenants/{tenant}/users/{user}/role', 'assignTenantUserRole')->middleware('can:admin.saas.manage');
            Route::post('/tenants/message', 'sendTenantMessages')->middleware('can:admin.saas.manage');
            Route::post('/tenants/bulk/assign-plan', 'bulkAssignPlan')->middleware('can:admin.saas.manage');
            Route::post('/tenants/bulk/extend-trial', 'bulkExtendTrial')->middleware('can:admin.saas.manage');
            Route::post('/tenants/bulk/create-invoices', 'bulkCreateInvoices')->middleware('can:admin.saas.manage');
            Route::post('/tenants/bulk/apply-coupon', 'bulkApplyCoupon')->middleware('can:admin.saas.manage');
            Route::post('/tenants/bulk/archive', 'bulkArchive')->middleware('can:admin.tenants.destroy');
            Route::post('/tenants/export', 'exportTenants')->middleware('can:admin.tenants.index');
            Route::post('/tenants/{tenant}/message', 'sendTenantMessage')->middleware('can:admin.saas.manage');
            Route::post('/tenants/restore/validate', 'restore')->middleware('can:admin.saas.manage');
            Route::post('/tenants/restore/execute', 'executeRestore')->middleware('can:admin.saas.manage');
            Route::post('/billing/invoices', 'createBillingInvoice')->middleware('can:admin.saas.manage');
            Route::post('/billing/invoices/{invoice}/payment-intent', 'createBillingPaymentIntent')->middleware('can:admin.saas.manage');
            Route::get('/billing/invoices/{invoice}/download', 'downloadBillingInvoice')->middleware('can:admin.saas.index');
            Route::post('/billing/invoices/{invoice}/mark-paid', 'markBillingPaid')->middleware('can:admin.saas.manage');
            Route::post('/billing/invoices/{invoice}/refund', 'refundBillingInvoice')->middleware('can:admin.saas.manage');
            Route::get('/billing/tax-report', 'billingTaxReport')->middleware('can:admin.saas.index');
            Route::post('/billing/dunning/run', 'runDunning')->middleware('can:admin.saas.manage');
            Route::post('/billing/lifecycle/run', 'runBillingLifecycle')->middleware('can:admin.saas.manage');
            Route::post('/billing/coupons', 'createCoupon')->middleware('can:admin.saas.manage');
            Route::post('/billing/coupons/apply', 'applyCoupon')->middleware('can:admin.saas.manage');
            Route::post('/billing/activation-keys', 'createActivationKey')->middleware('can:admin.saas.manage');
            Route::post('/billing/activation-keys/activate', 'activateLicense')->middleware('can:admin.saas.manage');
            Route::post('/alerts/dispatch', 'dispatchAlerts')->middleware('can:admin.saas.manage');
            Route::post('/server/apache-ssl', 'apacheSsl')->middleware('can:admin.saas.manage');
        });

    Route::get('/saas/customer-success', [SaasCustomerSuccessController::class, 'index'])
        ->middleware('can:admin.saas.index');
    Route::post('/saas/customer-success/records', [SaasCustomerSuccessController::class, 'store'])
        ->middleware('can:admin.saas.manage');
    Route::put('/saas/customer-success/records/{record}', [SaasCustomerSuccessController::class, 'update'])
        ->middleware('can:admin.saas.manage');

    Route::get('/saas/operations-center', [SaasOperationsCenterController::class, 'index'])
        ->middleware('can:admin.saas.index');

    Route::get('/saas/plan-upgrade-requests', [TenantBillingController::class, 'adminIndex'])->middleware('can:admin.saas.index');
    Route::post('/saas/plan-upgrade-requests/{uuid}/decision', [TenantBillingController::class, 'decide'])->whereUuid('uuid')->middleware('can:admin.saas.manage');

    Route::get('/saas/security-center', [SaasSecurityController::class, 'index'])
        ->middleware('can:admin.saas.index');
    Route::post('/saas/security-center/tokens/{token}/revoke', [SaasSecurityController::class, 'revokeToken'])
        ->middleware('can:admin.saas.manage');

    Route::get('/saas/device-center', [SaasDeviceCenterController::class, 'index'])
        ->middleware('can:admin.saas.index');
    Route::post('/saas/device-center/assign', [SaasDeviceCenterController::class, 'assign'])->middleware('can:admin.saas.manage');
    Route::put('/saas/device-center/terminals/{device}/name', [SaasDeviceCenterController::class, 'renameTerminal'])->whereNumber('device')->middleware('can:admin.saas.manage');

    Route::get('/saas/communication-center', [SaasCommunicationController::class, 'index'])->middleware('can:admin.saas.index');
    Route::post('/saas/communication-center/company-mail', [SaasCommunicationController::class, 'sendCompanyMail'])->middleware(['can:admin.saas.manage', 'throttle:20,1']);
    Route::get('/saas/communication-center/company-mail/{reference}', [SaasCommunicationController::class, 'companyMailThread'])->whereUuid('reference')->middleware('can:admin.saas.index');
    Route::patch('/saas/communication-center/company-mail/{reference}/read-state', [SaasCommunicationController::class, 'updateCompanyMailReadState'])->whereUuid('reference')->middleware('can:admin.saas.index');
    Route::post('/saas/communication-center/{log}/retry', [SaasCommunicationController::class, 'retry'])->middleware('can:admin.saas.manage');
    Route::post('/saas/communication-center/templates', [SaasCommunicationController::class, 'storeTemplate'])->middleware('can:admin.saas.manage');
    Route::post('/saas/communication-center/campaigns', [SaasCommunicationController::class, 'storeCampaign'])->middleware('can:admin.saas.manage');
    Route::get('/saas/whatsapp-ordering', [SaasWhatsAppOrderingController::class, 'index'])->middleware('can:admin.saas.index');
    Route::post('/saas/whatsapp-ordering/profiles', [SaasWhatsAppOrderingController::class, 'storeProfile'])->middleware(['can:admin.saas.manage', 'throttle:10,1']);
    Route::put('/saas/whatsapp-ordering/profiles/{profile}', [SaasWhatsAppOrderingController::class, 'updateProfile'])->middleware(['can:admin.saas.manage', 'throttle:20,1']);
    Route::delete('/saas/whatsapp-ordering/profiles/{profile}', [SaasWhatsAppOrderingController::class, 'destroyProfile'])->middleware(['can:admin.saas.manage', 'throttle:10,1']);
    Route::post('/saas/whatsapp-ordering/profiles/{profile}/diagnostics', [SaasWhatsAppOrderingController::class, 'diagnostics'])->middleware(['can:admin.saas.manage', 'throttle:10,1']);
    Route::post('/saas/whatsapp-ordering/assignments', [SaasWhatsAppOrderingController::class, 'assign'])->middleware(['can:admin.saas.manage', 'throttle:20,1']);
    Route::put('/saas/whatsapp-ordering/assignments/{assignment}', [SaasWhatsAppOrderingController::class, 'updateAssignment'])->middleware(['can:admin.saas.manage', 'throttle:20,1']);
    Route::post('/saas/whatsapp-ordering/assignments/{assignment}/transfer', [SaasWhatsAppOrderingController::class, 'transferAssignment'])->middleware(['can:admin.saas.manage', 'throttle:10,1']);
    Route::delete('/saas/whatsapp-ordering/assignments/{assignment}', [SaasWhatsAppOrderingController::class, 'destroyAssignment'])->middleware(['can:admin.saas.manage', 'throttle:10,1']);
    Route::post('/saas/security-center/users/{user}/revoke-sessions', [SaasSecurityController::class, 'revokeUserSessions'])
        ->middleware('can:admin.saas.manage');

    Route::controller(SaasAlertController::class)
        ->prefix('saas/alerts')
        ->middleware('can:admin.saas.manage')
        ->group(function () {
            Route::post('/{fingerprint}/assign-to-me', 'assignToMe');
            Route::post('/{fingerprint}/acknowledge', 'acknowledge');
            Route::post('/{fingerprint}/resolve', 'resolve');
        });

    Route::controller(SaasServerControlController::class)
        ->prefix('saas/server')
        ->middleware('can:admin.saas.manage')
        ->group(function () {
            Route::get('/health', 'health');
            Route::post('/webserver-ssl', 'webserverSsl');
        });

    Route::controller(SaasControlPlaneController::class)
        ->prefix('saas/control-plane')
        ->middleware('can:admin.saas.manage')
        ->group(function () {
            Route::get('/', 'overview');
            Route::get('/tenants/{tenant}/features', 'features');
            Route::post('/tenants/{tenant}/features', 'updateFeature');
            Route::get('/tenants/{tenant}/usage', 'usage');
            Route::get('/tenants/{tenant}/activity', 'activity');
        });

    /*
     * Purchase → payment → approval → provisioning pipeline.
     *
     * Read surfaces need `admin.saas.index`; anything that commits the platform to
     * creating or refusing a tenant needs `admin.saas.manage`.
     */
    Route::controller(SaasOnboardingRequestController::class)
        ->prefix('saas/onboarding-requests')
        ->group(function () {
            Route::get('/', 'index')->middleware('can:admin.saas.index');
            Route::get('/lifecycle', 'lifecycle')->middleware('can:admin.saas.index');
            Route::get('/{uuid}', 'show')->middleware('can:admin.saas.index');
            Route::post('/', 'store')->middleware('can:admin.saas.create');
            Route::post('/{uuid}/approve', 'approve')->middleware('can:admin.saas.manage');
            Route::post('/{uuid}/reject', 'reject')->middleware('can:admin.saas.manage');
            Route::post('/{uuid}/retry', 'retry')->middleware('can:admin.saas.manage');
            Route::post('/{uuid}/mark-paid', 'markPaid')->middleware('can:admin.saas.manage');
            Route::post('/{uuid}/assign', 'assign')->middleware('can:admin.saas.manage');
            Route::post('/{uuid}/note', 'note')->middleware('can:admin.saas.index');
            Route::post('/bulk', 'bulk')->middleware('can:admin.saas.manage');
        });

    /*
     * Launch certification. Read-only; nothing here mutates state.
     */
    Route::get('/saas/launch-readiness', [SaasLaunchReadinessController::class, 'index'])
        ->middleware('can:admin.saas.index');

    /*
     * Infrastructure dashboard. Read-only observability of platform health and the
     * tenant infrastructure registry. No write actions in this phase.
     */
    Route::get('/saas/infrastructure', [SaasInfrastructureController::class, 'index'])
        ->middleware('can:admin.saas.index');
    Route::post('/saas/infrastructure/horizon/{command}', [SaasInfrastructureController::class, 'horizon'])
        ->whereIn('command', ['pause', 'continue', 'terminate', 'snapshot'])
        ->middleware('can:admin.saas.manage');
    // Cache management. Lets an administrator apply configuration changes without
    // shell access; audited like every other operational command.
    Route::post('/saas/infrastructure/cache/{scope}', [SaasInfrastructureController::class, 'cache'])
        ->whereIn('scope', ['application', 'config', 'route', 'view', 'permission', 'all'])
        ->middleware('can:admin.saas.manage');
    Route::post('/saas/infrastructure/tenants/{tenant}/ssl', [SaasInfrastructureController::class, 'tenantSsl'])
        ->whereNumber('tenant')
        ->middleware('can:admin.saas.manage');
    Route::post('/saas/infrastructure/releases', [SaasInfrastructureController::class, 'release'])
        ->middleware('can:admin.saas.manage');
    Route::get('/saas/infrastructure/releases/{uuid}', [SaasInfrastructureController::class, 'releaseStatus'])
        ->whereUuid('uuid')
        ->middleware('can:admin.saas.index');
    Route::post('/saas/infrastructure/terminal', [SaasInfrastructureController::class, 'terminal'])
        ->middleware(['can:admin.saas.manage', 'throttle:10,1']);

    /*
     * SaaS content catalogue. Published items are consumed by every tenant, so
     * write access is control-plane only.
     */
    Route::controller(SaasContentCatalogController::class)
        ->prefix('saas/content')
        ->group(function () {
            Route::get('/', 'index')->middleware('can:admin.saas.index');
            Route::get('/preview', 'preview')->middleware('can:admin.saas.index');
            Route::post('/upload', 'upload')->middleware('can:admin.saas.manage');
            Route::post('/', 'store')->middleware('can:admin.saas.manage');
            Route::put('/{id}', 'update')->middleware('can:admin.saas.manage');
            Route::patch('/{id}/publish', 'publish')->middleware('can:admin.saas.manage');
            Route::delete('/{id}', 'destroy')->middleware('can:admin.saas.manage');
        });

    /*
     * Customer app control center. SaaS admins manage content for a selected
     * tenant; restaurant admins only manage their own tenant. Content publish
     * changes are runtime-safe and do not require rebuilding the app binary.
     */
    Route::controller(CustomerAppControlCenterController::class)
        ->prefix('saas/customer-apps/tenants/{tenant}')
        ->middleware('can:admin.saas.index')
        ->group(function () {
            Route::get('/', 'saasOverview');
            Route::get('/content', 'saasContent');
            Route::get('/settings', 'saasSettings');
            Route::get('/preview', 'saasPreview');
            Route::post('/test-email', 'saasTestEmail')->middleware(['can:admin.saas.manage', 'throttle:5,1']);
            Route::post('/content', 'saasStoreContent')->middleware(['can:admin.saas.manage', 'throttle:customer-app-content']);
            Route::put('/content/{item}', 'saasUpdateContent')->whereNumber('item')->middleware(['can:admin.saas.manage', 'throttle:customer-app-content']);
            Route::post('/content/{item}/publish', 'saasPublishContent')->whereNumber('item')->middleware(['can:admin.saas.manage', 'throttle:customer-app-content']);
            Route::post('/content/{item}/unpublish', 'saasUnpublishContent')->whereNumber('item')->middleware(['can:admin.saas.manage', 'throttle:customer-app-content']);
            Route::delete('/content/{item}', 'saasDestroyContent')->whereNumber('item')->middleware(['can:admin.saas.manage', 'throttle:customer-app-content']);
            Route::post('/publish', 'saasPublishAll')->middleware(['can:admin.saas.manage', 'throttle:customer-app-content']);
            Route::put('/settings', 'saasUpdateSettings')->middleware(['can:admin.saas.manage', 'throttle:customer-app-content']);
            Route::post('/media', 'saasUploadMedia')->middleware(['can:admin.saas.manage', 'throttle:customer-app-content']);
            Route::get('/builds', [CustomerAppBuildController::class, 'saasIndex']);
            Route::post('/builds', [CustomerAppBuildController::class, 'saasStore'])
                ->middleware(['can:admin.saas.manage', 'throttle:customer-app-builds']);
            Route::get('/builds/{uuid}/artifact', [CustomerAppBuildController::class, 'saasDownload'])
                ->whereUuid('uuid');
        });
});

/*
 * Tenant self-service workspace. No control-plane permission: a restaurant
 * owner must be able to onboard, download and activate without platform access.
 * Every endpoint scopes to the caller's own tenant and accepts no tenant id.
 */
Route::controller(TenantWorkspaceController::class)
    ->prefix('tenants/current')
    ->group(function () {
        Route::get('/overview', 'overview');
        Route::get('/resources', 'resources');
        Route::post('/activation/verify', 'verifyActivationKey')->middleware('throttle:10,1');
    });

Route::controller(TenantAppsController::class)->prefix('tenants/current/apps')->group(function () {
    Route::get('/', 'index');
    Route::post('/waiter/activation', 'createWaiterActivation')->middleware(['can:admin.settings.edit', 'throttle:10,1']);
    Route::get('/customer-app/version-options', 'versionOptions')->middleware('can:admin.settings.edit');
    Route::post('/waiter/devices/{device}/revoke', 'revoke')->whereNumber('device')->middleware('can:admin.settings.edit');
});

Route::controller(TenantAppsController::class)
    ->prefix('saas/tenants/{tenant}/apps')
    ->middleware(EnsurePlatformActor::class)
    ->group(function () {
        Route::get('/waiter/config', 'saasWaiterConfig')->middleware('can:admin.saas.index');
        Route::post('/waiter/activation', 'createSaasWaiterActivation')
            ->middleware(['can:admin.saas.manage', 'throttle:10,1']);
    });

Route::controller(TenantBillingController::class)->prefix('tenants/current/billing')->group(function () {
    Route::get('/', 'index')->middleware('can:admin.settings.edit');
    Route::get('/invoices/{invoice}/download', 'invoice')->whereNumber('invoice')->middleware('can:admin.settings.edit');
    Route::post('/upgrade-requests', 'store')->middleware('can:admin.settings.edit');
    Route::post('/upgrade-requests/{uuid}/cancel', 'cancel')->whereUuid('uuid')->middleware('can:admin.settings.edit');
});

Route::controller(CustomerAppControlCenterController::class)
    ->prefix('tenants/current/customer-app')
    ->middleware(['can:admin.settings.edit', 'tenant.feature:customer_app'])
    ->group(function () {
        Route::get('/', 'overview');
        Route::get('/content', 'content');
        Route::get('/settings', 'settings');
        Route::get('/preview', 'preview');
        Route::post('/test-email', 'testEmail')->middleware('throttle:5,1');
        Route::post('/content', 'storeContent')->middleware('throttle:customer-app-content');
        Route::put('/content/{item}', 'updateContent')->whereNumber('item')->middleware('throttle:customer-app-content');
        Route::post('/content/{item}/publish', 'publishContent')->whereNumber('item')->middleware('throttle:customer-app-content');
        Route::post('/content/{item}/unpublish', 'unpublishContent')->whereNumber('item')->middleware('throttle:customer-app-content');
        Route::delete('/content/{item}', 'destroyContent')->whereNumber('item')->middleware('throttle:customer-app-content');
        Route::post('/publish', 'publishAll')->middleware('throttle:customer-app-content');
        Route::put('/settings', 'updateSettings')->middleware('throttle:customer-app-content');
        Route::post('/media', 'uploadMedia')->middleware('throttle:customer-app-content');
    });

Route::controller(CustomerAppBuildController::class)
    ->prefix('tenants/current/customer-app/builds')
    ->middleware(['can:admin.settings.edit', 'tenant.feature:customer_app', 'throttle:customer-app-builds'])
    ->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::get('/{uuid}', 'show')->whereUuid('uuid');
        Route::post('/{uuid}/cancel', 'cancel')->whereUuid('uuid');
        Route::post('/{uuid}/retry', 'retry')->whereUuid('uuid');
        Route::get('/{uuid}/artifact', 'download')->whereUuid('uuid');
    });

Route::controller(TenantController::class)
    ->prefix('tenants')
    ->group(function () {
        Route::get('/current/checklist', 'checklist');
        Route::put('/current/checklist', 'updateChecklist');
        Route::get('/registry-summary', 'registrySummary')->middleware([EnsurePlatformActor::class, 'can:admin.tenants.index']);
        Route::get('/', 'index')->middleware([EnsurePlatformActor::class, 'can:admin.tenants.index']);
        Route::get('/{id}', 'show')->middleware([EnsurePlatformActor::class, 'permission:admin.tenants.show|admin.tenants.edit']);
        Route::post('/{id}/login', 'login')->middleware([EnsurePlatformActor::class, 'can:admin.tenants.show']);
        Route::post('/', 'store')->middleware([EnsurePlatformActor::class, 'can:admin.tenants.create']);
        Route::put('/{id}', 'update')->middleware([EnsurePlatformActor::class, 'can:admin.tenants.edit']);
        Route::delete('/{ids}', 'destroy')->middleware([EnsurePlatformActor::class, 'can:admin.tenants.destroy']);
    });


Route::prefix('delivery-provider-logs')
    ->middleware([EnsurePlatformActor::class, 'can:admin.tenants.edit'])
    ->group(function () {
        Route::get('/', [DeliveryProviderApiLogController::class, 'index']);
        Route::delete('/', [DeliveryProviderApiLogController::class, 'clear'])->middleware('throttle:5,1');
    });

Route::prefix('delivery-wallet')->middleware([EnsurePlatformActor::class, 'can:admin.tenants.edit'])->group(function () {
    Route::get('/funding-methods', [DeliveryWalletFundingController::class, 'platformMethods']);
    Route::post('/funding-methods', [DeliveryWalletFundingController::class, 'storeMethod'])->middleware('throttle:20,1');
    Route::put('/funding-methods/{uuid}', [DeliveryWalletFundingController::class, 'updateMethod'])->whereUuid('uuid')->middleware('throttle:20,1');
    Route::get('/top-up-requests', [DeliveryWalletFundingController::class, 'platformRequests']);
    Route::post('/top-up-requests/{uuid}/review', [DeliveryWalletFundingController::class, 'review'])->whereUuid('uuid')->middleware('throttle:20,1');
});

Route::get('tenants/{tenantId}/delivery-wallet', [DeliveryWalletController::class, 'showForPlatform'])
    ->whereNumber('tenantId')
    ->middleware([EnsurePlatformActor::class, 'can:admin.tenants.edit']);
Route::prefix('tenants/{tenantId}/services')->whereNumber('tenantId')
    ->middleware([EnsurePlatformActor::class, 'can:admin.tenants.edit'])->group(function () {
        Route::get('/', [\Modules\Saas\Http\Controllers\Api\V1\TenantServiceController::class, 'index']);
        Route::post('/', [\Modules\Saas\Http\Controllers\Api\V1\TenantServiceController::class, 'store'])->middleware('throttle:10,1');
        Route::post('/{uuid}/cancel', [\Modules\Saas\Http\Controllers\Api\V1\TenantServiceController::class, 'cancel'])->whereUuid('uuid')->middleware('throttle:10,1');
    });
Route::post('tenants/{tenantId}/delivery-wallet/adjust', [DeliveryWalletController::class, 'adjust'])
    ->whereNumber('tenantId')
    ->middleware([EnsurePlatformActor::class, 'can:admin.tenants.edit', 'throttle:10,1']);
Route::post('tenants/{tenantId}/delivery-wallet/refund', [DeliveryWalletController::class, 'refund'])
    ->whereNumber('tenantId')
    ->middleware([EnsurePlatformActor::class, 'can:admin.tenants.edit', 'throttle:10,1']);

Route::controller(TenantSubscriptionController::class)
    ->prefix('tenant-subscriptions')
    ->middleware(EnsurePlatformActor::class)
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.tenant_subscriptions.index');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.tenant_subscriptions.edit|admin.tenant_subscriptions.create');
        Route::get('/{id}', 'show')->middleware('permission:admin.tenant_subscriptions.show|admin.tenant_subscriptions.edit');
        Route::post('/', 'store')->middleware('can:admin.tenant_subscriptions.create');
        Route::put('/{id}', 'update')->middleware('can:admin.tenant_subscriptions.edit');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.tenant_subscriptions.destroy');
    });

Route::controller(SubscriptionPlanController::class)
    ->prefix('subscription-plans')
    ->middleware(EnsurePlatformActor::class)
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.subscription_plans.index');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.subscription_plans.edit|admin.subscription_plans.create');
        Route::get('/{id}', 'show')->middleware('permission:admin.subscription_plans.show|admin.subscription_plans.edit');
        Route::post('/', 'store')->middleware('can:admin.subscription_plans.create');
        Route::put('/{id}', 'update')->middleware('can:admin.subscription_plans.edit');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.subscription_plans.destroy');
    });

Route::prefix('tenants/{tenantId}/whatsapp-settings')->whereNumber('tenantId')
    ->middleware([EnsurePlatformActor::class, 'can:admin.tenants.edit'])->group(function () {
        Route::get('/', [\Modules\Saas\Http\Controllers\Api\V1\TenantWhatsAppSettingsController::class, 'show']);
        Route::put('/', [\Modules\Saas\Http\Controllers\Api\V1\TenantWhatsAppSettingsController::class, 'update'])->middleware('throttle:10,1');
    });
