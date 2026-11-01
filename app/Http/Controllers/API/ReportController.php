<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Expense;
use App\Models\Purchase;
use App\Models\Payment;
use App\Models\Product;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;

class ReportController extends Controller
{
    public function sales(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $from = $request->query('from', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $to = $request->query('to', Carbon::now()->format('Y-m-d'));

        $orders = Order::where('restaurant_id', $restaurantId)
            ->where('payment_status', 'paid')
            ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59']);

        return response()->json([
            'total_sales' => (float) $orders->sum('total_amount'),
            'total_orders' => (int) $orders->count(),
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function profitLoss(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $from = $request->query('from', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $to = $request->query('to', Carbon::now()->format('Y-m-d'));

        $revenue = Order::where('restaurant_id', $restaurantId)
            ->where('payment_status', 'paid')
            ->whereBetween('created_at', [$from, $to])
            ->sum('total_amount');

        $expense = Expense::where('restaurant_id', $restaurantId)
            ->whereBetween('expense_date', [$from, $to])
            ->sum('amount');

        $purchase = Purchase::where('restaurant_id', $restaurantId)
            ->whereBetween('order_date', [$from, $to])
            ->sum('total_amount');

        return response()->json([
            'revenue' => (float) $revenue,
            'expense' => (float) $expense,
            'purchase' => (float) $purchase,
            'profit' => (float) ($revenue - $expense - $purchase),
        ]);
    }

    public function topProducts(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $products = Product::where('restaurant_id', $restaurantId)
            ->orderByDesc('sales_count')
            ->limit(10)
            ->get(['id', 'name', 'sales_count', 'sale_price']);
        return response()->json(['products' => $products]);
    }

    public function inventory(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $items = InventoryItem::where('restaurant_id', $restaurantId)
            ->with('unit')
            ->get()
            ->map(function ($i) {
                return [
                    'name' => $i->name,
                    'current_stock' => (float) $i->current_stock,
                    'min_stock' => (float) $i->min_stock,
                    'unit' => optional($i->unit)->abbreviation,
                    'stock_value' => (float) ($i->current_stock * $i->cost_per_unit),
                ];
            });
        return response()->json(['items' => $items]);
    }

    public function purchases(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $from = $request->query('from', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $to = $request->query('to', Carbon::now()->format('Y-m-d'));
        $total = Purchase::where('restaurant_id', $restaurantId)
            ->whereBetween('order_date', [$from, $to])
            ->sum('total_amount');
        return response()->json(['total_purchases' => (float) $total, 'from' => $from, 'to' => $to]);
    }

    public function expenses(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $from = $request->query('from', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $to = $request->query('to', Carbon::now()->format('Y-m-d'));
        $total = Expense::where('restaurant_id', $restaurantId)
            ->whereBetween('expense_date', [$from, $to])
            ->sum('amount');
        return response()->json(['total_expenses' => (float) $total, 'from' => $from, 'to' => $to]);
    }

    public function staff(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $staff = User::where('restaurant_id', $restaurantId)->where('is_active', true)->get()->map(function ($u) {
            return [
                'name' => $u->name,
                'role' => $u->getRoleNames()->first(),
                'orders' => Order::where('restaurant_id', $restaurantId)->where('cashier_id', $u->id)->where('status', 'completed')->count(),
            ];
        });
        return response()->json(['staff' => $staff]);
    }

    public function paymentMethods(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $data = Order::where('restaurant_id', $restaurantId)
            ->where('payment_status', 'paid')
            ->select('payment_method', DB::raw('SUM(total_amount) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('payment_method')
            ->get();
        return response()->json(['methods' => $data]);
    }

    public function tax(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $from = $request->query('from', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $to = $request->query('to', Carbon::now()->format('Y-m-d'));
        $tax = Order::where('restaurant_id', $restaurantId)
            ->whereBetween('created_at', [$from, $to])
            ->sum('tax_amount');
        return response()->json(['total_tax' => (float) $tax]);
    }

    public function shifts(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $shifts = \App\Models\Shift::where('restaurant_id', $restaurantId)
            ->with('user')
            ->orderByDesc('shift_date')
            ->get();
        return response()->json(['shifts' => $shifts]);
    }

    public function export(Request $request, $type)
    {
        $restaurantId = $request->user()->restaurant_id;
        $from = $request->query('from', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $to = $request->query('to', Carbon::now()->format('Y-m-d'));

        if ($type === 'sales') {
            $orders = Order::where('restaurant_id', $restaurantId)
                ->where('payment_status', 'paid')
                ->whereBetween('created_at', [$from, $to])
                ->with('cashier')
                ->get()
                ->map(function ($o) {
                    return [
                        'order_number' => $o->order_number,
                        'date' => $o->created_at,
                        'cashier' => optional($o->cashier)->name,
                        'total' => (float) $o->total_amount,
                        'method' => $o->payment_method,
                        'status' => $o->status,
                    ];
                });

            if ($request->query('format') === 'pdf') {
                $pdf = Pdf::loadView('report.sales', ['orders' => $orders, 'from' => $from, 'to' => $to]);
                return $pdf->download('sales-report.pdf');
            }

            return Excel::download(new \App\Exports\SalesExport($orders), 'sales-report.xlsx');
        }

        return response()->json(['message' => 'نوع التصدير غير مدعوم'], 400);
    }
}
