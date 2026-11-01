<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\Setting;
use App\Models\Notification;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $settings = Setting::where('restaurant_id', $restaurantId)
            ->orderBy('group')
            ->orderBy('key')
            ->get();
        return response()->json(['settings' => $settings]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'group' => 'required|string|max:50',
            'key' => 'required|string|max:100',
            'value' => 'nullable|string',
            'type' => 'sometimes|in:string,number,boolean,json',
        ]);
        $validated['restaurant_id'] = $request->user()->restaurant_id;
        $validated['type'] = $validated['type'] ?? 'string';
        $setting = Setting::updateOrCreate(
            ['restaurant_id' => $validated['restaurant_id'], 'group' => $validated['group'], 'key' => $validated['key']],
            ['value' => $validated['value'], 'type' => $validated['type']]
        );
        return response()->json(['setting' => $setting, 'message' => 'تم حفظ الإعداد'], 201);
    }

    public function show(Setting $setting)
    {
        if ($setting->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        return response()->json(['setting' => $setting]);
    }

    public function update(Request $request, Setting $setting)
    {
        if ($setting->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'value' => 'sometimes|string',
            'type' => 'sometimes|in:string,number,boolean,json',
        ]);
        $setting->update($validated);
        return response()->json(['setting' => $setting->fresh(), 'message' => 'تم تحديث الإعداد']);
    }

    public function destroy(Setting $setting)
    {
        if ($setting->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $setting->delete();
        return response()->json(['message' => 'تم حذف الإعداد']);
    }
}

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $query = Notification::where('restaurant_id', $restaurantId);

        // If no specific user_id, show for current user OR global (user_id null)
        $query->where(function ($q) use ($request) {
            $q->whereNull('user_id')
              ->orWhere('user_id', $request->user()->id);
        });

        if ($request->has('unread_only')) {
            $query->where('is_read', false);
        }

        return response()->json($query->orderByDesc('created_at')->paginate(20));
    }

    public function markRead(Request $request, Notification $notification)
    {
        if ($notification->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        if ($notification->user_id && $notification->user_id !== $request->user()->id) {
            abort(403);
        }
        $notification->update(['is_read' => true, 'read_at' => now()]);
        return response()->json(['message' => 'تم تنفيذ الإشعار كمقروء']);
    }
}
