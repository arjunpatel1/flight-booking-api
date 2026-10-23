<?php

use Illuminate\Support\Facades\Route;
use Modules\Flight\Http\Controllers\Api\V1\FlightAircraftController;
use Modules\Flight\Http\Controllers\Api\V1\FlightAirlineController;
use Modules\Flight\Http\Controllers\Api\V1\FlightAirportController;
use Modules\Flight\Http\Controllers\Api\V1\FlightFareController;
use Modules\Flight\Http\Controllers\Api\V1\FlightFareFamilyController;
use Modules\Flight\Http\Controllers\Api\V1\FlightCabinClassController;
use Modules\Flight\Http\Controllers\Api\V1\FlightInventoryController;
use Modules\Flight\Http\Controllers\Api\V1\FlightInventoryAllocationController;
use Modules\Flight\Http\Controllers\Api\V1\FlightInventoryLedgerController;
use Modules\Flight\Http\Controllers\Api\V1\FlightMetaController;
use Modules\Flight\Http\Controllers\Api\V1\FlightRouteController;
use Modules\Flight\Http\Controllers\Api\V1\FlightScheduleController;
use Modules\Flight\Http\Controllers\Api\V1\FlightSupplierController;
use Modules\Flight\Http\Controllers\Api\V1\FlightCommissionRuleController;
use Modules\Flight\Http\Controllers\Api\V1\FlightB2BController;
use Modules\Flight\Http\Controllers\Api\V1\FlightWalletController;
use Modules\Flight\Http\Controllers\Api\V1\FlightSubscriptionController;
use Modules\Flight\Http\Controllers\Api\V1\FlightSearchController;
use Modules\Flight\Http\Controllers\Api\V1\FlightBookingSettingsController;
use Modules\Flight\Http\Controllers\Api\V1\FlightHoldController;
use Modules\Flight\Http\Controllers\Api\V1\FlightBookingController;
use Modules\Flight\Http\Controllers\Api\V1\FlightAncillaryController;
use Modules\Flight\Http\Controllers\Api\V1\FlightPaymentController;
use Modules\Flight\Http\Controllers\Api\V1\FlightOfferController;
use Modules\Flight\Http\Controllers\Api\V1\FlightTicketController;
use Modules\Flight\Http\Controllers\Api\V1\FlightServicingController;
use Modules\Flight\Http\Controllers\Api\V1\FlightSettlementController;
use Modules\Flight\Http\Controllers\Api\V1\FlightNotificationController;
use Modules\Flight\Http\Controllers\Api\V1\FlightWebhookController;
use Modules\Flight\Http\Controllers\Api\V1\FlightCustomerAuthController;
use Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant;

