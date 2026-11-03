<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

Route::get('media/{path}', function (string $path) {
    abort_unless(
        preg_match('~^(?:products|users|logos)/[A-Za-z0-9._/-]+$~', $path) && !str_contains($path, '..'),
        404
    );
    abort_unless(Storage::disk('public')->exists($path), 404);

    return Storage::disk('public')->response($path, null, [
        'Cache-Control' => 'public, max-age=86400',
        'X-Content-Type-Options' => 'nosniff',
    ]);
})->where('path', '.*')->name('media.show');

/*
|--------------------------------------------------------------------------
| API Routes - Scarlet Restaurant Management System
|--------------------------------------------------------------------------
|
| सभी المسارات محمية بـ Sanctum ما عدا تسجيل الدخول والتسجيل.
| التحقق من الصلاحيات يتم عبر middlewares: role:* و permission:*
|
*/

// ---- Guest routes (no auth) ----
Route::prefix('auth')->group(function () {
    Route::post('login', [\App\Http\Controllers\API\AuthController::class, 'login']);
    Route::post('register', [\App\Http\Controllers\API\AuthController::class, 'register']);
});

// ---- Authenticated routes ----
Route::middleware(['auth:sanctum', 'audit'])->prefix('auth')->group(function () {
    Route::post('logout', [\App\Http\Controllers\API\AuthController::class, 'logout']);
    Route::get('me', [\App\Http\Controllers\API\AuthController::class, 'me']);
    Route::post('avatar', [\App\Http\Controllers\API\AuthController::class, 'uploadAvatar']);
    Route::get('restaurant', [\App\Http\Controllers\API\AuthController::class, 'restaurant']);
    Route::put('restaurant', [\App\Http\Controllers\API\RestaurantController::class, 'update']);
    Route::post('restaurant/logo', [\App\Http\Controllers\API\RestaurantController::class, 'uploadLogo']);
    Route::delete('restaurant/logo', [\App\Http\Controllers\API\RestaurantController::class, 'removeLogo']);
});

// Dashboard
Route::middleware(['auth:sanctum', 'audit'])->prefix('dashboard')->group(function () {
    Route::get('stats', [\App\Http\Controllers\API\DashboardController::class, 'stats']);
    Route::get('sales-chart', [\App\Http\Controllers\API\DashboardController::class, 'salesChart']);
    Route::get('top-products', [\App\Http\Controllers\API\DashboardController::class, 'topProducts']);
    Route::get('staff-performance', [\App\Http\Controllers\API\DashboardController::class, 'staffPerformance']);
    Route::get('inventory-alerts', [\App\Http\Controllers\API\DashboardController::class, 'inventoryAlerts']);
});

// Restaurants
Route::middleware(['auth:sanctum', 'audit'])->apiResource('restaurants', \App\Http\Controllers\API\RestaurantController::class);

// Categories
Route::middleware(['auth:sanctum', 'audit'])->apiResource('categories', \App\Http\Controllers\API\CategoryController::class);
Route::middleware(['auth:sanctum', 'audit'])->get('categories/tree', [\App\Http\Controllers\API\CategoryController::class, 'tree']);

// Products
Route::middleware(['auth:sanctum', 'audit'])->get('products/available', [\App\Http\Controllers\API\ProductController::class, 'available']);
Route::middleware(['auth:sanctum', 'audit'])->apiResource('products', \App\Http\Controllers\API\ProductController::class);
Route::middleware(['auth:sanctum', 'audit'])->get('products/{product}/sizes', [\App\Http\Controllers\API\ProductController::class, 'sizes']);
Route::middleware(['auth:sanctum', 'audit'])->get('products/{product}/modifiers', [\App\Http\Controllers\API\ProductController::class, 'modifiers']);
Route::middleware(['auth:sanctum', 'audit'])->post('products/{product}/image', [\App\Http\Controllers\API\ProductController::class, 'uploadImage']);

