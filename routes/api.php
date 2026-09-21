<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\VendorStripeConnectController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\RolePermissionController;
use App\Http\Controllers\Api\ServiceCategoryController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\DiscountController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\PaymentMethodController;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\SubAccountController;
use App\Http\Controllers\Api\VendorController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\TourController;
use App\Http\Controllers\Api\AdminStripePaymentController;
// use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\AgentPaymentController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\StripeAgentWebhookController;
// use App\Http\Controllers\Api\StripeOAuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\PackageController;
use App\Http\Controllers\Api\QuickBooksController;
use App\Http\Controllers\Api\QbSyncLogController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\GoogleCalendarController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\AgentAudioController;
use App\Http\Controllers\Api\OrganizationAudioController;
use App\Http\Controllers\Api\FeatureSheetController;
use App\Http\Controllers\Api\EmailTemplateController;
use App\Http\Controllers\Api\MediaJobController;
use App\Http\Controllers\Api\MediaDownloadController;
use App\Http\Controllers\Api\VendorBillingController;
use App\Http\Controllers\Api\VendorInvoiceController;
use App\Http\Controllers\Api\SignatureController;
use App\Http\Controllers\Api\OrderCancellationController;
use App\Http\Controllers\Api\PrintRequestController;
use App\Http\Controllers\Api\VendorEarningsController;
use App\Http\Controllers\Api\TaxSettingsController;

use App\Http\Controllers\Api\DomainController;
use App\Http\Controllers\Api\BrandingController;

Route::get('domains/resolve', [DomainController::class, 'resolve']);

