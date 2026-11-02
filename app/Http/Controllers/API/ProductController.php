<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use App\Support\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Http\Services\ProductService;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Resources\ProductResource;

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

        $products = $query->with('category', 'sizes', 'modifiers.options')->orderBy('sort_order')->paginate(15);

        return ProductResource::collection($products);
    }

    public function store(StoreProductRequest $request)
    {
        $validated = $request->validated();
        $validated['restaurant_id'] = $request->user()->restaurant_id;
        $validated['slug'] = $validated['slug'] ?? \Illuminate\Support\Str::slug($validated['name']);
        $validated['track_inventory'] = $request->boolean('track_inventory', true);
        $validated['is_available'] = $request->boolean('is_available', true);
        if ($request->hasFile('image_file')) {
            $validated['image'] = $request->file('image_file')->store('products', 'public');
        }

        $product = $this->service->create($validated);

        return (new ProductResource($product))
            ->additional(['message' => 'تم إضافة المنتج بنجاح'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Product $product)
    {
        $this->authorizeRestaurant($product);
        return new ProductResource($product->load('category', 'sizes', 'modifiers.options'));
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
            'description' => 'sometimes|nullable|string',
            'tax_rate' => 'sometimes|nullable|numeric|min:0|max:1',
            'track_inventory' => 'sometimes|boolean',
            'current_stock' => 'sometimes|integer|min:0',
            'min_stock' => 'sometimes|integer|min:0',
            'is_featured' => 'sometimes|boolean',
            'is_best_seller' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
            'image_file' => 'sometimes|nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ]);

        if ($request->hasFile('image_file')) {
            $this->deleteStoredImage($product->image);
            $validated['image'] = $request->file('image_file')->store('products', 'public');
        }
        unset($validated['image_file']);
        $this->service->update($product, $validated);

        $updated = $product->fresh();
        $updated->image = PublicMediaUrl::make($updated->image);

        return response()->json(['product' => $updated, 'message' => 'تم تحديث المنتج بنجاح']);
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

        return response()->json([
            'products' => $products->map(function (Product $product) {
                $product->image = PublicMediaUrl::make($product->image);
                return $product;
            }),
        ]);
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

    public function uploadImage(Request $request, Product $product)
    {
        $this->authorizeRestaurant($product);
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ]);
        if ($request->hasFile('image')) {
            $this->deleteStoredImage($product->image);
            $path = $request->file('image')->store('products', 'public');
            $product->update(['image' => $path]);
            return response()->json(['image' => PublicMediaUrl::make($path), 'message' => 'تم رفع الصورة']);
        }
        return response()->json(['message' => 'لم يتم رفع ملف'], 400);
    }

    protected function deleteStoredImage(?string $url): void
    {
        $path = PublicMediaUrl::storagePath($url);
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }

    protected function authorizeRestaurant($model)
    {
        if ($model->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403, 'غير مسموح بالوصول إلى هذا المورد');
        }
    }
}