// Product sizes & modifiers (nested under products)
Route::middleware(['auth:sanctum', 'audit'])->apiResource('products.sizes', \App\Http\Controllers\API\ProductSizeController::class)->only(['store', 'update', 'destroy']);
Route::middleware(['auth:sanctum', 'audit'])->apiResource('products.modifiers', \App\Http\Controllers\API\ProductModifierController::class)->only(['store', 'update', 'destroy']);

// Tables & Areas
Route::middleware(['auth:sanctum', 'audit'])->apiResource('areas', \App\Http\Controllers\API\AreaController::class);
Route::middleware(['auth:sanctum', 'audit'])->apiResource('tables', \App\Http\Controllers\API\TableController::class);

// Orders
Route::middleware(['auth:sanctum', 'audit'])->apiResource('orders', \App\Http\Controllers\API\OrderController::class);
Route::middleware(['auth:sanctum', 'audit'])->post('orders/{order}/cancel', [\App\Http\Controllers\API\OrderController::class, 'cancel']);
Route::middleware(['auth:sanctum', 'audit'])->post('orders/{order}/refund', [\App\Http\Controllers\API\OrderController::class, 'refund']);
Route::middleware(['auth:sanctum', 'audit'])->post('orders/{order}/status', [\App\Http\Controllers\API\OrderController::class, 'updateStatus']);
Route::middleware(['auth:sanctum', 'audit'])->post('orders/{order}/payment', [\App\Http\Controllers\API\OrderController::class, 'recordPayment']);

// Kitchen
Route::middleware(['auth:sanctum', 'audit'])->prefix('kitchen')->group(function () {
    Route::get('orders', [\App\Http\Controllers\API\KitchenController::class, 'index']);
    Route::get('stations', [\App\Http\Controllers\API\KitchenController::class, 'stations']);
    Route::post('stations', [\App\Http\Controllers\API\KitchenController::class, 'storeStation']);
    Route::put('stations/{station}', [\App\Http\Controllers\API\KitchenController::class, 'updateStation']);
    Route::delete('stations/{station}', [\App\Http\Controllers\API\KitchenController::class, 'destroyStation']);
    Route::post('order-items/{orderItem}/station', [\App\Http\Controllers\API\KitchenController::class, 'assignStation']);
    Route::post('order-items/{orderItem}/status', [\App\Http\Controllers\API\KitchenController::class, 'updateItemStatus']);
    Route::post('order-items/{orderItem}/start-timer', [\App\Http\Controllers\API\KitchenController::class, 'startTimer']);
    Route::post('order-items/{orderItem}/complete-timer', [\App\Http\Controllers\API\KitchenController::class, 'completeTimer']);
});

// Inventory
Route::middleware(['auth:sanctum', 'audit'])->apiResource('inventory-units', \App\Http\Controllers\API\InventoryUnitController::class);
Route::middleware(['auth:sanctum', 'audit'])->apiResource('suppliers', \App\Http\Controllers\API\SupplierController::class);
Route::middleware(['auth:sanctum', 'audit'])->apiResource('inventory-items', \App\Http\Controllers\API\InventoryItemController::class);
Route::middleware(['auth:sanctum', 'audit'])->apiResource('purchases', \App\Http\Controllers\API\PurchaseController::class);
Route::middleware(['auth:sanctum', 'audit'])->apiResource('stock-movements', \App\Http\Controllers\API\StockMovementController::class);
Route::middleware(['auth:sanctum', 'audit'])->get('inventory-items/low-stock', [\App\Http\Controllers\API\InventoryItemController::class, 'lowStock']);

// Recipes
Route::middleware(['auth:sanctum', 'audit'])->apiResource('recipes', \App\Http\Controllers\API\RecipeController::class);

// Customers
Route::middleware(['auth:sanctum', 'audit'])->apiResource('customers', \App\Http\Controllers\API\CustomerController::class);