Route::post("register", [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::get("test/ftp-sync/{fileId}", [TourController::class, 'testFtpSync']);
Route::get("test/mls-list", [TourController::class, 'listMlsFiles']);
Route::get("test/mls-check/{fileId}", [TourController::class, 'checkMlsFile']);
Route::post("tours/{tourId}/sync-mls", [TourController::class, 'syncTourToMls']);
Route::get("login", [AuthController::class, 'loginForm'])->name('login');
Route::post("login", [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post("forgot-password", [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post("reset-password", [AuthController::class, 'resetPassword'])->name('password.reset')->middleware('throttle:5,1');
Route::post('agent/signup', [AgentController::class, 'signup']);
Route::get('public/organizations', [OrganizationController::class, 'publicIndex'])->middleware('throttle:30,1');
Route::get('packages', [PackageController::class, 'index'])->middleware(['resolve.org', 'throttle:30,1']);
Route::get('services', [ServiceController::class, 'index'])->middleware(['resolve.org', 'throttle:30,1']);
Route::get('vendors', [VendorController::class, 'index'])->middleware(['resolve.org', 'throttle:30,1']);
Route::post('public/tax-preview', [TaxSettingsController::class, 'calculatePreview'])->middleware(['resolve.org']);
Route::get('twilight-window', [OrderController::class, 'getTwilightWindow'])->middleware(['resolve.org']);
Route::get('feature-sheets/order/{orderUuid}', [FeatureSheetController::class, 'indexByOrder'])->middleware(['resolve.org', 'throttle:60,1']);
Route::get('feature-sheets/{featureSheetUuid}', [FeatureSheetController::class, 'show'])->middleware(['resolve.org', 'throttle:60,1']);
Route::group(["middleware" => ["auth.any:api,agent-api,subaccount-api,vendor-api", "resolve.org"]], function () {
    // Moved from public — now org-scoped
    Route::get('/order-slots', [OrderController::class, 'getAllOrderSlots']);
    Route::get('/discounts', [DiscountController::class, 'index']);
    Route::get('global-settings', [TourController::class, 'getAllGlobalTourSettings']);

    // Organization and Settings access for all roles
    Route::get('/organizations', [OrganizationController::class, 'index']);
    Route::get('/organizations/{uuid}', [OrganizationController::class, 'show']);
    Route::get('/organizations/{uuid}/branding', [BrandingController::class, 'show']);
    Route::get('/settings', [SettingsController::class, 'index']);
    Route::get('/tax-settings', [TaxSettingsController::class, 'show']);
    Route::post('/tax-settings/calculate-preview', [TaxSettingsController::class, 'calculatePreview']);

    Route::post('/notifications/email', [NotificationController::class, 'sendEmail']);

    //update all password
    Route::match(['put', 'patch'], 'vendors/{uuid}/password', [VendorController::class, 'updatePassword']);
    Route::match(['put', 'patch'], 'sub-accounts/{uuid}/password', [SubAccountController::class, 'updatePassword']);
    Route::match(['put', 'patch'], 'agents/{uuid}/password', [AgentController::class, 'updatePassword']);

    //get all order slots for admin/agent/vendor


    Route::get('roles', [RolePermissionController::class, 'roles']);
    Route::get('permissions', [RolePermissionController::class, 'permissions']);
    // Get discounts for vendor and public 


    // Order management - read access for all
    Route::post('orders/update-slot-time', [OrderController::class, 'updateSlotTime']);
    Route::match(['put', 'patch', 'post'], 'orders/{uuid}', [OrderController::class, 'update']);
    Route::post('orders/slots/{slot_uuid}/reassign', [OrderController::class, 'reassignSlot']);
    Route::post('orders/slots/swap', [OrderController::class, 'swapSlots']);

    Route::get('/settings/{key}', [SettingsController::class, 'show']);
    Route::get('/matterport', [TourController::class, 'getMatterPortData']);
    Route::post('/matterport/{uuid}/renew', [TourController::class, 'renewMatterPort']);
    Route::post('/matterport/{uuid}/send-reminder', [TourController::class, 'sendRenewalReminder']);

    //mls data fetch route
    Route::get('/quickbooks/customers', [QuickBooksController::class, 'createCustomer']);
    Route::get('/vendor/fetch-mls-data', [VendorController::class, 'fetchMlsData']);

    //vendor portfolio image routes
    Route::delete('/vendor/{vendorUuid}/portfolio-images', [VendorController::class, 'deleteAllPortfolioImages']);
    // Profile and auth routes for all users
    Route::get("profile", [AuthController::class, 'profile']);
    Route::get("refresh", [AuthController::class, 'refreshToken']);
    Route::get("logout", [AuthController::class, 'logout']);

    // Services - read only for agents and vendors

    Route::get('services/{uuid}', [ServiceController::class, 'show']);

    // Packages - read only for all

    Route::get('packages/{uuid}', [PackageController::class, 'show']);

    // Properties - read access for all
    Route::get('properties', [PropertyController::class, 'index']);
    Route::get('properties/{uuid}', [PropertyController::class, 'show']);
    Route::match(['put', 'patch'], 'properties/{uuid}', [PropertyController::class, 'update']);
    Route::match(['put', 'patch'], 'orders/edit/properties/{uuid}', [PropertyController::class, 'orderUpdateProperty']);

    // Orders - read access for all
    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/{uuid}', [OrderController::class, 'show']);
    Route::get('orders/{uuid}/cancel-preview', [OrderCancellationController::class, 'preview']);
    Route::post('orders/{uuid}/cancel', [OrderCancellationController::class, 'cancel']);
    Route::get('orders/{uuid}/cancel-service-preview/{service_uuid}', [OrderCancellationController::class, 'previewService']);
    Route::post('orders/{uuid}/cancel-service/{service_uuid}', [OrderCancellationController::class, 'cancelService']);

    // Agents - read access for all
    Route::get('agents', [AgentController::class, 'index']);
    Route::get('agents/{uuid}', [AgentController::class, 'show']);
    Route::get('agent-audio', [AgentAudioController::class, 'index']);
    Route::get('organization-audio', [OrganizationAudioController::class, 'index']);

    // Vendors - read access for all

    Route::get('vendors/{uuid}', [VendorController::class, 'show']);

    // Tours - basic access for all
    Route::get('tours/{uuid}', [TourController::class, 'show']);
    Route::get('tours/order/{order_uuid}', [TourController::class, 'getByOrder']);
    Route::post('tours', [TourController::class, 'store']);
    Route::match(['put', 'patch'], 'tours/{uuid}', [TourController::class, 'update']);
    Route::delete('tours/{uuid}', [TourController::class, 'destroy']);
    Route::delete('tours/files/{uuid}', [TourController::class, 'destroyFile']);
    Route::delete('tours/snapshots/{uuid}', [TourController::class, 'destroySnapshot']);
    Route::delete('tours/links/{uuid}', [TourController::class, 'destroyLink']);

    Route::get('tours/files/{uuid}/download', [TourController::class, 'downloadFile']);
    Route::get('tours/snapshots/{uuid}/download', [TourController::class, 'downloadSnapshot']);

    Route::prefix('tours')->group(function () {
        // Route::post('{uuid}/publish', [TourController::class, 'publish']);
        // Route::post('{uuid}/unpublish', [TourController::class, 'unpublish']);
        Route::get('{uuid}/preview', [TourController::class, 'preview']);
        Route::post('{uuid}/share', [TourController::class, 'generateShareLink']);
    });

    Route::get('payment-methods', [PaymentMethodController::class, 'index']);
    Route::post('payment-methods', [PaymentMethodController::class, 'store']);
    Route::get('payment-methods/{uuid}', [PaymentMethodController::class, 'show']);
    Route::match(['put', 'patch'], 'payment-methods/{uuid}', [PaymentMethodController::class, 'update']);
    Route::delete('payment-methods/{uuid}', [PaymentMethodController::class, 'destroy']);

    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']); // user’s notifications
        Route::post('/', [NotificationController::class, 'store']); // manually create
        Route::put('/read/{uuid}', [NotificationController::class, 'markAsRead']);
    });

    Route::prefix('notification-preferences')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\NotificationPreferenceController::class, 'index']);
        Route::put('/', [\App\Http\Controllers\Api\NotificationPreferenceController::class, 'update']);
        Route::get('/events', [\App\Http\Controllers\Api\NotificationPreferenceController::class, 'events']);
    });

    // Email Template access for all users (Agents, Vendors, Admins)
    Route::prefix('email-templates')->group(function () {
        Route::get('/', [EmailTemplateController::class, 'index']);
        Route::get('/{uuid}', [EmailTemplateController::class, 'show']);
        Route::post('/{uuid}/preview', [EmailTemplateController::class, 'preview']);
    });

    // Media Download Jobs
    Route::post('tours/files/bulk-download', [MediaDownloadController::class, 'initiate']);
    Route::get('media/download-job/{uuid}', [MediaDownloadController::class, 'status']);

    Route::prefix('feature-sheets')->group(function () {
        Route::get('/agent/all', [FeatureSheetController::class, 'indexByAgent']);
        Route::post('/', [FeatureSheetController::class, 'store']);
        Route::put('/{featureSheetUuid}', [FeatureSheetController::class, 'update']);
        Route::patch('/{featureSheetUuid}/toggle-publish', [FeatureSheetController::class, 'togglePublish']);
        Route::delete('/{featureSheetUuid}', [FeatureSheetController::class, 'destroy']);

        // Optional: image delete (UUID)
        Route::delete('/image/{imageUuid}', [FeatureSheetController::class, 'deleteImage']);
        Route::patch('/image/{imageUuid}/toggle-hide', [FeatureSheetController::class, 'toggleHideImage']);

        // Print Requests
        Route::post('/{featureSheetUuid}/print-request', [PrintRequestController::class, 'store']);
    });

    // Calendar Sync Status & Retrospective Sync (Vendor & Agent)
    Route::prefix('calendar')->group(function () {
        Route::get('/sync-status', [GoogleCalendarController::class, 'getSyncStatus']);
        Route::post('/sync-manual', [GoogleCalendarController::class, 'syncManual']);
    });
});

