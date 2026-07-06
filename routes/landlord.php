<?php

use App\Http\Controllers\Landlord\AdminPanelController;
use App\Http\Controllers\Landlord\AlertController;
use App\Http\Controllers\Landlord\DeviceController;
use App\Http\Controllers\Landlord\DeviceInviteCodeController;
use App\Http\Controllers\Landlord\NotificationController;
use App\Http\Controllers\Landlord\OrderController;
use App\Http\Controllers\Landlord\PaymentController;
use App\Http\Controllers\Landlord\PaymentMethodConfigController;
use App\Http\Controllers\Landlord\PaymentNotificationController;
use App\Http\Controllers\Landlord\PlanController;
use App\Http\Controllers\Landlord\ReconciliationDashboardController;
use App\Http\Controllers\Landlord\ResourceController;
use App\Http\Controllers\Landlord\SubscriptionChangeController;
use App\Http\Controllers\Landlord\SubscriptionController;
use App\Http\Controllers\Landlord\SubscriptionHistoryController;
use App\Http\Controllers\Landlord\SystemConfigController;
use App\Http\Controllers\Landlord\TenantController;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Landlord / Admin Routes
|--------------------------------------------------------------------------
|
| These routes are for the platform administrator only.
| They are accessible ONLY from the landlord domain (no subdomain).
| The 'tenant' middleware is intentionally NOT applied here.
|
| Middleware stack: web → auth → verified → EnsureUserIsAdmin
|
*/

Route::middleware(['auth', 'verified', EnsureUserIsAdmin::class])->prefix('admin')->name('landlord.')->group(function () {

    // Panel (home del landlord)
    Route::get('/', [AdminPanelController::class, 'index'])->name('admin-panel');

    // Tenant management
    Route::get('/tenants', [TenantController::class, 'index'])->name('tenants.index');
    Route::get('/tenants/create', [TenantController::class, 'create'])->name('tenants.create');
    Route::post('/tenants', [TenantController::class, 'store'])->name('tenants.store');
    Route::get('/tenants/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
    Route::get('/tenants/{tenant}/edit', [TenantController::class, 'edit'])->name('tenants.edit');
    Route::put('/tenants/{tenant}', [TenantController::class, 'update'])->name('tenants.update');
    Route::delete('/tenants/{tenant}', [TenantController::class, 'destroy'])->name('tenants.destroy');

    // Plan management
    Route::resource('plans', PlanController::class)->except('show');

    // Resource management
    Route::resource('resources', ResourceController::class)->except('show');

    // Subscription management
    Route::get('subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::get('subscriptions/{subscription}', [SubscriptionController::class, 'show'])->name('subscriptions.show');
    Route::post('tenants/{tenant}/subscriptions', [SubscriptionController::class, 'assign'])->name('subscriptions.assign');

    // Plan change (1.5G-buy-plan) — mid-life switch for a tenant
    // that already has a subscription. Distinct from `assign` (the
    // "no subscription / initial assignment" path): this one
    // delegates to ChangePlanService which holds the row lock and
    // the same-plan guard. Both routes coexist on the tenant
    // show page. The new plan comes from the request body
    // (`plan_id`), matching the `assign` style.
    Route::post('tenants/{tenant}/subscription/change', [SubscriptionChangeController::class, 'update'])
        ->name('subscriptions.change');

    // Subscription history
    Route::get('tenants/{tenant}/subscription-history', [SubscriptionHistoryController::class, 'index'])
        ->name('subscriptions.history');

    // Order management — visibility into tenant purchase requests
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');

    // Payment list — all payments across all tenants (independent from orders)
    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');

    // Payment actions (verify / cancel) — used from the Order detail page
    Route::post('payments/{payment}/verify', [PaymentController::class, 'verify'])->name('payments.verify');
    Route::post('payments/{payment}/cancel', [PaymentController::class, 'cancel'])->name('payments.cancel');

    // Payment method config CRUD — manage PagoMóvil and Transferencia accounts
    Route::resource('payment-configs', PaymentMethodConfigController::class)
        ->parameters(['payment-configs' => 'payment_method_config'])
        ->except('show');

    // Device management — phones that capture bank notifications
    Route::resource('devices', DeviceController::class);

    // Device invite codes — single-use per-tenant registration codes
    Route::get('invite-codes', [DeviceInviteCodeController::class, 'index'])->name('invite-codes.index');
    Route::get('invite-codes/create', [DeviceInviteCodeController::class, 'create'])->name('invite-codes.create');
    Route::post('invite-codes', [DeviceInviteCodeController::class, 'store'])->name('invite-codes.store');
    Route::get('invite-codes/{device_invite_code}/edit', [DeviceInviteCodeController::class, 'edit'])->name('invite-codes.edit');
    Route::put('invite-codes/{device_invite_code}', [DeviceInviteCodeController::class, 'update'])->name('invite-codes.update');
    Route::delete('invite-codes/{device_invite_code}', [DeviceInviteCodeController::class, 'destroy'])->name('invite-codes.destroy');

    // Manual notifications — landlord admin can compose, preview, send, and review
    Route::get('notifications', [NotificationController::class, 'create'])->name('notifications.create');
    Route::post('notifications/preview', [NotificationController::class, 'preview'])->name('notifications.preview');
    Route::post('notifications/send', [NotificationController::class, 'send'])->name('notifications.send');
    Route::get('notifications/history', [NotificationController::class, 'history'])->name('notifications.history');

    // System configuration management — dynamic configs with type-aware editing
    Route::get('system-configs', [SystemConfigController::class, 'index'])->name('admin.system-configs');
    Route::put('system-configs/{system_config}', [SystemConfigController::class, 'update'])->name('admin.system-configs.update');

    // System alerts dashboard — filterable, paginated system notification viewer
    Route::get('alerts', [AlertController::class, 'index'])->name('alerts.index');
    Route::post('alerts/{notification}/read', [AlertController::class, 'read'])->name('alerts.read');

    // Payment notifications — monitor and reprocess failed notifications
    Route::get('payment-notifications', [PaymentNotificationController::class, 'index'])->name('payment-notifications.index');
    Route::post('payment-notifications/{notification}/reprocess', [PaymentNotificationController::class, 'reprocess'])->name('payment-notifications.reprocess');

    // Reconciliation dashboard — KPIs, tabs, and management endpoints
    Route::get('reconciliation', [ReconciliationDashboardController::class, 'index'])->name('reconciliation.index');
    Route::get('reconciliation/pending', [ReconciliationDashboardController::class, 'pending'])->name('reconciliation.pending');
    Route::get('reconciliation/matched', [ReconciliationDashboardController::class, 'matched'])->name('reconciliation.matched');
    Route::get('reconciliation/stats', [ReconciliationDashboardController::class, 'stats'])->name('reconciliation.stats');
    Route::get('reconciliation/payments/{payment}', [ReconciliationDashboardController::class, 'show'])->name('reconciliation.payments.show');

});
