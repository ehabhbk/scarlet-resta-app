<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Services\ProductService;

class ProductController extends Controller
{
    protected ProductService $service;

    public function __construct(ProductService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $query = Product::where('restaurant_id', $restaurantId);

        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        if ($request->has('is_available')) {
            $query->where('is_available', $request->boolean('is_available'));
        }
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        $products = $query->with('category')->orderBy('sort_order')->paginate(15);

        return response()->json($products);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'nullable|exists:categories,id',
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:products,slug',
            'sku' => 'nullable|string|max:100|unique:products,sku',
            'barcode' => 'nullable|string|max:100|unique:products,barcode',
            'description' => 'nullable|string',
            'image' => 'nullable|string',
            'sale_price' => 'required|numeric|min:0',
            'cost_price' => 'required|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:1',
            'track_inventory' => 'boolean',
            'current_stock' => 'nullable|integer|min:0',
            'min_stock' => 'nullable|integer|min:0',
            'is_available' => 'boolean',
            'is_featured' => 'boolean',
            'is_best_seller' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $validated['restaurant_id'] = $request->user()->restaurant_id;
        $validated['slug'] = $validated['slug'] ?? \Illuminate\Support\Str::slug($validated['name']);
        $validated['track_inventory'] = $request->boolean('track_inventory', true);
        $validated['is_available'] = $request->boolean('is_available', true);

        $product = $this->service->create($validated);

        return response()->json(['product' => $product, 'message' => 'تم إضافة المنتج بنجاح'], 201);
    }

    public function show(Product $product)
    {
        $this->authorizeRestaurant($product);
        return response()->json(['product' => $product->load('category', 'sizes', 'modifiers.options')]);
    }

    public function update(Request $request, Product $product)
    {
        $this->authorizeRestaurant($product);

        $validated = $request->validate([
            'category_id' => 'nullable|exists:categories,id',
            'name' => 'sometimes|string|max:255',
            'slug' => 'sometimes|string|max:255|unique:products,slug,' . $product->id,
            'sku' => 'sometimes|string|max:100|unique:products,sku,' . $product->id,
            'barcode' => 'sometimes|string|max:100|unique:products,barcode,' . $product->id,
            'sale_price' => 'sometimes|numeric|min:0',
            'cost_price' => 'sometimes|numeric|min:0',
            'is_available' => 'sometimes|boolean',
        ]);

        $product->update($validated);

        return response()->json(['product' => $product->fresh(), 'message' => 'تم تحديث المنتج بنجاح']);
    }

    public function destroy(Product $product)
    {
        $this->authorizeRestaurant($product);
        $product->delete();
        return response()->json(['message' => 'تم حذف المنتج بنجاح']);
    }

    public function available(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $products = Product::where('restaurant_id', $restaurantId)
            ->where('is_available', true)
            ->with('category')
            ->orderBy('sort_order')
            ->get();

        return response()->json(['products' => $products]);
    }

    public function sizes(Product $product)
    {
        $this->authorizeRestaurant($product);
        return response()->json(['sizes' => $product->sizes()->orderBy('sort_order')->get()]);
    }

    public function modifiers(Product $product)
    {
        $this->authorizeRestaurant($product);
        return response()->json(['modifiers' => $product->modifiers()->with('options')->get()]);
    }

    protected function authorizeRestaurant($model)
    {
        if ($model->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403, 'غير مسموح بالوصول إلى هذا المورد');
        }
    }
}