Route::get('/auth/google/callback', [GoogleCalendarController::class, 'handleGoogleCallback'])
    ->name('calendar.callback');

// Routes for Admins/Users ONLY (full access)
Route::group(["middleware" => ["auth:api", "resolve.org"]], function () {


    // User management - admin only
    Route::post('/organizations', [OrganizationController::class, 'store']);
    Route::match(['put', 'patch'], '/organizations/{uuid}', [OrganizationController::class, 'update']);
    Route::delete('/organizations/{uuid}', [OrganizationController::class, 'destroy']);

    // Signature routes
    Route::prefix('organizations/{org_uuid}/signatures')->group(function () {
        Route::get('/', [SignatureController::class, 'index']);
        Route::post('/', [SignatureController::class, 'store']);
    });
    Route::prefix('signatures')->group(function () {
        Route::get('/{signature}', [SignatureController::class, 'show']);
        Route::match(['put', 'patch'], '/{signature}', [SignatureController::class, 'update']);
        Route::delete('/{signature}', [SignatureController::class, 'destroy']);
    });

    // Branding routes
    Route::post('/organizations/{uuid}/branding', [BrandingController::class, 'update']);

    Route::post('/settings/{key}', [SettingsController::class, 'update']);
    Route::delete('/settings/{key}', [SettingsController::class, 'destroy']);
    Route::post('/tax-settings', [TaxSettingsController::class, 'update']);

    Route::get('users', [UserController::class, 'index']);
    Route::get('users/{uuid}', [UserController::class, 'show']);
    Route::post('users', [UserController::class, 'store']);
    Route::match(['put', 'patch'], 'users/{uuid}', [UserController::class, 'update']);
    Route::delete('users/{uuid}', [UserController::class, 'destroy']);
    Route::match(['put', 'patch'], 'users/{uuid}/status', [UserController::class, 'updateStatus']);
    Route::match(['put', 'patch'], 'users/{uuid}/password', [UserController::class, 'updatePassword']);

    //global setttings management - admin only
    Route::put('global-settings/sort', [TourController::class, 'bulkUpdateGlobalSettingsSort']);
    Route::post('global-settings', [TourController::class, 'createGlobalSettings']);
    Route::match(['put', 'patch'], 'global-settings/{uuid}', [TourController::class, 'updateGlobalSettings']);
    Route::delete('global-settings/{uuid}', [TourController::class, 'deleteGlobalTourSetting']);
    Route::get('global-settings/{uuid}', [TourController::class, 'getOneGlobalTourSetting']);
    Route::match(['put', 'patch'], 'global-settings/{uuid}/status', [TourController::class, 'updateStatusGlobalTourSetting']);

    // Media Processing Jobs
    Route::get('admin/media-jobs', [MediaJobController::class, 'index']);
    Route::post('admin/media-jobs/retry', [MediaJobController::class, 'retry']);
    Route::post('admin/media-jobs/bulk', [MediaJobController::class, 'bulkProcess']);

    // Role/Permission management - admin only
    Route::delete('permissions/{id}', [RolePermissionController::class, 'deletePermission']);
    Route::post('roles', [RolePermissionController::class, 'createRole']);
    Route::post('permissions', [RolePermissionController::class, 'createPermission']);
    Route::post('assign-role', [RolePermissionController::class, 'assignRole']);
    Route::post('assign-permission', [RolePermissionController::class, 'assignPermission']);
    Route::get('user-permissions/{uuid}', [RolePermissionController::class, 'userPermissions']);

    // Service management - admin only
    Route::put('services/sort', [ServiceController::class, 'bulkUpdateSort']);
    Route::put('product-options/sort', [ServiceController::class, 'bulkUpdateProductOptionSort']);
    Route::post('services', [ServiceController::class, 'store']);
    Route::match(['put', 'patch'], 'services/{uuid}', [ServiceController::class, 'update']);
    Route::match(['put', 'patch'], 'services/{uuid}/status', [ServiceController::class, 'updateStatus']);
    Route::delete('services/{uuid}', [ServiceController::class, 'destroy']);

    // Package management - admin only
    Route::prefix('packages')->group(function () {
        Route::post('/', [PackageController::class, 'store']);       // create
        Route::match(['put', 'patch'], '{uuid}', [PackageController::class, 'update']);  // update
        Route::match(['put', 'patch'], '{uuid}/status', [PackageController::class, 'updateStatus']); // update status
        Route::delete('{uuid}', [PackageController::class, 'destroy']); // delete
    });

    // Service Category management - admin only
    Route::get('service-categories', [ServiceCategoryController::class, 'index']);
    Route::post('service-categories', [ServiceCategoryController::class, 'store']);
    Route::get('service-categories/{uuid}', [ServiceCategoryController::class, 'show']);
    Route::match(['put', 'patch'], 'service-categories/{uuid}', [ServiceCategoryController::class, 'update']);
    Route::delete('service-categories/{uuid}', [ServiceCategoryController::class, 'destroy']);

    // Discount management - admin only
    Route::post('/discounts', [DiscountController::class, 'store']);
    Route::get('/discounts/{uuid}', [DiscountController::class, 'show']);
    Route::put('/discounts/{uuid}', [DiscountController::class, 'update']);
    Route::delete('/discounts/{uuid}', [DiscountController::class, 'destroy']);
    Route::match(['put', 'patch'], 'discounts/{uuid}/status', [DiscountController::class, 'updateStatus']);

    // Company management - admin only
    Route::get('companies', [CompanyController::class, 'index']);
    Route::post('companies', [CompanyController::class, 'store']);
    Route::get('companies/by_user', [CompanyController::class, 'byUser']);
    Route::get('companies/{uuid}', [CompanyController::class, 'show']);
    Route::match(['put', 'patch'], 'companies/{uuid}', [CompanyController::class, 'update']);
    Route::delete('companies/{uuid}', [CompanyController::class, 'destroy']);

    // Agent management - admin only
    Route::post('agents', [AgentController::class, 'store']);

    Route::match(['put', 'patch'], 'agents/{uuid}/status', [AgentController::class, 'updateStatus']);

    Route::delete('agents/{uuid}', [AgentController::class, 'destroy']);

    // Vendor management - admin only
    Route::post('vendors', [VendorController::class, 'store']);
    Route::match(['put', 'patch'], 'vendors/{uuid}/status', [VendorController::class, 'updateStatus']);

    Route::match(['put', 'patch'], 'vendors/{uuid}/service-status', [VendorController::class, 'updateServiceStatus']);
    Route::delete('vendors/{uuid}', [VendorController::class, 'destroy']);
    Route::delete('vendors/{uuid}/service', [VendorController::class, 'destroyService']);

    //vendor payment - admin only
    Route::post('/admin/pay-vendor', [AdminStripePaymentController::class, 'payVendor']);

    // New Vendor Billing System (Admin)
    Route::prefix('vendor-billing')->group(function () {
        Route::get('summary', [VendorBillingController::class, 'getSummaryMetrics']);
        Route::get('invoices', [VendorBillingController::class, 'index']);
        Route::get('export/csv', [VendorBillingController::class, 'exportCsv']);
        Route::get('uninvoiced', [VendorBillingController::class, 'indexUninvoiced']);
        Route::get('pending/{vendorUuid}', [VendorBillingController::class, 'getPendingItems']);
        Route::get('invoices/{uuid}', [VendorBillingController::class, 'show']);
        Route::post('generate', [VendorBillingController::class, 'generateInvoice']);
        Route::post('pay/{uuid}', [VendorBillingController::class, 'payInvoice']);
        Route::post('pay-manual/{uuid}', [VendorBillingController::class, 'payInvoiceManual']);
        Route::patch('invoices/{uuid}/status', [VendorBillingController::class, 'updateStatus']);
        Route::patch('invoices/{uuid}', [VendorBillingController::class, 'update']);
        Route::patch('update/{uuid}', [VendorBillingController::class, 'update']);
        Route::delete('invoices/{uuid}', [VendorBillingController::class, 'destroy']);
    });

    // Vendor Earnings (Admin)
    Route::get('/admin/vendors/{uuid}/earnings', [VendorEarningsController::class, 'show']);

    // Email logs for admins
    Route::get('/email-logs', [\App\Http\Controllers\Api\EmailLogController::class, 'index']);
    Route::get('/email-logs/{id}', [\App\Http\Controllers\Api\EmailLogController::class, 'show']);

    // Email Template management - admin only
    Route::prefix('email-templates')->group(function () {
        Route::post('/', [EmailTemplateController::class, 'store']);
        Route::match(['put', 'patch'], '/{uuid}', [EmailTemplateController::class, 'update']);
        Route::delete('/{uuid}', [EmailTemplateController::class, 'destroy']);
    });

    // Print Requests (Admin)
    Route::prefix('admin/print-requests')->group(function () {
        Route::get('/', [PrintRequestController::class, 'index']);
        Route::patch('/{request_uuid}', [PrintRequestController::class, 'update']);
    });

    // QuickBooks Management (Admin)
    Route::prefix('quickbooks')->group(function () {
        Route::get('/connect', [QuickBooksController::class, 'connect']);
        Route::get('/status', [QuickBooksController::class, 'status']);
        Route::post('/refresh', [QuickBooksController::class, 'refresh']);
        Route::post('/disconnect', [QuickBooksController::class, 'disconnect']);
        Route::get('/company-info', [QuickBooksController::class, 'getCompanyInfo']);
        Route::get('/tax-codes', [QuickBooksController::class, 'getTaxCodes']);
        Route::post('/customers', [QuickBooksController::class, 'createCustomer']);

        // Invoice Management
        Route::post('invoices/create', [QuickBooksController::class, 'createInvoice']);
        Route::get('invoices/{invoice_id}', [QuickBooksController::class, 'getInvoice']);
        Route::post('invoices/void', [QuickBooksController::class, 'voidInvoice']);

        // Sync Management
        Route::get('sync/queue', [QuickBooksController::class, 'getSyncQueue']);
        Route::post('sync/retry', [QuickBooksController::class, 'retryFailedSyncs']);
        Route::get('sync/order-status', [QuickBooksController::class, 'getOrderSyncStatus']);

        // QB Sync Logs
        Route::get('sync/logs', [QbSyncLogController::class, 'index']);
        Route::get('sync/logs/{uuid}', [QbSyncLogController::class, 'show']);
        Route::post('sync/logs/{uuid}/retry', [QbSyncLogController::class, 'retrySingle']);
    });
});

