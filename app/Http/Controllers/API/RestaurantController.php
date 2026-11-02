<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class RestaurantController extends Controller
{
    public function show(Request $request)
    {
        $restaurant = $request->user()->restaurant;
        if (!$restaurant) {
            abort(404, 'المطعم غير موجود');
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
            'address' => 'sometimes|string',
            'currency' => 'sometimes|string|max:10',
            'symbol' => 'sometimes|string|max:10',
            'tax_rate' => 'sometimes|numeric|min:0|max:1',
            'primary_color' => 'sometimes|string|max:20',
            'logo' => 'sometimes|nullable|string',
        ]);
        $restaurant->update($validated);
        return response()->json(['restaurant' => $restaurant->fresh(), 'message' => 'تم تحديث بيانات المطعم']);
    }

    public function uploadLogo(Request $request)
    {
        $restaurant = $request->user()->restaurant;
        if (!$restaurant) {
            abort(404);
        }

        $request->validate([
            'logo' => 'required|image|mimes:jpeg,png,jpg,gif,webp,svg|max:4096',
        ]);

        if ($restaurant->logo && str_starts_with($restaurant->logo, '/storage/')) {
            Storage::disk('public')->delete(str_replace('/storage/', '', $restaurant->logo));
        }

        $path = $request->file('logo')->store('logos', 'public');
        $restaurant->update(['logo' => Storage::disk('public')->url($path)]);

        return response()->json([
            'restaurant' => $restaurant->fresh(),
            'message' => 'تم تحديث شعار المطعم',
        ]);
    }

    public function removeLogo(Request $request)
    {
        $restaurant = $request->user()->restaurant;
        if (!$restaurant) {
            abort(404);
        }

        if ($restaurant->logo && str_starts_with($restaurant->logo, '/storage/')) {
            Storage::disk('public')->delete(str_replace('/storage/', '', $restaurant->logo));
        }
        $restaurant->update(['logo' => null]);

        return response()->json([
            'restaurant' => $restaurant->fresh(),
            'message' => 'تم حذف شعار المطعم',
        ]);
    }
}
