<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar' => $this->avatar,
            'is_active' => (bool) $this->is_active,
            'roles' => $this->whenLoaded('roles', fn() => $this->roles->map(fn($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'guard_name' => $r->guard_name,
            ])),
            'restaurant' => $this->whenLoaded('restaurant', fn() => $this->restaurant ? [
                'id' => $this->restaurant->id,
                'name' => $this->restaurant->name,
                'slug' => $this->restaurant->slug,
                'currency' => $this->restaurant->currency,
                'symbol' => $this->restaurant->symbol,
                'tax_rate' => (float) $this->restaurant->tax_rate,
                'logo' => $this->restaurant->logo,
            ] : null),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