// Routes for Admins/Users AND Agents (elevated permissions)
Route::group(["middleware" => ["auth.any:api,agent-api,subaccount-api", "resolve.org"]], function () {
    // Billing information
    Route::get('/billing', [BillingController::class, 'getBillings']);
    // Agent payment
    Route::post('/agent/pay/create-session', [AgentPaymentController::class, 'createCheckoutSession']);
    // Manual payments
    Route::post('/pay/manual', [AgentPaymentController::class, 'handleManualPayment']);

    // routes/api.php
    Route::get('/stripe/session', [AgentPaymentController::class, 'getSessionDetails']);

    // Invoice management
    Route::prefix('invoices')->group(function () {
        Route::get('/', [InvoiceController::class, 'index']);
        Route::get('export/csv', [InvoiceController::class, 'exportCsv']);
        Route::get('/{uuid}', [InvoiceController::class, 'show']);
        Route::post('/', [InvoiceController::class, 'store']);
        Route::match(['put', 'patch'], '/{uuid}', [InvoiceController::class, 'update']);
        Route::post('/{uuid}/void', [InvoiceController::class, 'void']);
        Route::post('/{uuid}/refund', [InvoiceController::class, 'refund']);
        Route::get('/{uuid}/refund-receipts', [InvoiceController::class, 'getRefundReceipts']);
        Route::post('/{uuid}/markPaid', [InvoiceController::class, 'markPaid']);
        Route::post('/{uuid}/extra-items', [InvoiceController::class, 'syncExtraItems']);
    });

    // Property management
    Route::post('properties', [PropertyController::class, 'store']);
    Route::match(['put', 'patch'], 'properties/{uuid}/status', [PropertyController::class, 'updateStatus']);
    Route::delete('properties/{uuid}', [PropertyController::class, 'destroy']);

    //audio file management for agents
    Route::post('/agent-audio', [AgentAudioController::class, 'store']);
    Route::delete('/agent-audio/{uuid}', [AgentAudioController::class, 'destroy']);

    //audio file management for organizations
    Route::post('/organization-audio', [OrganizationAudioController::class, 'store']);
    Route::delete('/organization-audio/{uuid}', [OrganizationAudioController::class, 'destroy']);

    // Order management
    Route::post('orders', [OrderController::class, 'store']);

    Route::delete('orders/{uuid}', [OrderController::class, 'destroy']);
    Route::patch('orders/completion-status/update', [OrderController::class, 'IsOrderServiceCompleted']);
    Route::patch('orders/media-access/update', [OrderController::class, 'updateOrderServiceMediaAccess']);


    // Sub Account management - admin only
    Route::get('sub-accounts', [SubAccountController::class, 'index']);
    Route::post('sub-accounts', [SubAccountController::class, 'store']);
    Route::get('sub-accounts/{uuid}', [SubAccountController::class, 'show']);
    Route::match(['put', 'patch'], 'sub-accounts/{uuid}', [SubAccountController::class, 'update']);
    Route::match(['put', 'patch'], 'sub-accounts/{uuid}/status', [SubAccountController::class, 'updateStatus']);

    Route::delete('sub-accounts/{uuid}', [SubAccountController::class, 'destroy']);

    // Order-Property relationship management
    Route::post('orders/add/properties', [PropertyController::class, 'orderStoreProperty']);

    // Agent self-management
    Route::match(['post', 'put', 'patch'], 'agents/{uuid}', [AgentController::class, 'update']);

    // Tour management for agents
    // Route::post('tours', [TourController::class, 'store']);
    // Route::match(['put', 'patch'], 'tours/{uuid}', [TourController::class, 'update']);
    // Route::delete('tours/{uuid}', [TourController::class, 'destroy']);

    Route::post('tours/publish/{uuid}', [TourController::class, 'publish']);

    // Google Calendar for Agents (One-way)
    Route::prefix('agent/calendar')->group(function () {
        Route::get('connect', [GoogleCalendarController::class, 'redirectToGoogle'])->name('agent.calendar.connect');
        Route::post('disconnect', [GoogleCalendarController::class, 'disconnect'])->name('agent.calendar.disconnect');
        Route::get('list', [GoogleCalendarController::class, 'listCalendars']);
        Route::post('set', [GoogleCalendarController::class, 'setCalendar']);
    });
});

