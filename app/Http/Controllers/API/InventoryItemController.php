<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryUnit;
use App\Models\Supplier;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryItemController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $query = InventoryItem::where('restaurant_id', $restaurantId)
            ->with('unit', 'supplier');

        if ($request->has('category')) {
            $query->where('category', $request->category);
        }
        if ($request->has('low_stock')) {
            $query->whereColumn('current_stock', '<=', 'min_stock');
        }
        if ($request->has('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        return response()->json($query->orderBy('name')->paginate(15));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'unit_id' => 'required|exists:inventory_units,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|max:100|unique:inventory_items,sku',
            'category' => 'nullable|string|max:100',
            'current_stock' => 'nullable|numeric|min:0',
            'min_stock' => 'nullable|numeric|min:0',
            'reorder_qty' => 'nullable|numeric|min:0',
            'cost_per_unit' => 'nullable|numeric|min:0',
            'expiry_date' => 'nullable|date',
            'batch_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $validated['restaurant_id'] = $request->user()->restaurant_id;
        $validated['current_stock'] = $validated['current_stock'] ?? 0;
        $validated['min_stock'] = $validated['min_stock'] ?? 0;
        $validated['cost_per_unit'] = $validated['cost_per_unit'] ?? 0;
        $validated['is_active'] = $request->boolean('is_active', true);

        $item = InventoryItem::create($validated);

        // Initial stock movement if stock > 0
        if ($validated['current_stock'] > 0) {
            StockMovement::create([
                'restaurant_id' => $validated['restaurant_id'],
                'inventory_item_id' => $item->id,
                'type' => 'in',
                'quantity' => $validated['current_stock'],
                'cost_per_unit' => $validated['cost_per_unit'],
                'total_cost' => $validated['current_stock'] * $validated['cost_per_unit'],
                'reference_type' => 'initial',
                'user_id' => $request->user()->id,
                'notes' => 'رصيد أولي',
            ]);
        }

        return response()->json(['item' => $item->load('unit', 'supplier'), 'message' => 'تم إضافة الصنف'], 201);
    }

    public function show(InventoryItem $item)
    {
        if ($item->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        return response()->json(['item' => $item->load('unit', 'supplier')]);
    }

    public function update(Request $request, InventoryItem $item)
    {
        if ($item->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'current_stock' => 'sometimes|numeric|min:0',
            'min_stock' => 'sometimes|numeric|min:0',
            'cost_per_unit' => 'sometimes|numeric|min:0',
            'is_active' => 'sometimes|boolean',
        ]);
        $item->update($validated);
        return response()->json(['item' => $item->fresh(), 'message' => 'تم تحديث الصنف']);
    }

    public function destroy(InventoryItem $item)
    {
        if ($item->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $item->delete();
        return response()->json(['message' => 'تم حذف الصنف']);
    }

    public function lowStock(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $items = InventoryItem::where('restaurant_id', $restaurantId)
            ->whereColumn('current_stock', '<=', 'min_stock')
            ->with('unit')
            ->get();
        return response()->json(['low_stock' => $items]);
    }
}

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $suppliers = Supplier::where('restaurant_id', $restaurantId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
        return response()->json(['suppliers' => $suppliers]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'tax_number' => 'nullable|string|max:100',
            'balance' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ]);
        $validated['restaurant_id'] = $request->user()->restaurant_id;
        $validated['balance'] = $validated['balance'] ?? 0;
        $validated['is_active'] = $request->boolean('is_active', true);
        $supplier = Supplier::create($validated);
        return response()->json(['supplier' => $supplier, 'message' => 'تم إضافة المورد'], 201);
    }

    public function show(Supplier $supplier)
    {
        if ($supplier->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        return response()->json(['supplier' => $supplier]);
    }

    public function update(Request $request, Supplier $supplier)
    {
        if ($supplier->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'balance' => 'sometimes|numeric',
            'is_active' => 'sometimes|boolean',
        ]);
        $supplier->update($validated);
        return response()->json(['supplier' => $supplier->fresh(), 'message' => 'تم تحديث المورد']);
    }

    public function destroy(Supplier $supplier)
    {
        if ($supplier->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $supplier->delete();
        return response()->json(['message' => 'تم حذف المورد']);
    }
}

