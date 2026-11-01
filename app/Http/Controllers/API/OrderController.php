<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\OrderStatusHistory;
use App\Models\Table;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Payment;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $query = Order::where('restaurant_id', $restaurantId)
            ->with(['table', 'customer', 'cashier', 'waiter', 'items.product', 'payments']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        if ($request->has('table_id')) {
            $query->where('table_id', $request->table_id);
        }
        if ($request->has('search')) {
            $query->where('order_number', 'like', "%{$request->search}%");
        }

        return response()->json($query->orderByDesc('created_at')->paginate(15));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'table_id' => 'nullable|exists:tables,id',
            'customer_id' => 'nullable|exists:customers,id',
            'waiter_id' => 'nullable|exists:users,id',
            'type' => 'required|in:dine_in,takeaway,delivery',
            'payment_method' => 'required|in:cash,card,wallet',
            'discount_amount' => 'nullable|numeric|min:0',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'delivery_fee' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'customer_notes' => 'nullable|string',
            'delivery_address' => 'nullable|string',
            'delivery_phone' => 'nullable|string',
            'amount_paid' => 'nullable|numeric|min:0',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.size_id' => 'nullable|exists:product_sizes,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.notes' => 'nullable|string',
            'items.*.modifier_options' => 'nullable|array',
            'items.*.modifier_options.*.modifier_id' => 'required|exists:product_modifiers,id',
            'items.*.modifier_options.*.modifier_option_id' => 'required|exists:modifier_options,id',
        ]);

        $user = $request->user();
        $restaurantId = $user->restaurant_id;

        return DB::transaction(function () use ($validated, $user, $restaurantId) {
            // Generate order number
            $date = Carbon::now()->format('Ymd');
            $last = Order::where('restaurant_id', $restaurantId)
                ->where('order_number', 'like', "ORD-{$date}-%")
                ->orderByDesc('order_number')
                ->first();
            $seq = $last ? ((int) explode('-', $last->order_number)[2] + 1) : 1;
            $orderNumber = "ORD-{$date}-" . str_pad($seq, 4, '0', STR_PAD_LEFT);

            $subtotal = 0;
            $taxAmount = 0;

            // Calculate totals from product prices (server-side, never trust client prices)
            foreach ($validated['items'] as $item) {
                $product = Product::findOrFail($item['product_id']);
                if ($product->restaurant_id !== $restaurantId) {
                    abort(403, 'منتج غير تابع لهذا المطرب');
                }
                $unitPrice = $product->sale_price;
                if (!empty($item['size_id'])) {
                    $size = $product->sizes()->find($item['size_id']);
                    if ($size) {
                        $unitPrice += $size->price_modifier;
                    }
                }
                $subtotal += $unitPrice * $item['quantity'];
            }

            $discountAmount = $validated['discount_amount'] ?? 0;
            $discountPercent = $validated['discount_percent'] ?? 0;
            if ($discountPercent > 0) {
                $discountAmount += ($subtotal * $discountPercent / 100);
            }
            $discountAmount = min($discountAmount, $subtotal);

            $taxRate = $validated['tax_rate'] ?? null;
            if ($taxRate === null) {
                $restaurant = $user->restaurant;
                $taxRate = $restaurant ? (float) $restaurant->tax_rate : 0;
            }
            $taxAmount = ($subtotal - $discountAmount) * $taxRate;
            $deliveryFee = $validated['delivery_fee'] ?? 0;
            $totalAmount = $subtotal - $discountAmount + $taxAmount + $deliveryFee;
            $amountPaid = $validated['amount_paid'] ?? 0;
            $changeDue = max(0, $amountPaid - $totalAmount);

            $order = Order::create([
                'restaurant_id' => $restaurantId,
                'table_id' => $validated['table_id'] ?? null,
                'customer_id' => $validated['customer_id'] ?? null,
                'cashier_id' => $user->id,
                'waiter_id' => $validated['waiter_id'] ?? null,
                'order_number' => $orderNumber,
                'type' => $validated['type'],
                'status' => 'pending',
                'payment_status' => $amountPaid >= $totalAmount ? 'paid' : ($amountPaid > 0 ? 'partial' : 'unpaid'),
                'payment_method' => $validated['payment_method'],
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'discount_percent' => $discountPercent,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'amount_paid' => $amountPaid,
                'change_due' => $changeDue,
                'notes' => $validated['notes'] ?? null,
                'customer_notes' => $validated['customer_notes'] ?? null,
                'delivery_address' => $validated['delivery_address'] ?? null,
                'delivery_phone' => $validated['delivery_phone'] ?? null,
                'delivery_fee' => $deliveryFee,
            ]);

            // Order items + modifiers
            $sort = 0;
            foreach ($validated['items'] as $itemData) {
                $product = Product::find($itemData['product_id']);
                $unitPrice = $product->sale_price;
                if (!empty($itemData['size_id'])) {
                    $size = $product->sizes()->find($itemData['size_id']);
                    if ($size) {
                        $unitPrice += $size->price_modifier;
                    }
                }
                $itemDiscount = 0;
                $itemTax = 0;
                $itemTotal = ($unitPrice * $itemData['quantity']) - $itemDiscount + $itemTax;

                $orderItem = OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $itemData['product_id'],
                    'size_id' => $itemData['size_id'] ?? null,
                    'quantity' => $itemData['quantity'],
                    'unit_price' => $unitPrice,
                    'discount_amount' => $itemDiscount,
                    'tax_amount' => $itemTax,
                    'total_price' => $itemTotal,
                    'notes' => $itemData['notes'] ?? null,
                    'kitchen_status' => 'pending',
                    'sort_order' => $sort++,
                ]);

                // Modifiers
                if (!empty($itemData['modifier_options'])) {
                    foreach ($itemData['modifier_options'] as $modOpt) {
                        $option = \App\Models\ModifierOption::find($modOpt['modifier_option_id']);
                        $priceMod = $option ? $option->price_modifier : 0;
                        OrderItemModifier::create([
                            'order_item_id' => $orderItem->id,
                            'modifier_id' => $modOpt['modifier_id'],
                            'modifier_option_id' => $modOpt['modifier_option_id'],
                            'price_modifier' => $priceMod,
                        ]);
                    }
                }

                // Deduct inventory via recipe
                $this->deductInventoryForProduct($product->id, $itemData['quantity'], $user->id, $order->id);
            }

            // Update table status
            if ($validated['table_id']) {
                $table = Table::find($validated['table_id']);
                if ($table && $table->restaurant_id === $restaurantId) {
                    $table->update(['status' => 'occupied']);
                }
            }

            // Update customer stats
            if ($validated['customer_id']) {
                $customer = Customer::find($validated['customer_id']);
                if ($customer && $customer->restaurant_id === $restaurantId) {
                    $customer->increment('total_orders');
                    $customer->increment('total_spent', $totalAmount);
                }
            }

            // Status history
            OrderStatusHistory::create([
                'order_id' => $order->id,
                'old_status' => null,
                'new_status' => 'pending',
                'changed_by' => $user->id,
                'notes' => 'طلب جديد',
            ]);

            return response()->json([
                'order' => $order->load(['items.product', 'items.modifiers.modifierOption', 'table', 'customer', 'cashier']),
                'message' => 'تم إنشاء الطلب بنجاح',
            ], 201);
        });
    }

    public function show(Order $order)
    {
        if ($order->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        return response()->json(['order' => $order->load(['items.product.sizes', 'items.modifiers.modifier', 'items.modifiers.modifierOption', 'table', 'customer', 'cashier', 'waiter', 'statusHistories.changedBy', 'payments'])]);
    }

    public function update(Request $request, Order $order)
    {
        if ($order->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'notes' => 'nullable|string',
            'customer_notes' => 'nullable|string',
            'discount_amount' => 'nullable|numeric|min:0',
            'payment_method' => 'sometimes|in:cash,card,wallet',
        ]);
        $order->update($validated);
        return response()->json(['order' => $order->fresh(), 'message' => 'تم تحديث الطلب']);
    }

    public function destroy(Order $order)
    {
        if ($order->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        if ($order->status === 'completed') {
            return response()->json(['message' => 'لا يمكن حذف طلب مكتمل'], 400);
        }
        $order->delete();
        return response()->json(['message' => 'تم حذف الطلب']);
    }

    public function cancel(Request $request, Order $order)
    {
        if ($order->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        if (!in_array($order->status, ['pending', 'confirmed', 'preparing'])) {
            return response()->json(['message' => 'لا يمكن إلغاء الطلب في حالته الحالية'], 400);
        }
        $user = $request->user();
        $validated = $request->validate([
            'reason' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($order, $user, $validated) {
            // Restore inventory
            foreach ($order->items as $item) {
                $this->restoreInventoryForProduct($item->product_id, $item->quantity, $user->id, $order->id);
            }

            // Free table
            if ($order->table_id) {
                Table::where('id', $order->table_id)->update(['status' => 'available']);
            }

            $order->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancellation_reason' => $validated['reason'] ?? null,
            ]);

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'old_status' => $order->getOriginal('status'),
                'new_status' => 'cancelled',
                'changed_by' => $user->id,
                'notes' => 'إلغاء الطلب: ' . ($validated['reason'] ?? 'بدون سبب'),
            ]);

            return response()->json(['order' => $order->fresh(), 'message' => 'تم إلغاء الطلب']);
        });
    }

    public function updateStatus(Request $request, Order $order)
    {
        if ($order->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'status' => 'required|in:pending,confirmed,preparing,ready,served,completed,cancelled',
            'notes' => 'nullable|string',
        ]);

        $oldStatus = $order->status;
        $order->update(['status' => $validated['status']]);

        if ($validated['status'] === 'completed') {
            $order->completed_at = now();
        }

        if ($validated['status'] === 'served' && $order->table_id) {
            Table::where('id', $order->table_id)->update(['status' => 'available']);
        }

        $order->save();

        OrderStatusHistory::create([
            'order_id' => $order->id,
            'old_status' => $oldStatus,
            'new_status' => $validated['status'],
            'changed_by' => $request->user()->id,
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json(['order' => $order->fresh(), 'message' => 'تم تحديث حالة الطلب']);
    }

    public function recordPayment(Request $request, Order $order)
    {
        if ($order->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0',
            'method' => 'required|in:cash,card,wallet,bank_transfer,cheque',
            'reference' => 'nullable|string',
            'transaction_id' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($order, $request, $validated) {
            $payment = Payment::create([
                'restaurant_id' => $order->restaurant_id,
                'paymentable_type' => Order::class,
                'paymentable_id' => $order->id,
                'amount' => $validated['amount'],
                'method' => $validated['method'],
                'reference' => $validated['reference'] ?? null,
                'transaction_id' => $validated['transaction_id'] ?? null,
                'paid_at' => now(),
                'user_id' => $request->user()->id,
                'notes' => null,
            ]);

            $newPaid = $order->amount_paid + $validated['amount'];
            $paymentStatus = $newPaid >= $order->total_amount ? 'paid' : 'partial';
            $order->update(['amount_paid' => $newPaid, 'payment_status' => $paymentStatus]);

            return response()->json(['payment' => $payment, 'order' => $order->fresh(), 'message' => 'تم تسجيل الدفعة']);
        }, 5);
    }

    protected function deductInventoryForProduct($productId, $qty, $userId, $orderId)
    {
        $recipe = Recipe::where('product_id', $productId)->where('is_active', true)->first();
        if (!$recipe) {
            return;
        }
        foreach ($recipe->ingredients as $ingredient) {
            $needed = (float) $ingredient->quantity * (float) $qty;
            $item = InventoryItem::find($ingredient->inventory_item_id);
            if (!$item) {
                continue;
            }
            $item->decrement('current_stock', $needed);
            StockMovement::create([
                'restaurant_id' => $item->restaurant_id,
                'inventory_item_id' => $item->id,
                'type' => 'out',
                'quantity' => $needed,
                'cost_per_unit' => $ingredient->unit_cost,
                'total_cost' => $needed * $ingredient->unit_cost,
                'reference_type' => 'order',
                'reference_id' => $orderId,
                'user_id' => $userId,
                'notes' => "صرف لوصفة منتج #{$productId}",
            ]);
        }
    }

    protected function restoreInventoryForProduct($productId, $qty, $userId, $orderId)
    {
        $recipe = Recipe::where('product_id', $productId)->where('is_active', true)->first();
        if (!$recipe) {
            return;
        }
        foreach ($recipe->ingredients as $ingredient) {
            $restored = (float) $ingredient->quantity * (float) $qty;
            $item = InventoryItem::find($ingredient->inventory_item_id);
            if (!$item) {
                continue;
            }
            $item->increment('current_stock', $restored);
            StockMovement::create([
                'restaurant_id' => $item->restaurant_id,
                'inventory_item_id' => $item->id,
                'type' => 'in',
                'quantity' => $restored,
                'cost_per_unit' => $ingredient->unit_cost,
                'total_cost' => $restored * $ingredient->unit_cost,
                'reference_type' => 'order_cancel',
                'reference_id' => $orderId,
                'user_id' => $userId,
                'notes' => "استعادة مخزون عند إلغاء طلب #{$orderId}",
            ]);
        }
    }
}