// Routes for Vendors ONLY (self-management)
Route::group(["middleware" => ["auth.any:api,vendor-api", "resolve.org"]], function () {
    // Vendor calendar sync routes are now in separate middleware group below
    Route::get('/vendors/{vendorId}/availability', [GoogleCalendarController::class, 'checkAvailability']);
    Route::get('/vendors/{vendorId}/unavailable-dates', [GoogleCalendarController::class, 'getUnavailableDates']);
    Route::get('/vendors/{vendorId}/busy-slots', [GoogleCalendarController::class, 'getBusySlots']);

    //


    // Google Calendar for Vendors (Two-way)
    Route::prefix('vendor/calendar')->group(function () {
        Route::get('connect', [GoogleCalendarController::class, 'redirectToGoogle'])->name('vendor.calendar.connect');
        Route::post('disconnect', [GoogleCalendarController::class, 'disconnect'])->name('vendor.calendar.disconnect');
        Route::get('list', [GoogleCalendarController::class, 'listCalendars']);
        Route::post('set', [GoogleCalendarController::class, 'setCalendar']);
        
        // Events & Stats (Mostly for Vendors)
        Route::get('events', [GoogleCalendarController::class, 'getEvents']);
        Route::get('events/stats', [GoogleCalendarController::class, 'getEventsStats']);
        Route::post('events', [GoogleCalendarController::class, 'createEvent']);
        Route::put('events/{eventId}', [GoogleCalendarController::class, 'updateEvent']);
        Route::delete('events/{eventId}', [GoogleCalendarController::class, 'deleteEvent']);
        Route::post('block-time', [GoogleCalendarController::class, 'blockTimeSlot']);
    });


    Route::delete('/vendors/{vendorUuid}/services/{serviceUuid}', [VendorController::class, 'deleteVendorService']);

    // Vendor self-management
    Route::match(['put', 'patch'], 'vendors/{uuid}', [VendorController::class, 'update']);

    // Vendor break management
    Route::post('vendor-breaks/add', [VendorController::class, 'addBreak']);
    Route::match(['put', 'patch'], 'vendor-breaks/edit/{uuid}', [VendorController::class, 'updateBreak']);
    Route::delete('vendor-breaks/{uuid}', [VendorController::class, 'destroyBreak']);

    // New Vendor Invoice system (Vendor self-access)
    Route::prefix('vendor/invoices')->group(function () {
        Route::get('/', [VendorInvoiceController::class, 'index']);
        Route::get('export/csv', [VendorInvoiceController::class, 'exportCsv']);
        Route::get('/{uuid}', [VendorInvoiceController::class, 'show']);
    });

    // Vendor Earnings (Self)
    Route::get('/vendor/earnings', [VendorEarningsController::class, 'index']);
});

