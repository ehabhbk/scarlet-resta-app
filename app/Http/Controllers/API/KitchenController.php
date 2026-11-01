<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderKitchenStation;
use App\Models\KitchenStation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class KitchenController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;

        $orders = Order::where('restaurant_id', $restaurantId)
            ->whereIn('status', ['pending', 'confirmed', 'preparing', 'ready'])
            ->with(['items.product', 'items.kitchenStations.station', 'table'])
            ->orderBy('created_at')
            ->get();

        $grouped = $orders->map(function ($order) {
            return [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'type' => $order->type,
                'table' => optional($order->table)->name,
                'status' => $order->status,
                'created_at' => $order->created_at,
                'items' => $order->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'product' => $item->product->name,
                        'quantity' => $item->quantity,
                        'kitchen_status' => $item->kitchen_status,
                        'notes' => $item->notes,
                        'stations' => $item->kitchenStations->map(function ($ks) {
                            return [
                                'station' => $ks->station->name,
                                'station_color' => $ks->station->color,
                                'prep_time_minutes' => $ks->prep_time_minutes,
                                'started_at' => $ks->started_at,
                                'completed_at' => $ks->completed_at,
                            ];
                        }),
                    ];
                }),
            ];
        });

        return response()->json(['orders' => $grouped]);
    }

    public function stations(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $stations = KitchenStation::where('restaurant_id', $restaurantId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
        return response()->json(['stations' => $stations]);
    }

    public function assignStation(Request $request, OrderItem $orderItem)
    {
        if ($orderItem->order->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'station_id' => 'required|exists:kitchen_stations,id',
            'prep_time_minutes' => 'nullable|integer|min:1',
        ]);

        $station = KitchenStation::find($validated['station_id']);
        if ($station->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }

        return DB::transaction(function () use ($orderItem, $validated) {
            OrderKitchenStation::updateOrCreate(
                ['order_item_id' => $orderItem->id, 'station_id' => $validated['station_id']],
                ['prep_time_minutes' => $validated['prep_time_minutes'] ?? null]
            );
            return response()->json(['message' => 'تم تكليف المحطة']);
        });
    }

    public function updateItemStatus(Request $request, OrderItem $orderItem)
    {
        if ($orderItem->order->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'kitchen_status' => 'required|in:pending,confirmed,preparing,ready,served,cancelled',
            'station_id' => 'nullable|exists:kitchen_stations,id',
        ]);

        $orderItem->update(['kitchen_status' => $validated['kitchen_status']]);

        // If completed at a station, mark completed_at
        if ($validated['kitchen_status'] === 'ready' && $validated['station_id']) {
            OrderKitchenStation::where('order_item_id', $orderItem->id)
                ->where('station_id', $validated['station_id'])
                ->update(['completed_at' => now()]);
        }

        return response()->json(['order_item' => $orderItem->fresh(), 'message' => 'تم تحديث حالة التحضير']);
    }

    public function startTimer(Request $request, OrderItem $orderItem)
    {
        if ($orderItem->order->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'station_id' => 'required|exists:kitchen_stations,id',
        ]);
        OrderKitchenStation::updateOrCreate(
            ['order_item_id' => $orderItem->id, 'station_id' => $validated['station_id']],
            ['started_at' => now()]
        );
        return response()->json(['message' => 'بدأ المؤقت']);
    }

    public function completeTimer(Request $request, OrderItem $orderItem)
    {
        if ($orderItem->order->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'station_id' => 'required|exists:kitchen_stations,id',
        ]);
        OrderKitchenStation::where('order_item_id', $orderItem->id)
            ->where('station_id', $validated['station_id'])
            ->update(['completed_at' => now()]);
        return response()->json(['message' => 'أكتمل التحضير']);
    }
}
