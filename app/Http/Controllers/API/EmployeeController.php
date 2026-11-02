<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Models\Attendance;
use App\Models\Order;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $employees = Employee::where('restaurant_id', $restaurantId)
            ->with('user')
            ->orderBy('name')
            ->paginate(15);
        return response()->json($employees);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'nullable|exists:users,id',
            'employee_id' => 'required|string|max:50|unique:employees,employee_id',
            'name' => 'required|string|max:255',
            'job_title' => 'required|string|max:100',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'hire_date' => 'required|date',
            'salary' => 'nullable|numeric|min:0',
            'status' => 'sometimes|in:active,on_leave,terminated',
            'avatar' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);
        $validated['restaurant_id'] = $request->user()->restaurant_id;
        $validated['salary'] = $validated['salary'] ?? 0;
        $validated['status'] = $validated['status'] ?? 'active';
        $employee = Employee::create($validated);
        return response()->json(['employee' => $employee->load('user'), 'message' => 'تم إضافة الموظف'], 201);
    }

    public function show(Employee $employee)
    {
        if ($employee->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        return response()->json(['employee' => $employee->load('user', 'attendances')]);
    }

    public function update(Request $request, Employee $employee)
    {
        if ($employee->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'job_title' => 'sometimes|string|max:100',
            'salary' => 'sometimes|numeric|min:0',
            'status' => 'sometimes|in:active,on_leave,terminated',
        ]);
        $employee->update($validated);
        return response()->json(['employee' => $employee->fresh(), 'message' => 'تم تحديث الموظف']);
    }

    public function destroy(Employee $employee)
    {
        if ($employee->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $employee->delete();
        return response()->json(['message' => 'تم حذف الموظف']);
    }
}

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $query = Attendance::whereHas('employee', function ($q) use ($restaurantId) {
            $q->where('restaurant_id', $restaurantId);
        })->with('employee');

        if ($request->has('work_date')) {
            $query->where('work_date', $request->work_date);
        }
        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        return response()->json($query->orderByDesc('work_date')->paginate(15));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'work_date' => 'required|date|unique:attendances,employee_id,NULL,id,work_date,' . $request->employee_id,
            'check_in' => 'nullable|date_format:H:i',
            'check_out' => 'nullable|date_format:H:i',
            'status' => 'sometimes|in:present,absent,late,half_day',
            'notes' => 'nullable|string',
        ]);

        $employee = Employee::find($validated['employee_id']);
        if ($employee->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }

        $validated['work_hours'] = 0;
        if ($validated['check_in'] && $validated['check_out']) {
            $start = \Carbon\Carbon::parse($validated['check_in']);
            $end = \Carbon\Carbon::parse($validated['check_out']);
            $validated['work_hours'] = (float) $start->diffInHours($end, true);
        }
        $validated['status'] = $validated['status'] ?? 'present';

        $attendance = Attendance::create($validated);
        return response()->json(['attendance' => $attendance->load('employee'), 'message' => 'تم تسجيل الحضور'], 201);
    }

    public function show(Attendance $attendance)
    {
        if ($attendance->employee->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        return response()->json(['attendance' => $attendance->load('employee')]);
    }

    public function update(Request $request, Attendance $attendance)
    {
        if ($attendance->employee->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'check_in' => 'sometimes|date_format:H:i',
            'check_out' => 'sometimes|date_format:H:i',
            'status' => 'sometimes|in:present,absent,late,half_day',
        ]);
        if ($request->has('check_in') && $request->has('check_out')) {
            $start = \Carbon\Carbon::parse($request->check_in);
            $end = \Carbon\Carbon::parse($request->check_out);
            $validated['work_hours'] = (float) $start->diffInHours($end, true);
        }
        $attendance->update($validated);
        return response()->json(['attendance' => $attendance->fresh(), 'message' => 'تم تحديث الحضور']);
    }

    public function destroy(Attendance $attendance)
    {
        if ($attendance->employee->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $attendance->delete();
        return response()->json(['message' => 'تم حذف سجل الحضور']);
    }
}

