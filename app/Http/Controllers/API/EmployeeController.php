<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Models\Attendance;
use Illuminate\Http\Request;
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
        $shifts = \App\Models\Shift::where('restaurant_id', $restaurantId)
            ->with('user', 'employee')
            ->orderByDesc('shift_date')
            ->paginate(15);
        return response()->json($shifts);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'employee_id' => 'nullable|exists:employees,id',
            'name' => 'required|string|max:100',
            'shift_date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'status' => 'sometimes|in:open,closed',
            'opening_cash' => 'nullable|numeric|min:0',
            'expected_cash' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);
        $validated['restaurant_id'] = $request->user()->restaurant_id;
        $validated['opening_cash'] = $validated['opening_cash'] ?? 0;
        $validated['expected_cash'] = $validated['expected_cash'] ?? 0;
        $validated['actual_cash'] = 0;
        $validated['difference'] = 0;
        $validated['status'] = $validated['status'] ?? 'open';
        $shift = \App\Models\Shift::create($validated);
        return response()->json(['shift' => $shift->load('user'), 'message' => 'تم فتح الوردية'], 201);
    }

    public function show(\App\Models\Shift $shift)
    {
        if ($shift->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        return response()->json(['shift' => $shift->load('user', 'employee')]);
    }

    public function update(Request $request, \App\Models\Shift $shift)
    {
        if ($shift->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'actual_cash' => 'sometimes|numeric|min:0',
            'status' => 'sometimes|in:open,closed',
            'notes' => 'sometimes|string',
        ]);
        if ($request->has('actual_cash')) {
            $validated['difference'] = (float) $validated['actual_cash'] - (float) $shift->expected_cash;
        }
        if ($request->has('status') && $request->status === 'closed') {
            $validated['closed_at'] = now();
        }
        $shift->update($validated);
        return response()->json(['shift' => $shift->fresh(), 'message' => 'تم تحديث الوردية']);
    }

    public function destroy(\App\Models\Shift $shift)
    {
        if ($shift->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $shift->delete();
        return response()->json(['message' => 'تم حذف الوردية']);
    }
}
