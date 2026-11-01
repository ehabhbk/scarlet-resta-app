<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use Illuminate\Http\Request;

class RestaurantController extends Controller
{
    public function show(Request $request)
    {
        $restaurant = $request->user()->restaurant;
        if (!$restaurant) {
            abort(404, 'المطرب غير موجود');
        }
        return response()->json(['restaurant' => $restaurant]);
    }

    public function update(Request $request)
    {
        $restaurant = $request->user()->restaurant;
        if (!$restaurant) {
            abort(404);
        }
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:50',
            'email' => 'sometimes|email|max:255',
            'address' => 'sometimes|string',
            'currency' => 'sometimes|string|max:10',
            'symbol' => 'sometimes|string|max:10',
            'tax_rate' => 'sometimes|numeric|min:0|max:1',
            'primary_color' => 'sometimes|string|max:20',
            'logo' => 'sometimes|string',
        ]);
        $restaurant->update($validated);
        return response()->json(['restaurant' => $restaurant->fresh(), 'message' => 'تم تحديث بيانات المطرب']);
    }
}