// Presigned URL routes for direct S3 uploads (Admin & Vendors)
Route::prefix('uploads')->middleware(['auth.any:api,agent-api,vendor-api', 'resolve.org'])->group(function () {
    Route::post('/presigned-urls', [App\Http\Controllers\Api\PresignedUrlController::class, 'generateUploadUrls']);
    Route::post('/confirm', [App\Http\Controllers\Api\PresignedUrlController::class, 'confirmUploads']);
    Route::get('/status', [App\Http\Controllers\Api\PresignedUrlController::class, 'getProcessingStatus']);
    Route::delete('/', [App\Http\Controllers\Api\MediaController::class, 'batchDelete']);
    Route::patch('/hide', [App\Http\Controllers\Api\MediaController::class, 'batchHide']);
});

Route::get('/stripe/onboard/success', [VendorStripeConnectController::class, 'onboardingSuccess']);
Route::get('/stripe/onboard/refresh', [VendorStripeConnectController::class, 'onboardingRefresh']);
// Route::post('/stripe/webhook', [StripeWebhookController::class, 'handleWebhook']);

Route::post('/webhook/stripe/agentpayment', [StripeAgentWebhookController::class, 'handleWebhook']);

    Route::get('/quickbooks/callback', [QuickBooksController::class, 'callback']);


