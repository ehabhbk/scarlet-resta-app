<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\ShiftGroup;
use App\Models\User;
use App\Services\CashierAccountService;
use App\Services\JournalPostingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $employees = Employee::where('restaurant_id', $restaurantId)
            ->with('user', 'payAccount')
            ->orderBy('name')
            ->paginate(min(max($request->integer('per_page', 15), 1), 100));

        return response()->json($employees);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('restaurant_id', $request->user()->restaurant_id),
            ],
            'employee_id' => 'required|string|max:50|unique:employees,employee_id',
            'name' => 'required|string|max:255',
            'job_title' => 'required|string|max:100',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'hire_date' => 'required|date',
            'salary' => 'nullable|numeric|min:0',
            'pay_type' => 'sometimes|in:monthly,daily,percentage',
            'pay_rate' => 'nullable|numeric|min:0',
            'pay_account_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where(fn ($query) => $query
                    ->where('restaurant_id', $request->user()->restaurant_id)
                    ->where('type', 'expense')
                    ->where('is_active', true)),
            ],
            'pay_day' => 'nullable|integer|min:1|max:31',
            'pay_auto' => 'sometimes|boolean',
            'status' => 'sometimes|in:active,on_leave,terminated',
            'avatar' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);
        $validated['restaurant_id'] = $request->user()->restaurant_id;
        $validated['salary'] = $validated['salary'] ?? 0;
        $validated['pay_type'] = $validated['pay_type'] ?? 'monthly';
        $validated['pay_rate'] = $validated['pay_rate'] ?? 0;
        $validated['pay_auto'] = $request->boolean('pay_auto', true);
        $validated['status'] = $validated['status'] ?? 'active';
        $employee = DB::transaction(function () use ($validated) {
            $user = ! empty($validated['user_id'])
                ? User::where('restaurant_id', $validated['restaurant_id'])->whereKey($validated['user_id'])->firstOrFail()
                : null;
            $validated['pay_account_id'] = $validated['pay_account_id'] ?? $user?->pay_account_id;
            $employee = Employee::create($validated);
            if (! $employee->pay_account_id) {
                $parent = Account::firstOrCreate(
                    ['restaurant_id' => $employee->restaurant_id, 'code' => '5000'],
                    ['name' => 'رواتب وأجور', 'type' => 'expense', 'opening_balance' => 0, 'is_active' => true]
                );
                if ($parent->type !== 'expense' || ! $parent->is_active) {
                    throw ValidationException::withMessages([
                        'pay_account_id' => ['الحساب الافتراضي للأجور غير صالح أو غير نشط.'],
                    ]);
                }
                $typeName = match ($employee->pay_type) {
                    'daily' => 'يومية',
                    'percentage' => 'نسبة',
                    default => 'راتب',
                };
                $payAccount = Account::firstOrCreate(
                    [
                        'restaurant_id' => $employee->restaurant_id,
                        'code' => '52'.str_pad((string) $employee->id, 6, '0', STR_PAD_LEFT),
                    ],
                    [
                        'parent_id' => $parent->id,
                        'name' => 'مصروف '.$typeName.' '.$employee->name,
                        'type' => 'expense',
                        'opening_balance' => 0,
                        'is_active' => true,
                    ]
                );
                if ($payAccount->type !== 'expense' || ! $payAccount->is_active) {
                    throw ValidationException::withMessages([
                        'pay_account_id' => ['تعذر إنشاء حساب مصروف صالح للموظف.'],
                    ]);
                }
                $employee->update(['pay_account_id' => $payAccount->id]);
            }
            if ($employee->user_id) {
                User::whereKey($employee->user_id)->update(['pay_account_id' => $employee->pay_account_id]);
            }

            return $employee;
        });

        return response()->json(['employee' => $employee->load('user', 'payAccount'), 'message' => 'تم إضافة الموظف'], 201);
    }

    public function show(Employee $employee)
    {
        if ($employee->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }

        return response()->json(['employee' => $employee->load('user', 'payAccount', 'attendances')]);
    }

    public function update(Request $request, Employee $employee)
    {
        if ($employee->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'user_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('restaurant_id', $employee->restaurant_id),
            ],
            'job_title' => 'sometimes|string|max:100',
            'salary' => 'sometimes|numeric|min:0',
            'pay_type' => 'sometimes|in:monthly,daily,percentage',
            'pay_rate' => 'sometimes|numeric|min:0',
            'pay_account_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where(fn ($query) => $query
                    ->where('restaurant_id', $employee->restaurant_id)
                    ->where('type', 'expense')
                    ->where('is_active', true)),
            ],
            'pay_day' => 'sometimes|nullable|integer|min:1|max:31',
            'pay_auto' => 'sometimes|boolean',
            'status' => 'sometimes|in:active,on_leave,terminated',
        ]);
        DB::transaction(function () use ($employee, $validated) {
            $employee->update($validated);
            if ($employee->user_id && $employee->pay_account_id) {
                User::where('restaurant_id', $employee->restaurant_id)
                    ->whereKey($employee->user_id)
                    ->update(['pay_account_id' => $employee->pay_account_id]);
            }
        });

        return response()->json(['employee' => $employee->fresh()->load('user', 'payAccount'), 'message' => 'تم تحديث الموظف']);
    }

    public function destroy(Employee $employee)
    {
        if ($employee->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $employee->delete();

        return response()->json(['message' => 'تم حذف الموظف']);
    }

    public function paySalary(Request $request, Employee $employee)
    {
        if ($employee->restaurant_id !== $request->user()->restaurant_id) {
            abort(403);
        }
        abort_unless($request->user()->hasPermissionTo('pay_employee_wages', 'sanctum'), 403);
        if ($employee->pay_type !== 'monthly') {
            return response()->json(['message' => 'هذا الموظف ليس على راتب شهري'], 400);
        }
        $restaurantId = $employee->restaurant_id;
        $validated = $request->validate([
            'payment_method' => 'required|string|max:50',
        ]);
        app(JournalPostingService::class)
            ->assertConfiguredPaymentMethod($employee->restaurant_id, $validated['payment_method']);
        $amount = (float) $employee->salary;
        if ($amount <= 0) {
            return response()->json(['message' => 'الراتب صفر'], 400);
        }
        $reference = 'SALARY-'.$employee->id.'-'.now()->format('Y-m');
        if (JournalEntry::where('restaurant_id', $restaurantId)->where('reference', $reference)->exists()) {
            return response()->json(['message' => 'تم تسجيل راتب هذا الموظف لهذا الشهر مسبقًا'], 409);
        }
        $payAccountId = $employee->pay_account_id ?: $employee->user?->pay_account_id;
        $expenseAccount = $payAccountId
            ? Account::where('id', $payAccountId)->where('restaurant_id', $restaurantId)->where('type', 'expense')->where('is_active', true)->first()
            : Account::firstOrCreate(
                ['restaurant_id' => $restaurantId, 'code' => '5000'],
                ['name' => 'رواتب وأجور', 'type' => 'expense', 'opening_balance' => 0, 'is_active' => true]
            );
        if (! $expenseAccount) {
            return response()->json(['message' => 'حساب غير صالح'], 400);
        }
        $entry = DB::transaction(function () use ($request, $employee, $restaurantId, $amount, $expenseAccount, $validated, $reference) {
            $cashAccount = app(JournalPostingService::class)
                ->assertConfiguredPaymentMethod($restaurantId, $validated['payment_method']);

            return app(JournalPostingService::class)->post(
                $restaurantId,
                'SAL-',
                $reference,
                now()->toDateString(),
                'راتب '.$employee->name,
                $request->user()->id,
                [
                    ['account_id' => $expenseAccount->id, 'debit' => $amount, 'description' => 'راتب شهري'],
                    ['account_id' => $cashAccount->id, 'credit' => $amount, 'description' => 'راتب شهري'],
                ]
            );
        });

        return response()->json(['message' => 'تم دفع الراتب وإنشاء قيد بنجاح', 'entry' => $entry->load('lines.account')]);
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
            'work_date' => 'required|date|unique:attendances,employee_id,NULL,id,work_date,'.$request->employee_id,
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
            $start = Carbon::parse($validated['check_in']);
            $end = Carbon::parse($validated['check_out']);
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
            $start = Carbon::parse($request->check_in);
            $end = Carbon::parse($request->check_out);
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
        abort_unless(
            $request->user()->hasPermissionTo('view_all_shifts', 'sanctum')
                || $request->user()->hasPermissionTo('close_shift', 'sanctum'),
            403
        );
        $query = Shift::where('restaurant_id', $restaurantId);
        if (! $request->user()->hasPermissionTo('view_all_shifts', 'sanctum')) {
            $query->where('user_id', $request->user()->id);
        }
        $shifts = $query
            ->with('user', 'employee', 'employees', 'group.employees')
            ->orderByDesc('shift_date')
            ->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 15), 1), 100));
        $shifts->getCollection()->each(function (Shift $shift): void {
            $hasOtherOpenShift = $shift->shift_group_id
                ? Shift::where('shift_group_id', $shift->shift_group_id)
                    ->where('status', 'open')
                    ->where('id', '!=', $shift->id)
                    ->exists()
                : false;
            if ($shift->group) {
                $shift->setRelation('employees', $shift->group->employees);
            }
            $shift->setAttribute('wage_disbursement_due', ! $hasOtherOpenShift);
        });

        return response()->json($shifts);
    }

    public function options(Request $request)
    {
        $user = $request->user();
        abort_unless($this->userHasShiftPermission($user, 'open_shift'), 403);
        $canManageEmployees = $this->userHasShiftPermission($user, 'manage_shift_employees');
        $employees = $canManageEmployees
            ? Employee::where('restaurant_id', $user->restaurant_id)
                ->where('status', 'active')
                ->where('pay_auto', true)
                ->whereIn('pay_type', ['daily', 'percentage'])
                ->with('payAccount')
                ->orderBy('name')
                ->get()
            : collect();

        return response()->json([
            'employees' => $employees,
            'permissions' => [
                'manage_shift_employees' => $canManageEmployees,
                'pay_employee_wages' => $this->userHasShiftPermission($user, 'pay_employee_wages'),
            ],
        ]);
    }

    public function employeeWages(Request $request, Shift $shift)
    {
        abort_unless($shift->restaurant_id === $request->user()->restaurant_id, 403);
        abort_unless($request->user()->hasPermissionTo('pay_employee_wages', 'sanctum'), 403);
        abort_unless($shift->status === 'open', 422, 'لا يمكن دفع أجور وردية مغلقة.');

        $group = $shift->group;
        $employees = $this->employeesForWages($shift, $group);
        $revenue = $this->wageRevenue($shift, $group);
        $workDate = Carbon::parse($shift->shift_date)->toDateString();

        $wages = $employees
            ->where('restaurant_id', $shift->restaurant_id)
            ->where('status', 'active')
            ->where('pay_auto', true)
            ->whereIn('pay_type', ['daily', 'percentage'])
            ->map(function (Employee $employee) use ($revenue, $workDate, $shift, $group): array {
                $amount = $employee->pay_type === 'daily'
                    ? (float) $employee->pay_rate
                    : $revenue * ((float) $employee->pay_rate / 100);
                $reference = $this->wageReference($employee, $workDate, $shift, $group);
                $existingEntry = JournalEntry::where('restaurant_id', $shift->restaurant_id)
                    ->where('reference', $reference)
                    ->first();

                return [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'pay_type' => $employee->pay_type,
                    'pay_rate' => (float) $employee->pay_rate,
                    'amount' => round($amount, 2),
                    'payment_method' => $employee->pivot->payment_method,
                    'paid_at' => $employee->pivot->paid_at ?: $existingEntry?->created_at,
                    'journal_entry_id' => $employee->pivot->journal_entry_id ?: $existingEntry?->id,
                ];
            })
            ->filter(fn (array $wage) => ! $wage['paid_at'])
            ->values();

        return response()->json(['employees' => $wages]);
    }

    public function payEmployeeWage(Request $request, Shift $shift)
    {
        abort_unless($shift->restaurant_id === $request->user()->restaurant_id, 403);
        abort_unless($request->user()->hasPermissionTo('pay_employee_wages', 'sanctum'), 403);
        $validated = $request->validate([
            'employee_id' => 'required|integer',
            'payment_method' => 'required|string|max:50',
        ]);

        $entry = DB::transaction(function () use ($request, $shift, $validated): JournalEntry {
            $lockedShift = Shift::whereKey($shift->id)->lockForUpdate()->firstOrFail();
            if ($lockedShift->status !== 'open') {
                throw ValidationException::withMessages([
                    'shift' => ['لا يمكن دفع أجر موظف من وردية مغلقة.'],
                ]);
            }

            $group = $lockedShift->shift_group_id
                ? ShiftGroup::whereKey($lockedShift->shift_group_id)->lockForUpdate()->first()
                : null;
            $employees = $this->employeesForWages($lockedShift, $group);
            $employee = $employees->firstWhere('id', (int) $validated['employee_id']);
            if (! $employee
                || $employee->restaurant_id !== $lockedShift->restaurant_id
                || $employee->status !== 'active'
                || ! $employee->pay_auto
                || ! in_array($employee->pay_type, ['daily', 'percentage'], true)) {
                throw ValidationException::withMessages([
                    'employee_id' => ['الموظف غير مشارك في هذه الوردية أو لا يستحق أجراً تلقائياً.'],
                ]);
            }

            $workDate = Carbon::parse($lockedShift->shift_date)->toDateString();
            $reference = $this->wageReference($employee, $workDate, $lockedShift, $group);
            if ($employee->pivot->paid_at) {
                throw ValidationException::withMessages([
                    'employee_id' => ['تم دفع أجر هذا الموظف مسبقاً.'],
                ]);
            }

            $amount = $employee->pay_type === 'daily'
                ? (float) $employee->pay_rate
                : $this->wageRevenue($lockedShift, $group) * ((float) $employee->pay_rate / 100);
            $amount = round($amount, 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'employee_id' => ['قيمة أجر الموظف تساوي صفراً ولا يمكن إنشاء قيد.'],
                ]);
            }

            $posting = app(JournalPostingService::class);
            $paymentAccount = $posting->assertConfiguredPaymentMethod(
                $lockedShift->restaurant_id,
                $validated['payment_method'],
            );
            $expenseAccountId = $employee->pay_account_id ?: $employee->user?->pay_account_id;
            $expenseAccount = $expenseAccountId
                ? Account::where('restaurant_id', $lockedShift->restaurant_id)
                    ->whereKey($expenseAccountId)
                    ->where('type', 'expense')
                    ->where('is_active', true)
                    ->first()
                : Account::firstOrCreate(
                    ['restaurant_id' => $lockedShift->restaurant_id, 'code' => '5000'],
                    ['name' => 'رواتب وأجور', 'type' => 'expense', 'opening_balance' => 0, 'is_active' => true],
                );
            if (! $expenseAccount) {
                throw ValidationException::withMessages([
                    'pay_account_id' => ['حساب مصروف الموظف غير صالح أو غير نشط.'],
                ]);
            }

            $entry = $posting->post(
                $lockedShift->restaurant_id,
                'WG-',
                $reference,
                $workDate,
                'أجر '.$employee->name.' - وردية #'.$lockedShift->id,
                $request->user()->id,
                [
                    ['account_id' => $expenseAccount->id, 'debit' => $amount, 'description' => $employee->pay_type],
                    ['account_id' => $paymentAccount->id, 'credit' => $amount, 'description' => 'دفع الأجر عبر '.$validated['payment_method']],
                ],
            );

            $relation = $group ? $group->employees() : $lockedShift->employees();
            if (! $group && ! $lockedShift->employees()->whereKey($employee->id)->exists()) {
                $relation->attach($employee->id);
            }
            $relation->updateExistingPivot($employee->id, [
                'payment_method' => $validated['payment_method'],
                'paid_at' => now(),
                'paid_amount' => $amount,
                'journal_entry_id' => $entry->id,
                'paid_by' => $request->user()->id,
            ]);

            return $entry;
        });

        return response()->json([
            'journal_entry' => $entry,
            'message' => 'تم دفع أجر الموظف وإنشاء القيد المحاسبي',
        ]);
    }

    private function wageReference(Employee $employee, string $workDate, Shift $shift, ?ShiftGroup $group): string
    {
        return $employee->pay_type === 'daily'
            ? 'WAGE-'.$employee->id.'-'.$workDate
            : 'WAGE-'.$employee->id.'-'.($group ? 'GROUP-'.$group->id : $shift->id);
    }

    private function employeesForWages(Shift $shift, ?ShiftGroup $group)
    {
        $employees = $group
            ? $group->employees()->with('user')->get()
            : $shift->employees()->with('user')->get();

        if (! $group && $shift->employee_id && ! $employees->contains('id', $shift->employee_id)) {
            $employee = $shift->employee()->with('user')->first();
            if ($employee) {
                $employee->setRelation('pivot', (object) [
                    'payment_method' => null,
                    'paid_at' => null,
                    'paid_amount' => null,
                    'journal_entry_id' => null,
                    'paid_by' => null,
                ]);
                $employees->push($employee);
            }
        }

        return $employees;
    }

    private function wageRevenue(Shift $shift, ?ShiftGroup $group): float
    {
        $shiftIds = $group ? $group->shifts()->pluck('id') : collect([$shift->id]);
        $orders = Order::whereIn('shift_id', $shiftIds)
            ->where(function ($query) {
                $query->where('payment_status', 'paid')->orWhere('status', 'completed');
            })
            ->get();
        $payments = Payment::where('paymentable_type', Order::class)
            ->whereIn('paymentable_id', $orders->pluck('id'))
            ->get();

        return (float) ($payments->isNotEmpty()
            ? $payments->sum('amount')
            : $orders->sum('total_amount'));
    }

    private function userHasShiftPermission(User $user, string $permission): bool
    {
        return Permission::where('name', $permission)
            ->where('guard_name', 'sanctum')
            ->exists()
            && $user->hasPermissionTo($permission, 'sanctum');
    }

    public function current(Request $request)
    {
        $user = $request->user();
        $restaurantId = $user->restaurant_id;
        $shift = Shift::where('restaurant_id', $restaurantId)
            ->where('user_id', $user->id)
            ->where('status', 'open')
            ->latest('id')
            ->with('employees', 'employee')
            ->first();

        if (! $shift && $user->hasPermissionTo('open_shift', 'sanctum')) {
            $shift = DB::transaction(function () use ($user, $restaurantId) {
                $group = ShiftGroup::where('restaurant_id', $restaurantId)
                    ->where('status', 'open')
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();
                if (! $group) {
                    return null;
                }

                $existing = Shift::where('shift_group_id', $group->id)
                    ->where('user_id', $user->id)
                    ->where('status', 'open')
                    ->first();
                if ($existing) {
                    return $existing;
                }

                return Shift::create([
                    'restaurant_id' => $restaurantId,
                    'shift_group_id' => $group->id,
                    'user_id' => $user->id,
                    'name' => $group->name,
                    'shift_date' => $group->shift_date,
                    'start_time' => now()->format('H:i:s'),
                    'end_time' => '23:59:00',
                    'status' => 'open',
                    'opening_cash' => 0,
                    'expected_cash' => 0,
                    'actual_cash' => 0,
                    'difference' => 0,
                ]);
            });
        }

        if (! $shift) {
            if (! $user->hasPermissionTo('view_pos', 'sanctum')) {
                return response()->json([
                    'shift' => null,
                    'revenue' => null,
                    'orders_count' => null,
                ]);
            }

            $orders = Order::where('restaurant_id', $restaurantId)
                ->where('cashier_id', $user->id)
                ->whereDate('created_at', Carbon::today())
                ->where(function ($query) {
                    $query->where('payment_status', 'paid')->orWhere('status', 'completed');
                })
                ->get();
            $orderIds = $orders->pluck('id');
            $payments = Payment::where('paymentable_type', Order::class)
                ->whereIn('paymentable_id', $orderIds)
                ->get();
            $byPayment = [];
            foreach ($payments as $payment) {
                $method = $payment->method ?: 'cash';
                $byPayment[$method] = ($byPayment[$method] ?? 0) + (float) $payment->amount;
            }
            if (empty($byPayment)) {
                foreach ($orders as $order) {
                    $method = $order->payment_method ?: 'cash';
                    $byPayment[$method] = ($byPayment[$method] ?? 0) + (float) $order->total_amount;
                }
            }

            return response()->json([
                'shift' => null,
                'revenue' => [
                    'total' => round($orders->sum(fn ($order) => (float) $order->total_amount), 4),
                    'by_payment_method' => array_map(fn ($amount) => round($amount, 4), $byPayment),
                ],
                'orders_count' => $orders->count(),
            ]);
        }

        $ordersQuery = Order::where('restaurant_id', $restaurantId)
            ->where('shift_id', $shift->id)
            ->where(function ($q) use ($user) {
                $q->where('cashier_id', $user->id)->orWhereNull('cashier_id');
            });

        $orders = $ordersQuery->where(function ($q) {
            $q->where('payment_status', 'paid')->orWhere('status', 'completed');
        })->get();

        $total = $orders->sum(fn ($o) => (float) $o->total_amount);
        $orderIds = $orders->pluck('id');
        $payments = Payment::where('paymentable_type', Order::class)
            ->whereIn('paymentable_id', $orderIds)
            ->get();
        $byPayment = [];
        foreach ($payments as $payment) {
            $method = $payment->method ?: 'cash';
            $byPayment[$method] = ($byPayment[$method] ?? 0) + (float) $payment->amount;
        }
        if (empty($byPayment)) {
            foreach ($orders as $order) {
                $method = $order->payment_method ?: 'cash';
                $byPayment[$method] = ($byPayment[$method] ?? 0) + (float) $order->total_amount;
            }
        }
        $byPayment = array_map(fn ($v) => round($v, 4), $byPayment);

        $groupEmployees = $shift->group?->employees()->get();
        $hasOtherOpenShift = $shift->shift_group_id
            ? Shift::where('shift_group_id', $shift->shift_group_id)
                ->where('status', 'open')
                ->where('id', '!=', $shift->id)
                ->exists()
            : false;
        if ($groupEmployees) {
            $shift->setRelation('employees', $groupEmployees);
        }

        return response()->json([
            'shift' => $shift->load('user'),
            'wage_disbursement_due' => ! $hasOtherOpenShift,
            'revenue' => $user->hasPermissionTo('view_pos', 'sanctum') ? [
                'total' => round($total, 4),
                'by_payment_method' => $byPayment,
            ] : null,
            'orders_count' => $user->hasPermissionTo('view_pos', 'sanctum') ? $orders->count() : null,
        ]);
    }

    public function revenue(Request $request, Shift $shift)
    {
        abort_unless($shift->restaurant_id === $request->user()->restaurant_id, 403);
        abort_unless(
            $request->user()->hasPermissionTo('view_pos', 'sanctum')
                || $request->user()->hasPermissionTo('view_all_shifts', 'sanctum')
                || $request->user()->hasPermissionTo('close_shift', 'sanctum'),
            403
        );
        abort_unless(
            (int) $shift->user_id === (int) $request->user()->id
                || $request->user()->hasPermissionTo('view_all_shifts', 'sanctum'),
            403
        );

        $shifts = $shift->shift_group_id && $request->user()->hasPermissionTo('view_all_shifts', 'sanctum')
            ? $shift->group->shifts()->with('user')->get()
            : collect([$shift->load('user')]);

        $allOrders = collect();

        $cashiers = $shifts->map(function (Shift $cashierShift) use (&$allOrders) {
            $orders = Order::where('restaurant_id', $cashierShift->restaurant_id)
                ->where('shift_id', $cashierShift->id)
                ->where(function ($query) {
                    $query->where('payment_status', 'paid')->orWhere('status', 'completed');
                })
                ->with(['table', 'customer', 'items.product', 'payments'])
                ->orderBy('created_at')
                ->get();

            $byPayment = [];
            foreach ($orders as $order) {
                $orderMethods = [];
                foreach ($order->payments as $payment) {
                    $method = $payment->method ?: 'cash';
                    $byPayment[$method] = ($byPayment[$method] ?? 0) + (float) $payment->amount;
                    $orderMethods[$method] = ($orderMethods[$method] ?? 0) + (float) $payment->amount;
                }
                if ($order->payments->isEmpty()) {
                    $method = $order->payment_method ?: 'cash';
                    $byPayment[$method] = ($byPayment[$method] ?? 0) + (float) $order->total_amount;
                    $orderMethods[$method] = (float) $order->total_amount;
                }

                $allOrders->push([
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'type' => $order->type,
                    'status' => $order->status,
                    'payment_status' => $order->payment_status,
                    'table_name' => $order->table?->name,
                    'customer_name' => $order->customer?->name,
                    'cashier_name' => $cashierShift->user?->name,
                    'items_count' => (int) $order->items->sum('quantity'),
                    'items' => $order->items->map(fn ($item) => [
                        'name' => $item->product?->name ?: $item->name,
                        'quantity' => (int) $item->quantity,
                        'total_price' => (float) $item->total_price,
                    ])->values(),
                    'total_amount' => (float) $order->total_amount,
                    'amount_paid' => (float) $order->amount_paid,
                    'change_due' => (float) $order->change_due,
                    'payment_methods' => $orderMethods,
                    'created_at' => $order->created_at?->toISOString(),
                ]);
            }

            return [
                'shift_id' => $cashierShift->id,
                'user_id' => $cashierShift->user_id,
                'user_name' => $cashierShift->user?->name,
                'total' => round((float) array_sum($byPayment), 4),
                'orders_count' => $orders->count(),
                'by_payment_method' => array_map(fn ($amount) => round($amount, 4), $byPayment),
            ];
        })->values();

        $byPayment = [];
        foreach ($cashiers as $cashier) {
            foreach ($cashier['by_payment_method'] as $method => $amount) {
                $byPayment[$method] = ($byPayment[$method] ?? 0) + $amount;
            }
        }

        $shiftDates = $shifts->pluck('shift_date')
            ->filter()
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->unique()
            ->values();

        $expenses = Expense::where('restaurant_id', $shift->restaurant_id)
            ->whereIn(DB::raw('DATE(expense_date)'), $shiftDates->all())
            ->with('user')
            ->orderBy('expense_date')
            ->get()
            ->map(fn (Expense $expense) => [
                'id' => $expense->id,
                'category' => $expense->category,
                'description' => $expense->description,
                'amount' => (float) $expense->amount,
                'payment_method' => $expense->payment_method,
                'expense_date' => $expense->expense_date?->toDateString(),
                'user_name' => $expense->user?->name,
            ])->values();

        $ordersTotal = round((float) $cashiers->sum('total'), 4);
        $expensesTotal = round((float) $expenses->sum('amount'), 4);
        $baseShift = $shifts->firstWhere('id', $shift->id) ?: $shift;

        return response()->json([
            'total' => $ordersTotal,
            'by_payment_method' => array_map(fn ($v) => round($v, 4), $byPayment),
            'orders_count' => $allOrders->count(),
            'cashiers' => $cashiers,
            'orders' => $allOrders->values(),
            'expenses' => $expenses,
            'expenses_total' => $expensesTotal,
            'net_total' => round($ordersTotal - $expensesTotal, 4),
            'opening_cash' => (float) $baseShift->opening_cash,
            'expected_cash' => (float) $baseShift->expected_cash,
            'actual_cash' => (float) $baseShift->actual_cash,
            'difference' => (float) $baseShift->difference,
        ]);
    }

    public function store(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor->hasPermissionTo('open_shift', 'sanctum'), 403);
        $validated = $request->validate([
            'employee_ids' => 'sometimes|array',
            'employee_ids.*' => 'integer|distinct',
            'name' => 'required|string|max:100',
            'shift_date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'opening_cash' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $restaurantId = $actor->restaurant_id;
        $employeeIds = array_values(array_unique(array_map('intval', $validated['employee_ids'] ?? [])));
        if ($employeeIds && ! $actor->hasPermissionTo('manage_shift_employees', 'sanctum')) {
            abort(403);
        }
        if ($employeeIds) {
            $eligibleIds = Employee::where('restaurant_id', $restaurantId)
                ->where('status', 'active')
                ->where('pay_auto', true)
                ->whereIn('pay_type', ['daily', 'percentage'])
                ->whereIn('id', $employeeIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            if (count($eligibleIds) !== count($employeeIds)) {
                throw ValidationException::withMessages([
                    'employee_ids' => ['يمكن اختيار الموظفين النشطين ذوي الأجر اليومي أو النسبة فقط.'],
                ]);
            }
        }

        $result = DB::transaction(function () use ($validated, $employeeIds, $restaurantId, $actor) {
            $group = ShiftGroup::where('restaurant_id', $restaurantId)
                ->where('status', 'open')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($group) {
                if ($employeeIds) {
                    throw ValidationException::withMessages([
                        'employee_ids' => ['لا يمكن تغيير موظفي الوردية بعد فتحها.'],
                    ]);
                }
            } else {
                $group = ShiftGroup::create([
                    'restaurant_id' => $restaurantId,
                    'opened_by' => $actor->id,
                    'name' => $validated['name'],
                    'shift_date' => $validated['shift_date'],
                    'status' => 'open',
                ]);
                if ($employeeIds) {
                    $group->employees()->sync($employeeIds);
                }
            }

            $shift = Shift::where('shift_group_id', $group->id)
                ->where('user_id', $actor->id)
                ->where('status', 'open')
                ->first();
            if (! $shift) {
                $shift = Shift::create([
                    'restaurant_id' => $restaurantId,
                    'shift_group_id' => $group->id,
                    'user_id' => $actor->id,
                    'name' => $group->name,
                    'shift_date' => $group->shift_date,
                    'start_time' => Carbon::parse($validated['start_time'])->format('H:i:s'),
                    'end_time' => ! empty($validated['end_time'])
                        ? Carbon::parse($validated['end_time'])->format('H:i:s')
                        : '23:59:00',
                    'status' => 'open',
                    'opening_cash' => (float) ($validated['opening_cash'] ?? 0),
                    'expected_cash' => (float) ($validated['opening_cash'] ?? 0),
                    'actual_cash' => 0,
                    'difference' => 0,
                    'notes' => $validated['notes'] ?? null,
                ]);
            }

            $shift->setRelation('employees', $group->employees()->get());

            return ['group' => $group, 'shift' => $shift->load('user'), 'shifts' => collect([$shift])];
        });

        return response()->json([
            'group' => $result['group']->load('employees'),
            'shift' => $result['shift'],
            'shifts' => $result['shifts']->values(),
            'message' => 'تم فتح الوردية الجماعية، ويمكن لكل كاشير الانضمام من جهازه',
        ], 201);
    }

    public function show(Shift $shift)
    {
        $user = auth()->user();
        abort_unless($shift->restaurant_id === $user->restaurant_id, 403);
        abort_unless(
            (int) $shift->user_id === (int) $user->id
                || $user->hasPermissionTo('view_all_shifts', 'sanctum'),
            403
        );

        return response()->json(['shift' => $shift->load('user', 'employee', 'employees')]);
    }

    public function update(Request $request, Shift $shift)
    {
        abort_unless($shift->restaurant_id === $request->user()->restaurant_id, 403);
        abort_unless(
            (int) $shift->user_id === (int) $request->user()->id
                || $request->user()->hasPermissionTo('view_all_shifts', 'sanctum'),
            403
        );
        if ($request->input('status') === 'closed') {
            abort_unless($request->user()->hasPermissionTo('close_shift', 'sanctum'), 403);
        }
        $validated = $request->validate([
            'actual_cash' => 'sometimes|numeric|min:0',
            'status' => 'sometimes|in:open,closed',
            'notes' => 'sometimes|string',
            'end_time' => 'sometimes|date_format:H:i',
        ]);
        $journalCreated = false;
        if ($request->has('status') && $request->status === 'closed') {
            DB::transaction(function () use ($request, $shift, &$validated, &$journalCreated) {
                $lockedShift = Shift::whereKey($shift->id)->lockForUpdate()->firstOrFail();
                if ($lockedShift->status !== 'open') {
                    throw ValidationException::withMessages([
                        'status' => ['هذه الوردية مغلقة بالفعل.'],
                    ]);
                }
                $group = $lockedShift->shift_group_id
                    ? ShiftGroup::whereKey($lockedShift->shift_group_id)->lockForUpdate()->first()
                    : null;
                $hasOtherOpenShift = $group
                    ? Shift::where('shift_group_id', $group->id)
                        ->where('status', 'open')
                        ->where('id', '!=', $lockedShift->id)
                        ->exists()
                    : false;
                $cashOrders = Order::where('restaurant_id', $shift->restaurant_id)
                    ->where('shift_id', $shift->id)
                    ->where(function ($q) {
                        $q->where('payment_status', 'paid')->orWhere('status', 'completed');
                    })
                    ->get();
                $cashOrderIds = $cashOrders->pluck('id');
                $cashPayments = Payment::where('paymentable_type', Order::class)
                    ->whereIn('paymentable_id', $cashOrderIds)
                    ->get();
                $cashRevenue = $cashPayments->isNotEmpty()
                    ? (float) $cashPayments
                        ->filter(fn ($payment) => in_array(mb_strtolower(trim($payment->method ?: 'cash')), ['cash', 'نقد', 'نقدي', 'cashbox'], true))
                        ->sum('amount')
                    : (float) $cashOrders
                        ->filter(fn ($order) => in_array(mb_strtolower(trim($order->payment_method ?: 'cash')), ['cash', 'نقد', 'نقدي', 'cashbox'], true))
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

                $journalCreated = false;
                // Auto-create journal entry for shift revenue, linked to accounts
                $orders = Order::where('shift_id', $shift->id)
                    ->where(function ($q) {
                        $q->where('payment_status', 'paid')->orWhere('status', 'completed');
                    })->get();
                $orderIds = $orders->pluck('id');
                $payments = Payment::where('paymentable_type', Order::class)
                    ->whereIn('paymentable_id', $orderIds)->get();
                $byPayment = [];
                foreach ($payments as $p) {
                    $byPayment[$p->method ?: 'cash'] = ($byPayment[$p->method ?: 'cash'] ?? 0) + (float) $p->amount;
                }
                if (empty($byPayment)) {
                    foreach ($orders as $o) {
                        $byPayment[$o->payment_method ?: 'cash'] = ($byPayment[$o->payment_method ?: 'cash'] ?? 0) + (float) $o->total_amount;
                    }
                }

                $restaurantId = $shift->restaurant_id;
                $cashier = User::where('id', $shift->user_id)
                    ->where('restaurant_id', $restaurantId)
                    ->firstOrFail();
                app(CashierAccountService::class)->ensureFor($cashier);
                $vaultAccountId = $cashier->vault_account_id;
                $revenueAccount = Account::firstOrCreate(
                    ['restaurant_id' => $restaurantId, 'code' => '4000'],
                    ['name' => 'إيرادات المبيعات', 'type' => 'revenue', 'opening_balance' => 0, 'is_active' => true]
                );
                if ($revenueAccount->type !== 'revenue' || ! $revenueAccount->is_active) {
                    throw ValidationException::withMessages([
                        'account_id' => ['حساب إيرادات المبيعات غير صالح أو غير نشط.'],
                    ]);
                }

                $posting = app(JournalPostingService::class);
                $lines = [];
                $settlementLines = [];
                $totalRevenue = 0.0;
                foreach ($byPayment as $method => $amount) {
                    $amount = round((float) $amount, 2);
                    if ($amount <= 0) {
                        continue;
                    }
                    $methodAccount = $posting->paymentAccount($restaurantId, $method);
                    $lines[] = ['account_id' => $vaultAccountId, 'debit' => $amount, 'description' => 'تحصيل وردية في خزنة '.$cashier->name.' - '.$method];
                    $lines[] = ['account_id' => $revenueAccount->id, 'credit' => $amount, 'description' => 'إيراد مبيعات - '.$method];
                    $settlementLines[] = ['account_id' => $methodAccount->id, 'debit' => $amount, 'description' => 'توريد الوردية إلى '.$methodAccount->name];
                    $settlementLines[] = ['account_id' => $vaultAccountId, 'credit' => $amount, 'description' => 'تسليم من خزنة '.$cashier->name.' - '.$method];
                    $totalRevenue += (float) $amount;
                }
                if ($totalRevenue > 0) {
                    $posting->post(
                        $restaurantId,
                        'SH-',
                        'SHIFT-'.$shift->id,
                        Carbon::parse($shift->shift_date)->toDateString(),
                        'قيد إغلاق الوردية #'.$shift->id,
                        $request->user()->id,
                        array_merge($lines, $settlementLines)
                    );
                    $journalCreated = true;
                }

                if (isset($validated['end_time']) && is_string($validated['end_time']) && strlen($validated['end_time']) === 5) {
                    $validated['end_time'] .= ':00';
                }
                $lockedShift->update($validated);
                if ($group && ! $group->shifts()->where('status', 'open')->exists()) {
                    $group->update(['status' => 'closed']);
                }
            });

            return response()->json([
                'shift' => $shift->fresh()->load('user'),
                'message' => $journalCreated ? 'تم إغلاق الوردية وإنشاء قيد الوردية بنجاح' : 'تم إغلاق الوردية',
            ]);
        } elseif ($request->has('actual_cash')) {
            $expected = (float) ($shift->expected_cash ?? $shift->opening_cash ?? 0);
            $validated['difference'] = (float) $validated['actual_cash'] - $expected;
        }
        if (isset($validated['end_time']) && is_string($validated['end_time']) && strlen($validated['end_time']) === 5) {
            $validated['end_time'] = $validated['end_time'].':00';
        }
        $shift->update($validated);

        return response()->json(['shift' => $shift->fresh()->load('user'), 'message' => $journalCreated ? 'تم إغلاق الوردية وإنشاء قيد الوردية بنجاح' : 'تم إغلاق الوردية']);
    }

    public function destroy(Shift $shift)
    {
        $user = auth()->user();
        abort_unless($shift->restaurant_id === $user->restaurant_id, 403);
        abort_unless($user->hasPermissionTo('view_all_shifts', 'sanctum'), 403);
        $shift->delete();

        return response()->json(['message' => 'تم حذف الوردية']);
    }
}
