<?php

namespace App\Services;

use App\Models\Account;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class CashierAccountService
{
    public function ensurePayAccountFor(User $user): Account
    {
        $restaurantId = $user->restaurant_id;
        $suffix = str_pad((string) $user->id, 3, '0', STR_PAD_LEFT);
        $pay = $user->pay_account_id
            ? Account::where('restaurant_id', $restaurantId)->whereKey($user->pay_account_id)->first()
            : null;
        if ($user->pay_account_id && (! $pay || $pay->type !== 'expense' || ! $pay->is_active)) {
            throw ValidationException::withMessages([
                'pay_account_id' => ['يجب اختيار حساب مصروف أجور نشط من حسابات المطعم.'],
            ]);
        }
        if (! $pay) {
            $expenseAccount = Account::firstOrCreate(
                ['restaurant_id' => $restaurantId, 'code' => '5000'],
                ['name' => 'رواتب وأجور', 'type' => 'expense', 'opening_balance' => 0, 'is_active' => true]
            );
            if ($expenseAccount->type !== 'expense' || ! $expenseAccount->is_active) {
                throw ValidationException::withMessages([
                    'pay_account_id' => ['حساب الأجور الافتراضي غير صالح أو غير نشط.'],
                ]);
            }
            $pay = Account::firstOrCreate(
                ['restaurant_id' => $restaurantId, 'code' => '51'.$suffix],
                [
                    'parent_id' => $expenseAccount->id,
                    'name' => 'مصروف أجور '.$user->name,
                    'type' => 'expense',
                    'opening_balance' => 0,
                    'is_active' => true,
                ]
            );
        }

        if ($pay->type !== 'expense' || ! $pay->is_active) {
            throw ValidationException::withMessages([
                'pay_account_id' => ['يجب ربط المستخدم بحساب مصروف أجور نشط.'],
            ]);
        }

        if ((int) $user->pay_account_id !== (int) $pay->id) {
            $user->forceFill(['pay_account_id' => $pay->id])->save();
        }

        return $pay;
    }

    public function ensureFor(User $user): void
    {
        $restaurantId = $user->restaurant_id;
        $this->ensurePayAccountFor($user);
        $cashAccount = Account::firstOrCreate(
            ['restaurant_id' => $restaurantId, 'code' => '1000'],
            ['name' => 'الخزنة الرئيسية', 'type' => 'asset', 'opening_balance' => 0, 'is_active' => true]
        );
        if ($cashAccount->type !== 'asset' || ! $cashAccount->is_active) {
            throw ValidationException::withMessages([
                'vault_account_id' => ['حساب الخزينة الافتراضي غير صالح أو غير نشط.'],
            ]);
        }

        $suffix = str_pad((string) $user->id, 3, '0', STR_PAD_LEFT);
        $vault = $user->vault_account_id
            ? Account::where('restaurant_id', $restaurantId)->whereKey($user->vault_account_id)->first()
            : null;
        $vaultParent = $vault?->parent_id
            ? Account::where('restaurant_id', $restaurantId)
                ->whereKey($vault->parent_id)
                ->where('type', 'asset')
                ->where('is_active', true)
                ->first()
            : null;
        if ($user->vault_account_id && (! $vault || $vault->type !== 'asset' || ! $vault->is_active || ! $vaultParent)) {
            throw ValidationException::withMessages([
                'vault_account_id' => ['يجب ربط الكاشير بحساب خزينة فرعي ونشط من حسابات المطعم.'],
            ]);
        }
        $vault ??= Account::firstOrCreate(
            ['restaurant_id' => $restaurantId, 'code' => '11'.$suffix],
            [
                'parent_id' => $cashAccount->id,
                'name' => 'خزنة '.$user->name,
                'type' => 'asset',
                'opening_balance' => 0,
                'is_active' => true,
            ]
        );
        if ($vault->type !== 'asset' || ! $vault->is_active || ! $vault->parent_id) {
            throw ValidationException::withMessages([
                'vault_account_id' => ['يجب ربط الكاشير بحساب خزينة فرعي ونشط.'],
            ]);
        }

        if ((int) $user->vault_account_id !== (int) $vault->id) {
            $user->forceFill(['vault_account_id' => $vault->id])->save();
        }
    }

    public function isCashierRole(string $roleName): bool
    {
        return stripos($roleName, 'cashier') !== false || str_contains($roleName, 'كاشير');
    }
}
