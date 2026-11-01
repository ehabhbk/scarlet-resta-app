<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

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
Route::middleware('auth:sanctum')->prefix('auth')->group(function () {
    Route::post('logout', [\App\Http\Controllers\API\AuthController::class, 'logout']);
    Route::get('me', [\App\Http\Controllers\API\AuthController::class, 'me']);
    Route::get('restaurant', [\App\Http\Controllers\API\AuthController::class, 'restaurant']);
});

// Dashboard
Route::middleware('auth:sanctum')->prefix('dashboard')->group(function () {
    Route::get('stats', [\App\Http\Controllers\API\DashboardController::class, 'stats']);
    Route::get('sales-chart', [\App\Http\Controllers\API\DashboardController::class, 'salesChart']);
    Route::get('top-products', [\App\Http\Controllers\API\DashboardController::class, 'topProducts']);
    Route::get('staff-performance', [\App\Http\Controllers\API\DashboardController::class, 'staffPerformance']);
    Route::get('inventory-alerts', [\App\Http\Controllers\API\DashboardController::class, 'inventoryAlerts']);
});

// Restaurants
Route::middleware('auth:sanctum')->apiResource('restaurants', \App\Http\Controllers\API\RestaurantController::class);

// Categories
Route::middleware('auth:sanctum')->apiResource('categories', \App\Http\Controllers\API\CategoryController::class);
Route::middleware('auth:sanctum')->get('categories/tree', [\App\Http\Controllers\API\CategoryController::class, 'tree']);

// Products
Route::middleware('auth:sanctum')->apiResource('products', \App\Http\Controllers\API\ProductController::class);
Route::middleware('auth:sanctum')->get('products/available', [\App\Http\Controllers\API\ProductController::class, 'available']);
Route::middleware('auth:sanctum')->get('products/{product}/sizes', [\App\Http\Controllers\API\ProductController::class, 'sizes']);
Route::middleware('auth:sanctum')->get('products/{product}/modifiers', [\App\Http\Controllers\API\ProductController::class, 'modifiers']);

// Product sizes & modifiers (nested under products)
Route::middleware('auth:sanctum')->apiResource('products.sizes', \App\Http\Controllers\API\ProductSizeController::class)->only(['store', 'update', 'destroy']);
Route::middleware('auth:sanctum')->apiResource('products.modifiers', \App\Http\Controllers\API\ProductModifierController::class)->only(['store', 'update', 'destroy']);

// Tables & Areas
Route::middleware('auth:sanctum')->apiResource('areas', \App\Http\Controllers\API\AreaController::class);
Route::middleware('auth:sanctum')->apiResource('tables', \App\Http\Controllers\API\TableController::class);

// Orders
Route::middleware('auth:sanctum')->apiResource('orders', \App\Http\Controllers\API\OrderController::class);
Route::middleware('auth:sanctum')->post('orders/{order}/cancel', [\App\Http\Controllers\API\OrderController::class, 'cancel']);
Route::middleware('auth:sanctum')->post('orders/{order}/status', [\App\Http\Controllers\API\OrderController::class, 'updateStatus']);
Route::middleware('auth:sanctum')->post('orders/{order}/payment', [\App\Http\Controllers\API\OrderController::class, 'recordPayment']);

// Kitchen
Route::middleware('auth:sanctum')->prefix('kitchen')->group(function () {
    Route::get('orders', [\App\Http\Controllers\API\KitchenController::class, 'index']);
    Route::get('stations', [\App\Http\Controllers\API\KitchenController::class, 'stations']);
    Route::post('order-items/{orderItem}/station', [\App\Http\Controllers\API\KitchenController::class, 'assignStation']);
    Route::post('order-items/{orderItem}/status', [\App\Http\Controllers\API\KitchenController::class, 'updateItemStatus']);
    Route::post('order-items/{orderItem}/start-timer', [\App\Http\Controllers\API\KitchenController::class, 'startTimer']);
    Route::post('order-items/{orderItem}/complete-timer', [\App\Http\Controllers\API\KitchenController::class, 'completeTimer']);
});

// Inventory
Route::middleware('auth:sanctum')->apiResource('inventory-units', \App\Http\Controllers\API\InventoryUnitController::class);
Route::middleware('auth:sanctum')->apiResource('suppliers', \App\Http\Controllers\API\SupplierController::class);
Route::middleware('auth:sanctum')->apiResource('inventory-items', \App\Http\Controllers\API\InventoryItemController::class);
Route::middleware('auth:sanctum')->apiResource('purchases', \App\Http\Controllers\API\PurchaseController::class);
Route::middleware('auth:sanctum')->apiResource('stock-movements', \App\Http\Controllers\API\StockMovementController::class);
Route::middleware('auth:sanctum')->get('inventory-items/low-stock', [\App\Http\Controllers\API\InventoryItemController::class, 'lowStock']);

// Recipes
Route::middleware('auth:sanctum')->apiResource('recipes', \App\Http\Controllers\API\RecipeController::class);

// Customers
Route::middleware('auth:sanctum')->apiResource('customers', \App\Http\Controllers\API\CustomerController::class);

// Employees
Route::middleware('auth:sanctum')->apiResource('employees', \App\Http\Controllers\API\EmployeeController::class);
Route::middleware('auth:sanctum')->apiResource('attendances', \App\Http\Controllers\API\AttendanceController::class);
Route::middleware('auth:sanctum')->apiResource('shifts', \App\Http\Controllers\API\ShiftController::class);

// Finance
Route::middleware('auth:sanctum')->apiResource('expenses', \App\Http\Controllers\API\ExpenseController::class);
Route::middleware('auth:sanctum')->apiResource('payments', \App\Http\Controllers\API\PaymentController::class);

// Reports
Route::middleware('auth:sanctum')->prefix('reports')->group(function () {
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
Route::middleware('auth:sanctum')->apiResource('settings', \App\Http\Controllers\API\SettingController::class);

// Notifications
Route::middleware('auth:sanctum')->prefix('notifications')->group(function () {
    Route::get('/', [\App\Http\Controllers\API\NotificationController::class, 'index']);
    Route::post('{notification}/read', [\App\Http\Controllers\API\NotificationController::class, 'markRead']);
});

// Users & Roles & Permissions (admin only)
Route::middleware(['auth:sanctum', 'role:Super Admin|Admin'])->prefix('admin')->group(function () {
    Route::apiResource('users', \App\Http\Controllers\API\UserController::class);
    Route::apiResource('roles', \App\Http\Controllers\API\RoleController::class);
    Route::apiResource('permissions', \App\Http\Controllers\API\PermissionController::class);
});
