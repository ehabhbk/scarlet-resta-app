<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Support\PublicMediaUrl;
use App\Models\Order;
use App\Models\Product;
use App\Models\Expense;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function stats(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $today = Carbon::today();
        $monthStart = Carbon::now()->startOfMonth();

        $todayOrders = Order::where('restaurant_id', $restaurantId)
            ->whereDate('created_at', $today);

        $monthOrders = Order::where('restaurant_id', $restaurantId)
            ->whereBetween('created_at', [$monthStart, Carbon::now()]);

        $todayRevenue = $todayOrders->where('payment_status', 'paid')->sum('total_amount');
        $monthRevenue = $monthOrders->where('payment_status', 'paid')->sum('total_amount');

        $todayCount = $todayOrders->count();
        $monthCount = $monthOrders->count();

        $completed = Order::where('restaurant_id', $restaurantId)
            ->where('status', 'completed')->whereDate('created_at', $today)->count();
        $cancelled = Order::where('restaurant_id', $restaurantId)
            ->where('status', 'cancelled')->whereDate('created_at', $today)->count();
        $pending = Order::where('restaurant_id', $restaurantId)
            ->whereIn('status', ['pending', 'confirmed', 'preparing', 'ready'])->count();

        $monthExpense = Expense::where('restaurant_id', $restaurantId)
            ->whereBetween('expense_date', [$monthStart, Carbon::now()])->sum('amount');

        $netProfit = $monthRevenue - $monthExpense;

        $lowStock = InventoryItem::where('restaurant_id', $restaurantId)
            ->whereColumn('current_stock', '<=', 'min_stock')->count();

        return response()->json([
            'today_revenue' => (float) $todayRevenue,
            'month_revenue' => (float) $monthRevenue,
            'today_orders' => (int) $todayCount,
            'month_orders' => (int) $monthCount,
            'completed_today' => (int) $completed,
            'cancelled_today' => (int) $cancelled,
            'pending_orders' => (int) $pending,
            'month_expense' => (float) $monthExpense,
            'net_profit' => (float) $netProfit,
            'low_stock_alerts' => (int) $lowStock,
        ]);
    }

    public function salesChart(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $range = $request->query('range', '7d'); // 7d, 30d, 90d

        $days = $range === '30d' ? 30 : ($range === '90d' ? 90 : 7);
        $start = Carbon::now()->subDays($days - 1)->startOfDay();

        $sales = Order::where('restaurant_id', $restaurantId)
            ->where('payment_status', 'paid')
            ->where('created_at', '>=', $start)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(total_amount) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $labels = [];
        $data = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->format('Y-m-d');
            $labels[] = Carbon::parse($date)->format('d M');
            $found = $sales->firstWhere('date', $date);
            $data[] = $found ? (float) $found->total : 0;
        }

        return response()->json(['labels' => $labels, 'data' => $data]);
    }

    public function topProducts(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $limit = (int) $request->query('limit', 10);

        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.restaurant_id', $restaurantId)
            ->where('orders.status', '!=', 'cancelled')
            ->select(
                'order_items.product_id',
                DB::raw('SUM(order_items.quantity) as sales_count'),
                DB::raw('SUM(order_items.total_price) as revenue')
            )
            ->groupBy('order_items.product_id')
            ->orderByDesc('sales_count')
            ->limit($limit)
            ->get();

        $products = $rows->map(function ($row) {
            $product = Product::find($row->product_id);
            if (!$product) {
                return null;
            }
            return [
                'id' => $product->id,
                'name' => $product->name,
                'sales_count' => (int) $row->sales_count,
                'revenue' => (float) $row->revenue,
                'sale_price' => (float) $product->sale_price,
                'image' => PublicMediaUrl::make($product->image),
            ];
        })->filter()->values();

        return response()->json(['products' => $products]);
    }

    public function staffPerformance(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;

        $staff = User::where('restaurant_id', $restaurantId)
            ->where('is_active', true)
            ->with('roles')
            ->get()
            ->map(function ($u) use ($restaurantId) {
                $orders = Order::where('restaurant_id', $restaurantId)
                    ->where('cashier_id', $u->id)
                    ->where('status', '!=', 'cancelled');
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'username' => $u->username,
                    'role' => $u->getRoleNames()->first(),
                    'orders_completed' => (int) $orders->count(),
                    'revenue' => (float) (clone $orders)->where('payment_status', 'paid')->sum('total_amount'),
                ];
            })
            ->filter(fn ($row) => $row['orders_completed'] > 0)
            ->sortByDesc('revenue')
            ->values();

        return response()->json(['staff' => $staff]);
    }

    public function inventoryAlerts(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;

        $items = InventoryItem::where('restaurant_id', $restaurantId)
            ->whereColumn('current_stock', '<=', 'min_stock')
            ->get()
            ->map(function ($i) {
                return [
                    'id' => $i->id,
                    'name' => $i->name,
                    'current_stock' => (float) $i->current_stock,
                    'min_stock' => (float) $i->min_stock,
                    'unit' => optional($i->unit)->abbreviation,
                    'sku' => $i->sku,
                ];
            });

        return response()->json(['low_stock' => $items]);
    }
}
