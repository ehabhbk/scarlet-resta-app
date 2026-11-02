<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(LoginRequest $request)
    {
        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'بيانات تسجيل الدخول غير صحيحة'], 401);
        }

        if (!$user->is_active) {
            return response()->json(['message' => 'الحساب معطل، راجع المشرف'], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => new UserResource($user->load('restaurant', 'roles')),
            'token' => $token,
            'message' => 'تم تسجيل الدخول بنجاح',
        ]);
    }

    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
            'restaurant_id' => 'required|exists:restaurants,id',
            'phone' => 'nullable|string|max:20',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            'restaurant_id' => $request->restaurant_id,
            'phone' => $request->phone,
            'is_active' => true,
        ]);

        $role = $request->role ?? 'Cashier';
        $user->assignRole($role, 'sanctum');

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => new UserResource($user->load('restaurant', 'roles')),
            'token' => $token,
            'message' => 'تم إنشاء الحساب بنجاح',
        ], 201);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'تم تسجيل الخروج بنجاح']);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => new UserResource($request->user()->load('restaurant', 'roles')),
        ]);
    }

    public function restaurant(Request $request)
    {
        $restaurant = $request->user()->restaurant;
        if (!$restaurant) {
            return response()->json(['message' => 'لا يوجد مطرب مرتبط بالمستخدم'], 404);
        }
        return response()->json(['restaurant' => $restaurant]);
    }
}
