<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $query = Expense::where('restaurant_id', $restaurantId)->with('user');

        if ($request->has('category')) {
            $query->where('category', $request->category);
        }
        if ($request->has('from')) {
            $query->where('expense_date', '>=', $request->from);
        }
        if ($request->has('to')) {
            $query->where('expense_date', '<=', $request->to);
        }

        return response()->json($query->orderByDesc('expense_date')->paginate(15));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'category' => 'required|string|max:100',
            'amount' => 'required|numeric|min:0',
            'expense_date' => 'required|date',
            'description' => 'nullable|string',
            'receipt' => 'nullable|string',
            'payment_method' => 'nullable|string|max:50',
            'is_approved' => 'sometimes|boolean',
        ]);
        $validated['restaurant_id'] = $request->user()->restaurant_id;
        $validated['user_id'] = $request->user()->id;
        $validated['is_approved'] = $request->boolean('is_approved', false);
        $expense = Expense::create($validated);
        return response()->json(['expense' => $expense->load('user'), 'message' => 'تم إضافة المصروف'], 201);
    }

    public function show(Expense $expense)
    {
        if ($expense->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        return response()->json(['expense' => $expense->load('user')]);
    }

    public function update(Request $request, Expense $expense)
    {
        if ($expense->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $validated = $request->validate([
            'amount' => 'sometimes|numeric|min:0',
            'is_approved' => 'sometimes|boolean',
            'approved_by' => 'sometimes|exists:users,id',
        ]);
        $expense->update($validated);
        return response()->json(['expense' => $expense->fresh(), 'message' => 'تم تحديث المصروف']);
    }

    public function destroy(Expense $expense)
    {
        if ($expense->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $expense->delete();
        return response()->json(['message' => 'تم حذف المصروف']);
    }
}

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $payments = Payment::where('restaurant_id', $restaurantId)
            ->with('user')
            ->orderByDesc('paid_at')
            ->paginate(15);
        return response()->json($payments);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'paymentable_type' => 'required|string|max:100',
            'paymentable_id' => 'required|integer',
            'amount' => 'required|numeric|min:0',
            'method' => 'required|in:cash,card,wallet,bank_transfer,cheque',
            'reference' => 'nullable|string',
            'transaction_id' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);
        $validated['restaurant_id'] = $request->user()->restaurant_id;
        $validated['user_id'] = $request->user()->id;
        $validated['paid_at'] = now();
        $payment = Payment::create($validated);
        return response()->json(['payment' => $payment->load('user'), 'message' => 'تم تسجيل الدفعة'], 201);
    }

    public function show(Payment $payment)
    {
        if ($payment->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        return response()->json(['payment' => $payment->load('user')]);
    }

    public function destroy(Payment $payment)
    {
        if ($payment->restaurant_id !== auth()->user()->restaurant_id) {
            abort(403);
        }
        $payment->delete();
        return response()->json(['message' => 'تم حذف الدفعة']);
    }
}
