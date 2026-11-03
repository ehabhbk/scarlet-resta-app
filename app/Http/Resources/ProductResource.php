<?php

namespace App\Http\Resources;

use App\Support\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'description' => $this->description,
            'image' => PublicMediaUrl::make($this->image),
            'sale_price' => (float) $this->sale_price,
            'cost_price' => (float) $this->cost_price,
            'tax_rate' => $this->tax_rate ? (float) $this->tax_rate : null,
            'track_inventory' => (bool) $this->track_inventory,
            'current_stock' => (int) $this->current_stock,
            'min_stock' => (int) $this->min_stock,
            'is_available' => (bool) $this->is_available,
            'is_featured' => (bool) $this->is_featured,
            'is_best_seller' => (bool) $this->is_best_seller,
            'sort_order' => (int) $this->sort_order,
            'sales_count' => (int) $this->sales_count,
            'category' => $this->whenLoaded('category', fn() => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'color' => $this->category->color,
            ] : null),
            'kitchen_station_id' => $this->kitchen_station_id,
            'kitchen_station' => $this->whenLoaded('kitchenStation', fn() => $this->kitchenStation ? [
                'id' => $this->kitchenStation->id,
                'name' => $this->kitchenStation->display_name ?: $this->kitchenStation->name,
                'color' => $this->kitchenStation->color,
            ] : null),
            'sizes' => $this->whenLoaded('sizes', fn() => $this->sizes->map(fn($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'price_modifier' => (float) $s->price_modifier,
                'is_default' => (bool) $s->is_default,
            ])),
            'modifiers' => $this->whenLoaded('modifiers', fn() => $this->modifiers->map(fn($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'is_required' => (bool) $m->is_required,
                'min_selection' => (int) $m->min_selection,
                'max_selection' => (int) $m->max_selection,
                'options' => $m->options->map(fn($o) => [
                    'id' => $o->id,
                    'name' => $o->name,
                    'price_modifier' => (float) $o->price_modifier,
                    'is_default' => (bool) $o->is_default,
                ]),
            ])),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