class PurchaseController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $purchases = Purchase::where('restaurant_id', $restaurantId)
            ->with('supplier', 'user')
            ->orderByDesc('created_at')
            ->paginate(15);
        return response()->json($purchases);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'purchase_number' => 'required|string|max:100|unique:purchases,purchase_number',
            'status' => 'sometimes|in:draft,ordered,received,cancelled',
            'subtotal' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'total_amount' => 'nullable|numeric|min:0',
            'amount_paid' => 'nullable|numeric|min:0',
            'payment_status' => 'sometimes|in:unpaid,partial,paid',
            'order_date' => 'required|date',
            'expected_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.inventory_item_id' => 'required|exists:inventory_items,id',
            'items.*.quantity' => 'required|numeric|min:0',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.expiry_date' => 'nullable|date',
            'items.*.batch_number' => 'nullable|string|max:100',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $restaurantId = $request->user()->restaurant_id;
            $supplier = Supplier::find($validated['supplier_id']);
            if ($supplier->restaurant_id !== $restaurantId) {
                abort(403);
            }

            $subtotal = 0;
            foreach ($validated['items'] as $it) {
                $subtotal += (float) $it['quantity'] * (float) $it['unit_cost'];
            }
            $taxAmount = $validated['tax_amount'] ?? 0;
            $totalAmount = $validated['total_amount'] ?? ($subtotal + $taxAmount);
            $amountPaid = $validated['amount_paid'] ?? 0;

            $purchase = Purchase::create([
                'restaurant_id' => $restaurantId,
                'supplier_id' => $validated['supplier_id'],
                'user_id' => $request->user()->id,
                'purchase_number' => $validated['purchase_number'],
                'status' => $validated['status'] ?? 'ordered',
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'amount_paid' => $amountPaid,
                'payment_status' => $amountPaid >= $totalAmount ? 'paid' : ($amountPaid > 0 ? 'partial' : 'unpaid'),
                'order_date' => $validated['order_date'],
                'expected_date' => $validated['expected_date'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['items'] as $it) {
                $item = InventoryItem::find($it['inventory_item_id']);
                if ($item->restaurant_id !== $restaurantId) {
                    abort(403);
                }
                PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'inventory_item_id' => $it['inventory_item_id'],
                    'quantity' => $it['quantity'],
                    'unit_cost' => $it['unit_cost'],
                    'total_cost' => (float) $it['quantity'] * (float) $it['unit_cost'],
                    'expiry_date' => $it['expiry_date'] ?? null,
                    'batch_number' => $it['batch_number'] ?? null,
                ]);
            }

            // If received, add to inventory
            if ($validated['status'] === 'received') {
                foreach ($validated['items'] as $it) {
                    $item = InventoryItem::find($it['inventory_item_id']);
                    $item->increment('current_stock', (float) $it['quantity']);
                    StockMovement::create([
                        'restaurant_id' => $restaurantId,
                        'inventory_item_id' => $it['inventory_item_id'],
                        'type' => 'in',
                        'quantity' => (float) $it['quantity'],
                        'cost_per_unit' => (float) $it['unit_cost'],
                        'total_cost' => (float) $it['quantity'] * (float) $it['unit_cost'],
                        'reference_type' => 'purchase',
                        'reference_id' => $purchase->id,
                        'user_id' => $request->user()->id,
                        'notes' => "استلام مشتريات #{$validated['purchase_number']}",
                    ]);
                }
            }

            return response()->json(['purchase' => $purchase->load('supplier', 'items.inventoryItem'), 'message' => 'تم إضافة طلب الشراء'], 201);
        });
    }

    public function show(Purchase $purchase)
    {
        if ($purchase->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        return response()->json(['purchase' => $purchase->load('supplier', 'user', 'items.inventoryItem')]);
    }

    public function update(Request $request, Purchase $purchase)
    {
        if ($purchase->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'status' => 'sometimes|in:draft,ordered,received,cancelled',
            'notes' => 'sometimes|string',
        ]);
        $purchase->update($validated);
        return response()->json(['purchase' => $purchase->fresh(), 'message' => 'تم تحديث طلب الشراء']);
    }

    public function destroy(Purchase $purchase)
    {
        if ($purchase->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $purchase->delete();
        return response()->json(['message' => 'تم حذف طلب الشراء']);
    }
}

class StockMovementController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $query = StockMovement::where('restaurant_id', $restaurantId)
            ->with('inventoryItem', 'user');

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        if ($request->has('inventory_item_id')) {
            $query->where('inventory_item_id', $request->inventory_item_id);
        }

        return response()->json($query->orderByDesc('created_at')->paginate(15));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'inventory_item_id' => 'required|exists:inventory_items,id',
            'type' => 'required|in:in,out,adjustment',
            'quantity' => 'required|numeric|min:0',
            'cost_per_unit' => 'nullable|numeric|min:0',
            'reference_type' => 'nullable|string',
            'reference_id' => 'nullable|integer',
            'notes' => 'nullable|string',
        ]);

        $item = InventoryItem::find($validated['inventory_item_id']);
        if ($item->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }

        $totalCost = (float) $validated['quantity'] * ((float) $validated['cost_per_unit'] ?? 0);

        return DB::transaction(function () use ($item, $validated, $totalCost, $request) {
            $movement = StockMovement::create([
                'restaurant_id' => $item->restaurant_id,
                'inventory_item_id' => $validated['inventory_item_id'],
                'type' => $validated['type'],
                'quantity' => (float) $validated['quantity'],
                'cost_per_unit' => (float) ($validated['cost_per_unit'] ?? 0),
                'total_cost' => $totalCost,
                'reference_type' => $validated['reference_type'] ?? null,
                'reference_id' => $validated['reference_id'] ?? null,
                'user_id' => $request->user()->id,
                'notes' => $validated['notes'] ?? null,
            ]);

            // Update current stock
            if ($validated['type'] === 'in') {
                $item->increment('current_stock', (float) $validated['quantity']);
            } elseif ($validated['type'] === 'out') {
                $item->decrement('current_stock', (float) $validated['quantity']);
            } else {
                // adjustment: set directly
                $item->update(['current_stock' => (float) $validated['quantity']]);
            }

            return response()->json(['movement' => $movement->load('inventoryItem'), 'message' => 'تم تسجيل حركة المخزون'], 201);
        });
    }

    public function show(StockMovement $movement)
    {
        if ($movement->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        return response()->json(['movement' => $movement->load('inventoryItem', 'user')]);
    }

    public function destroy(StockMovement $movement)
    {
        if ($movement->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $movement->delete();
        return response()->json(['message' => 'تم حذف الحركة']);
    }
}
