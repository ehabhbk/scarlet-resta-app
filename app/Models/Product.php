<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'restaurant_id',
        'category_id',
        'name',
        'slug',
        'sku',
        'barcode',
        'description',
        'image',
        'sale_price',
        'cost_price',
        'tax_rate',
        'track_inventory',
        'current_stock',
        'min_stock',
        'is_available',
        'is_featured',
        'is_best_seller',
        'sort_order',
        'sales_count',
    ];

    protected $casts = [
        'sale_price' => 'decimal:4',
        'cost_price' => 'decimal:4',
        'tax_rate' => 'decimal:4',
        'track_inventory' => 'boolean',
        'current_stock' => 'integer',
        'min_stock' => 'integer',
        'is_available' => 'boolean',
        'is_featured' => 'boolean',
        'is_best_seller' => 'boolean',
        'sort_order' => 'integer',
        'sales_count' => 'integer',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function sizes()
    {
        return $this->hasMany(ProductSize::class)->orderBy('sort_order');
    }

    public function modifiers()
    {
        return $this->hasMany(ProductModifier::class)->where('is_active', true)->orderBy('sort_order');
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function scopeAvailable($query)
    {
        return $query->where('is_available', true);
    }

    public function scopeBestSellers($query)
    {
        return $query->where('is_best_seller', true);
    }
}