// Employees
Route::middleware(['auth:sanctum', 'audit'])->apiResource('employees', \App\Http\Controllers\API\EmployeeController::class);
Route::middleware(['auth:sanctum', 'audit'])->apiResource('attendances', \App\Http\Controllers\API\AttendanceController::class);
Route::middleware(['auth:sanctum', 'audit'])->get('shifts/current', [\App\Http\Controllers\API\ShiftController::class, 'current']);
Route::middleware(['auth:sanctum', 'audit'])->post('shifts/open', [\App\Http\Controllers\API\ShiftController::class, 'store']);
Route::middleware(['auth:sanctum', 'audit'])->get('shifts/{shift}/revenue', [\App\Http\Controllers\API\ShiftController::class, 'revenue']);
Route::middleware(['auth:sanctum', 'audit'])->apiResource('shifts', \App\Http\Controllers\API\ShiftController::class);

// Finance
Route::middleware(['auth:sanctum', 'audit'])->apiResource('expenses', \App\Http\Controllers\API\ExpenseController::class);
Route::middleware(['auth:sanctum', 'audit'])->apiResource('payments', \App\Http\Controllers\API\PaymentController::class);

// Reports
Route::middleware(['auth:sanctum', 'audit'])->prefix('reports')->group(function () {
    Route::get('sales', [\App\Http\Controllers\API\ReportController::class, 'sales']);
    Route::get('profit-loss', [\App\Http\Controllers\API\ReportController::class, 'profitLoss']);
    Route::get('top-products', [\App\Http\Controllers\API\ReportController::class, 'topProducts']);
    Route::get('inventory', [\App\Http\Controllers\API\ReportController::class, 'inventory']);
    Route::get('purchases', [\App\Http\Controllers\API\ReportController::class, 'purchases']);
    Route::get('expenses', [\App\Http\Controllers\API\ReportController::class, 'expenses']);
    Route::get('staff', [\App\Http\Controllers\API\ReportController::class, 'staff']);
    Route::get('payment-methods', [\App\Http\Controllers\API\ReportController::class, 'paymentMethods']);
    Route::get('tax', [\App\Http\Controllers\API\ReportController::class, 'tax']);
    Route::get('shifts', [\App\Http\Controllers\API\ReportController::class, 'shifts']);
    Route::get('export/{type}', [\App\Http\Controllers\API\ReportController::class, 'export']);
});

// Settings
Route::middleware(['auth:sanctum', 'audit'])->apiResource('settings', \App\Http\Controllers\API\SettingController::class);

// Notifications
Route::middleware(['auth:sanctum', 'audit'])->prefix('notifications')->group(function () {
    Route::get('/', [\App\Http\Controllers\API\NotificationController::class, 'index']);
    Route::post('{notification}/read', [\App\Http\Controllers\API\NotificationController::class, 'markRead']);
});

// Users & Roles & Permissions (admin only)
Route::middleware(['auth:sanctum', 'audit', 'role:Super Admin|Admin'])->prefix('admin')->group(function () {
    Route::apiResource('users', \App\Http\Controllers\API\UserController::class);
    Route::apiResource('roles', \App\Http\Controllers\API\RoleController::class);
    Route::apiResource('permissions', \App\Http\Controllers\API\PermissionController::class);
});

// Accounting
Route::middleware(['auth:sanctum', 'audit'])->apiResource('accounts', \App\Http\Controllers\API\AccountController::class);
Route::middleware(['auth:sanctum', 'audit'])->apiResource('journal-entries', \App\Http\Controllers\API\JournalEntryController::class);
Route::middleware(['auth:sanctum', 'audit'])->prefix('accounting')->group(function () {
    Route::get('chart-of-accounts', [\App\Http\Controllers\API\AccountingController::class, 'chartOfAccounts']);
    Route::get('ledger', [\App\Http\Controllers\API\AccountingController::class, 'ledger']);
    Route::get('income-statement', [\App\Http\Controllers\API\AccountingController::class, 'incomeStatement']);
    Route::get('balance-sheet', [\App\Http\Controllers\API\AccountingController::class, 'balanceSheet']);
});