Route::get('/tour/public/{uuid}', [OrderController::class, 'getOrderTourData']);
Route::get('/public/tour/{slug?}', [TourController::class, 'publishedTours'])->middleware('resolve.org');

// Public Tour Stats API - accessible without authentication for public tour pages
// Rate limited for abuse protection
Route::prefix('tours')->group(function () {
    Route::get('{uuid}/stats', [TourController::class, 'getStats'])->middleware('throttle:30,1'); // 30 requests per minute
    Route::post('{uuid}/stats', [TourController::class, 'recordStat'])->middleware('throttle:60,1'); // 60 requests per minute
});

// Media CORS proxy for WebGL 360 viewer and Canvas operations
Route::get('media/proxy', function (\Illuminate\Http\Request $request) {
    $url = $request->query('url');
    if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
        return response('Invalid URL', 400);
    }

    try {
        $response = \Illuminate\Support\Facades\Http::timeout(15)->get($url);
        if (!$response->successful()) {
            return response('Failed to fetch media', $response->status());
        }

        $contentType = $response->header('Content-Type') ?: 'image/jpeg';
        return response($response->body(), 200, [
            'Content-Type' => $contentType,
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    } catch (\Exception $e) {
        return response('Proxy error: ' . $e->getMessage(), 500);
    }
});