class ShiftController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $shifts = Shift::where('restaurant_id', $restaurantId)
            ->with('user', 'employee')
            ->orderByDesc('shift_date')
            ->orderByDesc('id')
            ->paginate($request->get('per_page', 15));
        return response()->json($shifts);
    }

    public function current(Request $request)
    {
        $user = $request->user();
        $restaurantId = $user->restaurant_id;
        $shift = Shift::where('restaurant_id', $restaurantId)
            ->where('user_id', $user->id)
            ->where('status', 'open')
            ->latest('id')
            ->first();

        if (!$shift) {
            return response()->json(['shift' => null]);
        }

        $ordersQuery = Order::where('restaurant_id', $restaurantId)
            ->where('shift_id', $shift->id)
            ->where(function ($q) use ($user) {
                $q->where('cashier_id', $user->id)->orWhereNull('cashier_id');
            });

        $orders = $ordersQuery->where(function ($q) {
            $q->where('payment_status', 'paid')->orWhere('status', 'completed');
        })->get();

        $total = $orders->sum(fn($o) => (float) $o->total_amount);
        $byPayment = [];
        foreach ($orders as $order) {
            $method = $order->payment_method ?: 'cash';
            $byPayment[$method] = ($byPayment[$method] ?? 0) + (float) $order->total_amount;
        }
        $byPayment = array_map(fn($v) => round($v, 4), $byPayment);

        return response()->json([
            'shift' => $shift->load('user'),
            'revenue' => [
                'total' => round($total, 4),
                'by_payment_method' => $byPayment,
            ],
            'orders_count' => $orders->count(),
        ]);
    }

    public function revenue(Request $request, Shift $shift)
    {
        if ($shift->restaurant_id !== $request->user()->restaurant_id) {
            abort(403);
        }

        $orders = Order::where('restaurant_id', $shift->restaurant_id)
            ->where('shift_id', $shift->id)
            ->where(function ($q) {
                $q->where('payment_status', 'paid')->orWhere('status', 'completed');
            })
            ->get();

        $total = $orders->sum(fn($o) => (float) $o->total_amount);
        $byPayment = [];
        foreach ($orders as $order) {
            $method = $order->payment_method ?: 'cash';
            $byPayment[$method] = ($byPayment[$method] ?? 0) + (float) $order->total_amount;
        }

        return response()->json([
            'total' => round($total, 4),
            'by_payment_method' => array_map(fn($v) => round($v, 4), $byPayment),
            'orders_count' => $orders->count(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'employee_id' => 'nullable|exists:employees,id',
            'name' => 'required|string|max:100',
            'shift_date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'status' => 'sometimes|in:open,closed',
            'opening_cash' => 'nullable|numeric|min:0',
            'expected_cash' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $restaurantId = $request->user()->restaurant_id;

        // Close any existing open shift for this user before opening a new one.
        Shift::where('restaurant_id', $restaurantId)
            ->where('user_id', $validated['user_id'])
            ->where('status', 'open')
            ->update([
                'status' => 'closed',
                'closed_at' => now(),
                'end_time' => now()->format('H:i'),
            ]);

        $validated['restaurant_id'] = $restaurantId;
        $validated['opening_cash'] = $validated['opening_cash'] ?? 0;
        $validated['expected_cash'] = $validated['expected_cash'] ?? $validated['opening_cash'];
        $validated['actual_cash'] = 0;
        $validated['difference'] = 0;
        $validated['status'] = $validated['status'] ?? 'open';
        $validated['start_time'] = \Carbon\Carbon::parse($validated['start_time'])->format('H:i:s');
        if (!empty($validated['end_time'])) {
            $validated['end_time'] = \Carbon\Carbon::parse($validated['end_time'])->format('H:i:s');
        } else {
            $validated['end_time'] = '23:59:00';
        }
        $shift = Shift::create($validated);
        return response()->json(['shift' => $shift->load('user'), 'message' => 'تم فتح الوردية'], 201);
    }

    public function show(Shift $shift)
    {
        if ($shift->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        return response()->json(['shift' => $shift->load('user', 'employee')]);
    }

    public function update(Request $request, Shift $shift)
    {
        if ($shift->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'actual_cash' => 'sometimes|numeric|min:0',
            'status' => 'sometimes|in:open,closed',
            'notes' => 'sometimes|string',
            'end_time' => 'sometimes|date_format:H:i',
        ]);
        if ($request->has('status') && $request->status === 'closed') {
            $cashRevenue = (float) Order::where('shift_id', $shift->id)
                ->where('payment_method', 'cash')
                ->where(function ($q) {
                    $q->where('payment_status', 'paid')->orWhere('status', 'completed');
                })
                ->sum('total_amount');
            $expected = (float) ($shift->opening_cash ?? 0) + $cashRevenue;
            $validated['expected_cash'] = round($expected, 4);
            if ($request->has('actual_cash')) {
                $validated['difference'] = round((float) $validated['actual_cash'] - $expected, 4);
            }
            $validated['closed_at'] = now();
            if (empty($validated['end_time'])) {
                $validated['end_time'] = now()->format('H:i:s');
            }
        } elseif ($request->has('actual_cash')) {
            $expected = (float) ($shift->expected_cash ?? $shift->opening_cash ?? 0);
            $validated['difference'] = (float) $validated['actual_cash'] - $expected;
        }
        if (isset($validated['end_time']) && is_string($validated['end_time']) && strlen($validated['end_time']) === 5) {
            $validated['end_time'] = $validated['end_time'] . ':00';
        }
        $shift->update($validated);
        return response()->json(['shift' => $shift->fresh()->load('user'), 'message' => 'تم تحديث الوردية']);
    }

    public function destroy(Shift $shift)
    {
        if ($shift->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $shift->delete();
        return response()->json(['message' => 'تم حذف الوردية']);
    }
}
