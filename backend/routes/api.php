<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DiningSessionController;
use App\Http\Controllers\Api\DiningTableController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\HotelController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\KitchenController;
use App\Http\Controllers\Api\KitchenStationController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\OutletController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PushSubscriptionController;
use App\Http\Controllers\Api\RecipeController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SecurityController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\PublicTableStatusController;
use App\Http\Controllers\Api\TablePublicLinkController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('health', HealthController::class);
    Route::post('onboarding', [OnboardingController::class, 'store'])->middleware('throttle:onboarding');

    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:password-reset');
    Route::get('public/tables/{token}/status', [PublicTableStatusController::class, 'show'])->middleware('throttle:public-table');
    Route::post('public/tables/{token}/call-waiter', [PublicTableStatusController::class, 'callWaiter'])->middleware('throttle:public-table-call');

    Route::middleware(['auth:sanctum', 'active.hotel', 'outlet.access'])->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::get('push/config', [PushSubscriptionController::class, 'config']);
        Route::post('push/subscriptions', [PushSubscriptionController::class, 'store']);
        Route::delete('push/subscriptions', [PushSubscriptionController::class, 'destroy']);
        Route::put('me/password', [AuthController::class, 'changePassword'])->middleware('throttle:password-change');

        Route::get('hotel', [HotelController::class, 'show']);
        Route::match(['put', 'patch'], 'hotel', [HotelController::class, 'update']);
        Route::post('hotel/end-day', [HotelController::class, 'endDay']);

        Route::get('outlets', [OutletController::class, 'index']);
        Route::post('outlets', [OutletController::class, 'store']);
        Route::match(['put', 'patch'], 'outlets/{outlet}', [OutletController::class, 'update']);
        Route::delete('outlets/{outlet}', [OutletController::class, 'destroy']);

        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::match(['put', 'patch'], 'users/{user}', [UserController::class, 'update']);

        Route::get('roles', [RoleController::class, 'index']);
        Route::post('roles', [RoleController::class, 'store'])->middleware('owner');
        Route::match(['put', 'patch'], 'roles/{role}', [RoleController::class, 'update'])->middleware('owner');
        Route::get('permissions', [PermissionController::class, 'index'])->middleware('owner');
        Route::get('table-public-links', [TablePublicLinkController::class, 'index'])->middleware('owner');
        Route::post('tables/{table}/public-link', [TablePublicLinkController::class, 'store'])->middleware('owner');
        Route::post('tables/{table}/public-link/rotate', [TablePublicLinkController::class, 'rotate'])->middleware('owner');
        Route::patch('tables/{table}/public-link', [TablePublicLinkController::class, 'update'])->middleware('owner');

        Route::get('categories', [CategoryController::class, 'index'])->middleware('permission:catalog.view');
        Route::post('categories', [CategoryController::class, 'store'])->middleware('permission:catalog.manage');
        Route::match(['put', 'patch'], 'categories/{category}', [CategoryController::class, 'update'])->middleware('permission:catalog.manage');
        Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->middleware('permission:catalog.manage');
        Route::get('kitchen-stations', [KitchenStationController::class, 'index'])->middleware('permission:catalog.view,kitchen.view');
        Route::post('kitchen-stations', [KitchenStationController::class, 'store'])->middleware('permission:catalog.manage');
        Route::match(['put', 'patch'], 'kitchen-stations/{kitchenStation}', [KitchenStationController::class, 'update'])->middleware('permission:catalog.manage');
        Route::delete('kitchen-stations/{kitchenStation}', [KitchenStationController::class, 'destroy'])->middleware('permission:catalog.manage');
        Route::get('products/search', [ProductController::class, 'search'])->middleware('permission:catalog.view,orders.create');
        Route::get('products/popular', [ProductController::class, 'popular'])->middleware('permission:catalog.view,orders.create');
        Route::get('products', [ProductController::class, 'index'])->middleware('permission:catalog.view,orders.create');
        Route::post('products', [ProductController::class, 'store'])->middleware('permission:catalog.manage');
        Route::match(['put', 'patch'], 'products/{product}', [ProductController::class, 'update'])->middleware('permission:catalog.manage');
        Route::get('recipes', [RecipeController::class, 'index'])->middleware('permission:catalog.manage');
        Route::post('recipes', [RecipeController::class, 'store'])->middleware('permission:catalog.manage');
        Route::match(['put', 'patch'], 'recipes/{recipe}', [RecipeController::class, 'update'])->middleware('permission:catalog.manage');

        Route::get('suppliers', [SupplierController::class, 'index'])->middleware('permission:inventory.receive');
        Route::post('suppliers', [SupplierController::class, 'store'])->middleware('permission:inventory.receive');
        Route::match(['put', 'patch'], 'suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('permission:inventory.receive');
        Route::get('inventory/stocks', [InventoryController::class, 'stocks'])->middleware('permission:inventory.view');
        Route::patch('inventory/stocks/{stock}', [InventoryController::class, 'updateStock'])->middleware('permission:inventory.adjust');
        Route::get('inventory/movements', [InventoryController::class, 'movements'])->middleware('permission:inventory.view');
        Route::get('inventory/valuation', [InventoryController::class, 'valuation'])->middleware('permission:inventory.view');
        Route::post('inventory/receipts', [InventoryController::class, 'receipt'])->middleware('permission:inventory.receive');
        Route::post('inventory/opening-stock', [InventoryController::class, 'openingStock'])->middleware('permission:inventory.adjust');
        Route::post('inventory/adjustments', [InventoryController::class, 'adjustment'])->middleware('permission:inventory.adjust');
        Route::post('inventory/wastage', [InventoryController::class, 'wastage'])->middleware('permission:inventory.adjust');
        Route::post('inventory/transfers', [InventoryController::class, 'transfer'])->middleware('permission:inventory.adjust');
        Route::post('inventory/transfers/{transfer}/receive', [InventoryController::class, 'receiveTransfer'])->middleware('permission:inventory.receive');
        Route::post('inventory/counts', [InventoryController::class, 'count'])->middleware('permission:inventory.adjust');
        Route::post('inventory/counts/{count}/approve', [InventoryController::class, 'approveCount'])->middleware('owner');

        Route::get('dashboard', [ReportController::class, 'dashboard'])->middleware('permission:dashboard.view');
        Route::get('reports/invoices', [ReportController::class, 'invoices'])->middleware('permission:reports.view');
        Route::get('reports/payments', [ReportController::class, 'payments'])->middleware('permission:reports.view');
        Route::get('reports/exports', [ReportController::class, 'exports'])->middleware('permission:reports.export');
        Route::post('reports/exports', [ReportController::class, 'export'])->middleware('permission:reports.export');
        Route::get('reports/exports/{export}/download', [ReportController::class, 'download'])->middleware('permission:reports.export');

        Route::get('finance/summary', [FinanceController::class, 'summary'])->middleware('permission:finance.view');
        Route::get('finance/entries', [FinanceController::class, 'entries'])->middleware('permission:finance.view');
        Route::post('finance/entries', [FinanceController::class, 'storeEntry'])->middleware('permission:finance.manage');
        Route::match(['put', 'patch'], 'finance/entries/{entry}', [FinanceController::class, 'updateEntry'])->middleware('permission:finance.manage');
        Route::delete('finance/entries/{entry}', [FinanceController::class, 'destroyEntry'])->middleware('permission:finance.manage');
        Route::get('finance/standing-costs', [FinanceController::class, 'standingCosts'])->middleware('permission:finance.view');
        Route::post('finance/standing-costs', [FinanceController::class, 'storeStandingCost'])->middleware('permission:finance.manage');
        Route::match(['put', 'patch'], 'finance/standing-costs/{cost}', [FinanceController::class, 'updateStandingCost'])->middleware('permission:finance.manage');

        Route::get('tables/status', [DiningTableController::class, 'status'])->middleware('permission:tables.view');
        Route::post('tables/{table}/acknowledge-waiter-call', [DiningTableController::class, 'acknowledgeWaiterCall'])->middleware('permission:tables.view,orders.create');
        Route::get('tables', [DiningTableController::class, 'index'])->middleware('permission:tables.view');
        Route::post('tables', [DiningTableController::class, 'store'])->middleware('permission:tables.configure');
        Route::match(['put', 'patch'], 'tables/{table}', [DiningTableController::class, 'update'])->middleware('permission:tables.configure');
        Route::post('parcels', [DiningTableController::class, 'startParcel'])->middleware('permission:sessions.open');
        Route::post('tables/{table}/sessions', [DiningSessionController::class, 'open'])->middleware('permission:sessions.open');
        Route::get('dining-staff', [DiningSessionController::class, 'staff'])->middleware('permission:sessions.assign');
        Route::post('dining-sessions/{session}/assign-waiter', [DiningSessionController::class, 'assignWaiter'])->middleware('permission:sessions.assign');
        Route::get('dining-sessions/{session}', [DiningSessionController::class, 'show'])->middleware('permission:orders.view');
        Route::post('dining-sessions/{session}/orders', [DiningSessionController::class, 'addOrder'])->middleware(['permission:orders.create', 'idempotent:order']);
        Route::post('dining-sessions/{session}/request-bill', [DiningSessionController::class, 'requestBill'])->middleware('permission:billing.request');
        Route::post('dining-sessions/{session}/close-without-sale', [DiningSessionController::class, 'closeWithoutSale'])->middleware('permission:billing.close_without_sale');
        Route::post('dining-sessions/{session}/discount', [DiningSessionController::class, 'discount'])->middleware('permission:billing.discount');
        Route::post('dining-sessions/{session}/invoice', [InvoiceController::class, 'create'])->middleware('permission:billing.create');
        Route::patch('invoices/{invoice}/guest', [InvoiceController::class, 'guest'])->middleware('permission:billing.create');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('permission:billing.view');
        Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'payment'])->middleware(['permission:payments.manage', 'idempotent:payment']);
        Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])->middleware('permission:billing.void');
        Route::post('invoices/{invoice}/reopen', [InvoiceController::class, 'reopen'])->middleware('permission:billing.create');
        Route::get('billing-owners', [InvoiceController::class, 'owners'])->middleware('permission:billing.view');
        Route::post('invoices/{invoice}/reprint', [InvoiceController::class, 'reprint'])->middleware('permission:billing.view');
        Route::post('invoices/{invoice}/payments/{payment}/refund', [InvoiceController::class, 'refund'])->middleware('permission:billing.refund');
        Route::get('kitchen-stations/{station}/queue', [KitchenController::class, 'queue'])->middleware('permission:kitchen.view');
        Route::post('order-items/{item}/kitchen-status', [KitchenController::class, 'transition'])->middleware('permission:kitchen.update');
        Route::post('order-items/{item}/serve', [KitchenController::class, 'serve'])->middleware('permission:orders.create');
        Route::post('order-items/{item}/cancel', [KitchenController::class, 'cancel'])->middleware('permission:orders.cancel');
        Route::get('security/sessions', [SecurityController::class, 'sessions']);
        Route::delete('security/sessions/{session}', [SecurityController::class, 'revokeSession']);
        Route::delete('security/sessions', [SecurityController::class, 'revokeOtherSessions']);
        Route::get('audit-logs', [SecurityController::class, 'audit'])->middleware('owner');
    });
});