Route::prefix('flight')->group(function () {
    // Public reference metadata, directory lookups, customer flight search
    Route::withoutMiddleware(['auth', EnsureAuthenticatedTenant::class])->group(function () {
        Route::post('/customer-auth/register', [FlightCustomerAuthController::class, 'register'])->middleware('throttle:login');
        Route::post('/customer-auth/login', [FlightCustomerAuthController::class, 'login'])->middleware('throttle:login');

        Route::get('/booking-options/resolve', [FlightFareController::class, 'resolveBookingOption']);

        Route::get('/meta', [FlightMetaController::class, 'index']);
        Route::get('/cabin-classes', [FlightCabinClassController::class, 'index']);
        Route::get('/airports', [FlightAirportController::class, 'index']);
        Route::get('/airports/{identifier}', [FlightAirportController::class, 'show']);
        Route::get('/airlines', [FlightAirlineController::class, 'index']);
        Route::get('/airlines/{identifier}', [FlightAirlineController::class, 'show']);
        Route::get('/aircraft', [FlightAircraftController::class, 'index']);
        Route::get('/aircraft/{identifier}', [FlightAircraftController::class, 'show']);
        Route::get('/fare-families', [FlightFareFamilyController::class, 'index']);
        Route::get('/fare-families/{identifier}', [FlightFareFamilyController::class, 'show']);
        Route::get('/configuration/{key}', [FlightBookingSettingsController::class, 'showConfiguration']);

        // Phase 11: Customer Flight Search & Aggregation
        Route::match(['get', 'post'], '/search', [FlightSearchController::class, 'search']);
        Route::post('/search/multi-city', [FlightSearchController::class, 'searchMultiCity']);
        Route::get('/search/filters', [FlightSearchController::class, 'filters']);
        Route::get('/search/workspace', [FlightSearchController::class, 'workspace']);

        // Phase 12: Public/Guest Price Check, Inventory Hold & Quotes
        Route::post('/price-check', [FlightHoldController::class, 'priceCheck']);
        Route::post('/inventory/check', [FlightHoldController::class, 'inventoryCheck']);
        Route::post('/holds', [FlightHoldController::class, 'createHold']);
        Route::get('/holds/{identifier}', [FlightHoldController::class, 'getHold']);
        Route::post('/holds/{identifier}/release', [FlightHoldController::class, 'releaseHold']);
        Route::post('/quotes', [FlightHoldController::class, 'createQuote']);
        Route::get('/quotes/{identifier}', [FlightHoldController::class, 'getQuote']);
        Route::get('/offers', [FlightOfferController::class, 'index']);
        Route::post('/offers/validate', [FlightOfferController::class, 'validateCode']);

        // Phase 13: Flight Booking Lifecycle (Public / Guest / Customer)
        Route::post('/bookings', [FlightBookingController::class, 'createBooking']);
        Route::get('/bookings/{identifier}', [FlightBookingController::class, 'getBooking']);
        Route::get('/bookings/{identifier}/status', [FlightBookingController::class, 'bookingStatus']);
        Route::post('/bookings/{identifier}/confirm', [FlightBookingController::class, 'confirmBooking']);
        Route::post('/bookings/{identifier}/cancel', [FlightBookingController::class, 'cancelBooking']);
        Route::put('/bookings/{identifier}/passengers/{passengerId}', [FlightBookingController::class, 'updatePassenger']);

        // Phase 14: Ancillaries, Seat Map, Seat Locking & Passenger Selection
        Route::get('/inventories/{inventoryId}/seat-map', [FlightAncillaryController::class, 'getSeatMap']);
        Route::post('/inventories/{inventoryId}/seats/lock', [FlightAncillaryController::class, 'lockSeat']);
        Route::post('/inventories/{inventoryId}/seats/release', [FlightAncillaryController::class, 'releaseSeatLock']);
        Route::get('/inventories/{inventoryId}/ancillaries', [FlightAncillaryController::class, 'getAncillaries']);
        Route::post('/bookings/{identifier}/ancillaries', [FlightAncillaryController::class, 'addAncillaryToBooking']);
        Route::delete('/bookings/{identifier}/ancillaries/{ancillaryId}', [FlightAncillaryController::class, 'removeAncillaryFromBooking']);

        // Phase 15: Flight Payment Gateway & Verification
        Route::post('/bookings/{identifier}/payment', [FlightPaymentController::class, 'initiate']);
        Route::post('/payments/{reference}/verify', [FlightPaymentController::class, 'verify']);
        Route::get('/payments/{reference}', [FlightPaymentController::class, 'show']);
        Route::get('/bookings/{identifier}/payment-options', [FlightPaymentController::class, 'paymentOptions']);
        Route::post('/payments/webhook/{gateway}', [FlightPaymentController::class, 'webhook']);

        // Phase 21: Public Webhook Ingestion & Customer Booking Notifications
        Route::post('/webhooks/{provider}', [FlightWebhookController::class, 'handleWebhook']);
        Route::get('/bookings/{identifier}/notifications', [FlightNotificationController::class, 'bookingNotifications']);

        // Phase 16: PNR, E-Ticket Issuance & Download
        Route::post('/bookings/{identifier}/issue-ticket', [FlightTicketController::class, 'issueTicket']);
        Route::get('/bookings/{identifier}/ticket', [FlightTicketController::class, 'showTicket']);
        Route::get('/bookings/{identifier}/eticket/download', [FlightTicketController::class, 'downloadEticket']);
        Route::get('/bookings/{identifier}/eticket/preview', [FlightTicketController::class, 'previewEticket']);

        // Phase 17: Cancellation, Refunds & Rescheduling (Servicing)
        Route::get('/bookings/{identifier}/servicing', [FlightServicingController::class, 'show']);
        Route::get('/bookings/{identifier}/cancellation-quote', [FlightServicingController::class, 'quoteCancellation']);
        Route::post('/bookings/{identifier}/cancel-servicing', [FlightServicingController::class, 'cancel']);
        Route::get('/bookings/{identifier}/reschedule/search', [FlightServicingController::class, 'searchReplacement']);
        Route::post('/bookings/{identifier}/reschedule', [FlightServicingController::class, 'reschedule']);
    });

    Route::post('/customer-auth/logout', [FlightCustomerAuthController::class, 'logout'])
        ->middleware('auth:sanctum')
        ->withoutMiddleware(['auth', EnsureAuthenticatedTenant::class]);
    Route::get('/customer-auth/bookings', [FlightCustomerAuthController::class, 'bookings'])
        ->middleware('auth:sanctum')
        ->withoutMiddleware(['auth', EnsureAuthenticatedTenant::class]);
    Route::get('/customer-auth/bookings/{identifier}/ticket', [FlightCustomerAuthController::class, 'downloadBookingTicket'])
        ->middleware('auth:sanctum')
        ->withoutMiddleware(['auth', EnsureAuthenticatedTenant::class]);
    Route::get('/customer-auth/bookings/{identifier}/ticket/preview', [FlightCustomerAuthController::class, 'previewBookingTicket'])
        ->middleware('auth:sanctum')
        ->withoutMiddleware(['auth', EnsureAuthenticatedTenant::class]);

    // Reference Management (Super Admin & Flight Admin)
    Route::get('/settings/booking-window', [FlightBookingSettingsController::class, 'show']);
    Route::put('/settings/booking-window', [FlightBookingSettingsController::class, 'update']);
    Route::get('/settings/ticket-advisory', [FlightBookingSettingsController::class, 'showTicketAdvisory']);
    Route::put('/settings/ticket-advisory', [FlightBookingSettingsController::class, 'updateTicketAdvisory']);
    Route::put('/configuration/{key}', [FlightBookingSettingsController::class, 'updateConfiguration']);

    Route::post('/airports', [FlightAirportController::class, 'store']);
    Route::put('/airports/{identifier}', [FlightAirportController::class, 'update']);
    Route::delete('/airports/{identifier}', [FlightAirportController::class, 'destroy']);

    Route::post('/airlines', [FlightAirlineController::class, 'store']);
    Route::put('/airlines/{identifier}', [FlightAirlineController::class, 'update']);
    Route::delete('/airlines/{identifier}', [FlightAirlineController::class, 'destroy']);

    Route::post('/aircraft', [FlightAircraftController::class, 'store']);
    Route::put('/aircraft/{identifier}', [FlightAircraftController::class, 'update']);
    Route::delete('/aircraft/{identifier}', [FlightAircraftController::class, 'destroy']);

    // Fare Families Management
    Route::get('/fare-families/manage/list', [FlightFareFamilyController::class, 'manageIndex']);
    Route::post('/cabin-classes', [FlightCabinClassController::class, 'store']);
    Route::put('/cabin-classes/{id}', [FlightCabinClassController::class, 'update']);
    Route::delete('/cabin-classes/{id}', [FlightCabinClassController::class, 'destroy']);
    Route::post('/fare-families', [FlightFareFamilyController::class, 'store']);
    Route::put('/fare-families/{identifier}', [FlightFareFamilyController::class, 'update']);
    Route::delete('/fare-families/{identifier}', [FlightFareFamilyController::class, 'destroy']);

    // Tenant Flight Routes CRUD
    Route::get('/routes', [FlightRouteController::class, 'index']);
    Route::post('/routes', [FlightRouteController::class, 'store']);
    Route::get('/routes/{id}', [FlightRouteController::class, 'show']);
    Route::put('/routes/{id}', [FlightRouteController::class, 'update']);
    Route::delete('/routes/{id}', [FlightRouteController::class, 'destroy']);

    // Tenant Flight Schedules CRUD
    Route::get('/schedules', [FlightScheduleController::class, 'index']);
    Route::post('/schedules', [FlightScheduleController::class, 'store']);
    Route::get('/schedules/{id}', [FlightScheduleController::class, 'show']);
    Route::put('/schedules/{id}', [FlightScheduleController::class, 'update']);
    Route::delete('/schedules/{id}', [FlightScheduleController::class, 'destroy']);

    // Tenant Flight Inventory CRUD & Availability
    Route::get('/inventories/availability', [FlightInventoryController::class, 'availability']);
    Route::get('/inventories', [FlightInventoryController::class, 'index']);
    Route::post('/inventories', [FlightInventoryController::class, 'store']);
    Route::get('/inventories/{identifier}', [FlightInventoryController::class, 'show']);
    Route::put('/inventories/{identifier}', [FlightInventoryController::class, 'update']);
    Route::delete('/inventories/{identifier}', [FlightInventoryController::class, 'destroy']);

    // Tenant Flight Fares, Dynamic Pricing Pipeline, Availability, Best Fares Engine & Rules
    Route::post('/fares/calculate-price', [FlightFareController::class, 'calculatePrice']);
    Route::get('/fares/availability', [FlightFareController::class, 'availability']);
    Route::get('/fares/best-fares', [FlightFareController::class, 'bestFares']);
    Route::get('/fares', [FlightFareController::class, 'index']);
    Route::post('/fares', [FlightFareController::class, 'store']);
    Route::get('/fares/{id}', [FlightFareController::class, 'show']);
    Route::put('/fares/{id}', [FlightFareController::class, 'update']);
    Route::delete('/fares/{id}', [FlightFareController::class, 'destroy']);
    Route::get('/fares/{id}/rules', [FlightFareController::class, 'rules']);
    Route::put('/fares/{id}/rules', [FlightFareController::class, 'rules']);

    // â”€â”€ Phase 4: Inventory Allocation â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    Route::get('/inventory-allocations', [FlightInventoryAllocationController::class, 'index']);
    Route::post('/inventory-allocations', [FlightInventoryAllocationController::class, 'store']);
    Route::get('/inventory-allocations/{id}', [FlightInventoryAllocationController::class, 'show']);
    Route::post('/inventory-allocations/{id}/approve', [FlightInventoryAllocationController::class, 'approve']);
    Route::post('/inventory-allocations/{id}/reject', [FlightInventoryAllocationController::class, 'reject']);
    Route::post('/inventory-allocations/{id}/cancel', [FlightInventoryAllocationController::class, 'cancel']);
    Route::post('/inventory-allocations/{id}/reverse', [FlightInventoryAllocationController::class, 'reverse']);


    // â”€â”€ Phase 4: Inventory Ledger â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    Route::get('/inventory-ledger/balances', [FlightInventoryLedgerController::class, 'balances']);
    Route::get('/inventory-ledger/tenant-view', [FlightInventoryLedgerController::class, 'tenantView']);
    Route::get('/inventory-ledger/{inventoryId}/transactions', [FlightInventoryLedgerController::class, 'transactions']);
    Route::get('/inventory-ledger/{inventoryId}/summary', [FlightInventoryLedgerController::class, 'summary']);

    // â”€â”€ Phase 5: Supplier Integration Abstraction â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    Route::get('/suppliers', [FlightSupplierController::class, 'index']);
    Route::post('/suppliers', [FlightSupplierController::class, 'store']);
    Route::get('/suppliers/{id}', [FlightSupplierController::class, 'show']);
    Route::put('/suppliers/{id}', [FlightSupplierController::class, 'update']);
    Route::post('/suppliers/{id}/credentials', [FlightSupplierController::class, 'updateCredentials']);
    Route::post('/suppliers/{id}/health-check', [FlightSupplierController::class, 'healthCheck']);
    Route::get('/suppliers/{id}/mappings', [FlightSupplierController::class, 'mappings']);
    Route::post('/suppliers/{id}/mappings', [FlightSupplierController::class, 'storeMapping']);

    // â”€â”€ Phase 7: Commission Engine + Commission Rules â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    Route::get('/commission-rules', [FlightCommissionRuleController::class, 'index']);
    Route::post('/commission-rules', [FlightCommissionRuleController::class, 'store']);
    Route::get('/commission-rules/{id}', [FlightCommissionRuleController::class, 'show']);
    Route::put('/commission-rules/{id}', [FlightCommissionRuleController::class, 'update']);
    Route::delete('/commission-rules/{id}', [FlightCommissionRuleController::class, 'destroy']);
    Route::post('/commission/calculate', [FlightCommissionRuleController::class, 'calculate']);
    Route::get('/commission/logs', [FlightCommissionRuleController::class, 'logs']);

    // â”€â”€ Phase 8: B2B Distributor + Agent Network â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    Route::get('/b2b/hierarchy', [FlightB2BController::class, 'hierarchy']);

    // Tenant Admin: Distributors
    Route::get('/b2b/distributors', [FlightB2BController::class, 'indexDistributors']);
    Route::post('/b2b/distributors', [FlightB2BController::class, 'storeDistributor']);
    Route::get('/b2b/distributors/{id}', [FlightB2BController::class, 'showDistributor']);
    Route::put('/b2b/distributors/{id}', [FlightB2BController::class, 'updateDistributor']);
    Route::post('/b2b/distributors/{id}/suspend', [FlightB2BController::class, 'suspendDistributor']);
    Route::post('/b2b/distributors/{id}/activate', [FlightB2BController::class, 'activateDistributor']);
    Route::post('/b2b/distributors/{id}/credit-limit', [FlightB2BController::class, 'adjustCreditLimit']);

    // Distributor: Agents, Wallet & Inventory
    Route::get('/b2b/distributor/{distributorId}/agents', [FlightB2BController::class, 'indexAgents']);
    Route::post('/b2b/distributor/{distributorId}/agents', [FlightB2BController::class, 'storeAgent']);
    Route::get('/b2b/distributor/{id}/wallet', [FlightB2BController::class, 'distributorWallet']);
    Route::get('/b2b/distributor/{id}/inventory', [FlightB2BController::class, 'distributorInventory']);

    // Agent: Status, Profile, Wallet & Inventory
    Route::post('/b2b/agents/{id}/suspend', [FlightB2BController::class, 'suspendAgent']);
    Route::post('/b2b/agents/{id}/activate', [FlightB2BController::class, 'activateAgent']);
    Route::get('/b2b/agents/{id}/profile', [FlightB2BController::class, 'agentProfile']);
    Route::get('/b2b/agents/{id}/wallet', [FlightB2BController::class, 'agentProfile']);
    Route::get('/b2b/agents/{id}/inventory', [FlightB2BController::class, 'agentInventory']);

    // â”€â”€ Phase 20: B2B Booking Workspace â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    Route::get('/b2b/workspace', [FlightB2BController::class, 'workspace']);
    Route::get('/b2b/bookings', [FlightB2BController::class, 'b2bBookings']);
    Route::post('/b2b/bookings', [FlightB2BController::class, 'b2bCreateBooking']);
    Route::get('/b2b/inventory', [FlightB2BController::class, 'b2bInventory']);
    Route::post('/b2b/bookings/{identifier}/pay-wallet', [FlightB2BController::class, 'b2bPayWallet']);
    Route::post('/b2b/bookings/{identifier}/cancel', [FlightB2BController::class, 'b2bCancelBooking']);

    // â”€â”€ Phase 9: Wallet + Double-Entry Ledger + Credit â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    Route::get('/wallets/workspace', [FlightWalletController::class, 'workspace']);
    Route::get('/wallets/tenant', [FlightWalletController::class, 'tenantWallet']);
    Route::get('/wallets/ledger/consistency', [FlightWalletController::class, 'verifyLedger']);
    Route::get('/wallets/transactions', [FlightWalletController::class, 'transactions']);
    Route::post('/wallets/transfer', [FlightWalletController::class, 'transfer']);

    Route::get('/wallets', [FlightWalletController::class, 'index']);
    Route::get('/wallets/{id}', [FlightWalletController::class, 'show']);
    Route::post('/wallets/{id}/recharge', [FlightWalletController::class, 'recharge']);
    Route::post('/wallets/{id}/debit', [FlightWalletController::class, 'debit']);
    Route::post('/wallets/{id}/credit', [FlightWalletController::class, 'credit']);
    Route::post('/wallets/{id}/booking-deduction', [FlightWalletController::class, 'bookingDeduction']);
    Route::post('/wallets/{id}/refund', [FlightWalletController::class, 'refund']);
    Route::post('/wallets/{id}/commission', [FlightWalletController::class, 'commission']);
    Route::post('/wallets/{id}/adjustment', [FlightWalletController::class, 'adjustment']);
    Route::post('/wallets/{id}/credit-limit', [FlightWalletController::class, 'adjustCreditLimit']);
    Route::get('/wallets/{id}/transactions', [FlightWalletController::class, 'transactions']);
    Route::get('/wallets/{id}/ledger', [FlightWalletController::class, 'ledgerEntries']);

    // â”€â”€ Phase 10: Subscription + Quotas + Overage â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    Route::get('/subscriptions/workspace', [FlightSubscriptionController::class, 'workspace']);
    Route::get('/subscriptions/plans', [FlightSubscriptionController::class, 'plans']);
    Route::get('/subscriptions/current', [FlightSubscriptionController::class, 'current']);
    Route::post('/subscriptions/subscribe', [FlightSubscriptionController::class, 'subscribe']);
    Route::post('/subscriptions/upgrade', [FlightSubscriptionController::class, 'upgrade']);
    Route::post('/subscriptions/downgrade', [FlightSubscriptionController::class, 'downgrade']);
    Route::post('/subscriptions/renew', [FlightSubscriptionController::class, 'renew']);
    Route::post('/subscriptions/cancel', [FlightSubscriptionController::class, 'cancel']);
    Route::get('/subscriptions/quotas', [FlightSubscriptionController::class, 'quotas']);
    Route::post('/subscriptions/quotas/{metric}/consume', [FlightSubscriptionController::class, 'consume']);
    Route::get('/subscriptions/overages', [FlightSubscriptionController::class, 'overages']);

    // â”€â”€ Phase 12: Holds Management (Tenant/Admin) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    Route::get('/holds', [FlightHoldController::class, 'indexHolds']);

    // â”€â”€ Phase 13: Bookings Management (Tenant/Admin) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    Route::get('/bookings', [FlightBookingController::class, 'listBookings']);
    Route::put('/bookings/{identifier}/pnr', [FlightBookingController::class, 'assignPnr']);

    // â”€â”€ Phase 15: B2B Wallet Payment â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    Route::post('/bookings/{identifier}/pay-wallet', [FlightPaymentController::class, 'payWithWallet']);

    // â”€â”€ Phase 18: Commission Settlement + 4-Way Reconciliation + Audit Reports â”€
    Route::get('/settlements/workspace', [FlightSettlementController::class, 'workspace']);
    Route::get('/settlements/batches', [FlightSettlementController::class, 'indexBatches']);
    Route::post('/settlements/batches', [FlightSettlementController::class, 'createBatch']);
    Route::get('/settlements/batches/{id}', [FlightSettlementController::class, 'showBatch']);
    Route::post('/settlements/batches/{id}/process', [FlightSettlementController::class, 'processBatch']);
    Route::post('/settlements/batches/{id}/retry', [FlightSettlementController::class, 'retryBatch']);
    Route::post('/settlements/batches/{id}/reconcile', [FlightSettlementController::class, 'reconcileBatch']);
    Route::post('/settlements/batches/{id}/audit-report', [FlightSettlementController::class, 'generateAuditReport']);
    Route::get('/settlements/mismatches', [FlightSettlementController::class, 'indexMismatches']);
    Route::post('/settlements/mismatches/{id}/resolve', [FlightSettlementController::class, 'resolveMismatch']);
    Route::get('/settlements/audit-reports', [FlightSettlementController::class, 'indexAuditReports']);
    Route::get('/settlements/audit-reports/{id}', [FlightSettlementController::class, 'showAuditReport']);

    // â”€â”€ Phase 21: Notifications Delivery & Webhooks Oversight â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    Route::get('/notifications', [FlightNotificationController::class, 'indexNotifications']);
    Route::get('/notifications/{identifier}', [FlightNotificationController::class, 'showNotification']);
    Route::post('/notifications/{identifier}/retry', [FlightNotificationController::class, 'retryNotification']);
    Route::get('/webhooks/{identifier}', [FlightWebhookController::class, 'showWebhook']);
});

// Root-level webhook endpoint matching POST /api/v1/webhooks/{provider}
Route::post('/webhooks/{provider}', [FlightWebhookController::class, 'handleWebhook'])
    ->withoutMiddleware(['auth', EnsureAuthenticatedTenant::class]);


