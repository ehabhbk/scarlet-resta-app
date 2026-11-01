<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $users = User::where('restaurant_id', $restaurantId)
            ->with('roles')
            ->orderBy('name')
            ->paginate(15);
        return response()->json($users);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'phone' => 'nullable|string|max:50',
            'role' => 'required|string',
            'is_active' => 'boolean',
        ]);
        $validated['restaurant_id'] = $request->user()->restaurant_id;
        $validated['password'] = \Illuminate\Support\Facades\Hash::make($validated['password']);
        $user = User::create($validated);
        $roleName = $validated['role'];
        $role = Role::where('name', $roleName)->where('guard_name', 'sanctum')->first();
        if ($role) {
            $user->assignRole($role);
        }
        return response()->json(['user' => $user->load('roles'), 'message' => 'تم إضافة المستخدم'], 201);
    }

    public function show(User $user)
    {
        if ($user->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        return response()->json(['user' => $user->load('roles')]);
    }

    public function update(Request $request, User $user)
    {
        if ($user->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'password' => 'sometimes|string|min:6',
            'role' => 'sometimes|string',
            'is_active' => 'sometimes|boolean',
        ]);
        if ($request->has('password')) {
            $validated['password'] = \Illuminate\Support\Facades\Hash::make($validated['password']);
        }
        $user->update($validated);
        if ($request->has('role')) {
            $user->syncRoles([$request->role]);
        }
        return response()->json(['user' => $user->fresh()->load('roles'), 'message' => 'تم تحديث المستخدم']);
    }

    public function destroy(User $user)
    {
        if ($user->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $user->delete();
        return response()->json(['message' => 'تم حذف المستخدم']);
    }
}

class RoleController extends Controller
{
    public function index(Request $request)
    {
        $roles = Role::where('guard_name', 'sanctum')->with('permissions')->orderBy('name')->get();
        return response()->json(['roles' => $roles]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:roles,name',
            'permissions' => 'sometimes|array',
        ]);
        $role = Role::create(['name' => $validated['name'], 'guard_name' => 'sanctum']);
        if (!empty($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        }
        return response()->json(['role' => $role->load('permissions'), 'message' => 'تم إضافة الدور'], 201);
    }

    public function show(Role $role)
    {
        return response()->json(['role' => $role->load('permissions')]);
    }

    public function update(Request $request, Role $role)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:100|unique:roles,name,' . $role->id,
            'permissions' => 'sometimes|array',
        ]);
        $role->update($validated);
        if ($request->has('permissions')) {
            $role->syncPermissions($validated['permissions']);
        }
        return response()->json(['role' => $role->fresh()->load('permissions'), 'message' => 'تم تحديث الدور']);
    }

    public function destroy(Role $role)
    {
        if (in_array($role->name, ['Super Admin'])) {
            return response()->json(['message' => 'لا يمكن حذف الدور الأساسي'], 400);
        }
        $role->delete();
        return response()->json(['message' => 'تم حذف الدور']);
    }
}

class PermissionController extends Controller
{
    public function index(Request $request)
    {
        $permissions = Permission::where('guard_name', 'sanctum')->orderBy('name')->get();
        return response()->json(['permissions' => $permissions]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:permissions,name',
        ]);
        $permission = \Spatie\Permission\Models\Permission::create([
            'name' => $validated['name'],
            'guard_name' => 'sanctum',
        ]);
        return response()->json(['permission' => $permission, 'message' => 'تم إضافة الصلاحية'], 201);
    }

    public function destroy(\Spatie\Permission\Models\Permission $permission)
    {
        $permission->delete();
        return response()->json(['message' => 'تم حذف الصلاحية']);
    }
}
