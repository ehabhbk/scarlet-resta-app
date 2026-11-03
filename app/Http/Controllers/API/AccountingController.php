<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountingController extends Controller
{
    private function balanceFor(Account $account, ?string $from = null, ?string $to = null): float
    {
        $q = JournalEntryLine::where('account_id', $account->id)
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.restaurant_id', $account->restaurant_id)
            ->whereNull('journal_entries.deleted_at');
        if ($from) $q->where('journal_entries.entry_date', '>=', $from);
        if ($to) $q->where('journal_entries.entry_date', '<=', $to);
        $debit = (float) $q->sum('journal_entry_lines.debit');
        $credit = (float) (clone $q)->sum('journal_entry_lines.credit');
        $net = $debit - $credit;
        // Assets & expenses: debit increases; liabilities/equity/revenue: credit increases
        if (in_array($account->type, ['asset', 'expense'])) {
            return (float) $account->opening_balance + $net;
        }
        return (float) $account->opening_balance - $net;
    }

    public function chartOfAccounts(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $accounts = Account::where('restaurant_id', $restaurantId)->with('parent')->orderBy('code')->get();
        $from = $request->get('from');
        $to = $request->get('to');
        $data = $accounts->map(function (Account $a) use ($from, $to) {
            return [
                'id' => $a->id,
                'parent_id' => $a->parent_id,
                'code' => $a->code,
                'name' => $a->name,
                'type' => $a->type,
                'is_active' => $a->is_active,
                'balance' => $this->balanceFor($a, $from, $to),
            ];
        });
        return response()->json(['accounts' => $data]);
    }

    public function ledger(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $accountId = $request->get('account_id');
        if (!$accountId) {
            return response()->json(['message' => 'account_id مطلوب'], 422);
        }
        $account = Account::where('id', $accountId)->where('restaurant_id', $restaurantId)->firstOrFail();
        $from = $request->get('from');
        $to = $request->get('to');

        $q = JournalEntryLine::where('account_id', $account->id)
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.restaurant_id', $restaurantId)
            ->whereNull('journal_entries.deleted_at')
            ->select('journal_entry_lines.*', 'journal_entries.entry_number', 'journal_entries.entry_date', 'journal_entries.description as entry_description', 'journal_entries.reference')
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entry_lines.id');
        if ($from) $q->where('journal_entries.entry_date', '>=', $from);
        if ($to) $q->where('journal_entries.entry_date', '<=', $to);

        $lines = $q->get();
        $running = (float) $account->opening_balance;
        $rows = [];
        foreach ($lines as $line) {
            if (in_array($account->type, ['asset', 'expense'])) {
                $running += (float) $line->debit - (float) $line->credit;
            } else {
                $running += (float) $line->credit - (float) $line->debit;
            }
            $rows[] = [
                'date' => $line->entry_date,
                'entry_number' => $line->entry_number,
                'reference' => $line->reference,
                'description' => $line->description ?: $line->entry_description,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'balance' => $running,
            ];
        }

        return response()->json([
            'account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'type' => $account->type, 'opening_balance' => (float) $account->opening_balance],
            'opening_balance' => (float) $account->opening_balance,
            'lines' => $rows,
            'closing_balance' => $running,
        ]);
    }

    public function incomeStatement(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $from = $request->get('from');
        $to = $request->get('to');

        $revenues = Account::where('restaurant_id', $restaurantId)->where('type', 'revenue')->where('is_active', true)->orderBy('code')->get();
        $expenses = Account::where('restaurant_id', $restaurantId)->where('type', 'expense')->where('is_active', true)->orderBy('code')->get();

        $revRows = $revenues->map(fn (Account $a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'amount' => $this->balanceFor($a, $from, $to)]);
        $expRows = $expenses->map(fn (Account $a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'amount' => $this->balanceFor($a, $from, $to)]);

        $totalRevenue = (float) $revRows->sum('amount');
        $totalExpense = (float) $expRows->sum('amount');

        return response()->json([
            'revenue' => $revRows,
            'expense' => $expRows,
            'total_revenue' => $totalRevenue,
            'total_expense' => $totalExpense,
            'net_income' => $totalRevenue - $totalExpense,
        ]);
    }

    public function balanceSheet(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $asOf = $request->get('as_of', now()->toDateString());

        $assets = Account::where('restaurant_id', $restaurantId)->where('type', 'asset')->where('is_active', true)->orderBy('code')->get();
        $liabilities = Account::where('restaurant_id', $restaurantId)->where('type', 'liability')->where('is_active', true)->orderBy('code')->get();
        $equity = Account::where('restaurant_id', $restaurantId)->where('type', 'equity')->where('is_active', true)->orderBy('code')->get();

        $assetRows = $assets->map(fn (Account $a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'amount' => $this->balanceFor($a, null, $asOf)]);
        $liabRows = $liabilities->map(fn (Account $a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'amount' => $this->balanceFor($a, null, $asOf)]);
        $equityRows = $equity->map(fn (Account $a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'amount' => $this->balanceFor($a, null, $asOf)]);

        $totalAssets = (float) $assetRows->sum('amount');
        $totalLiabilities = (float) $liabRows->sum('amount');
        $totalEquity = (float) $equityRows->sum('amount');

        return response()->json([
            'as_of' => $asOf,
            'assets' => $assetRows,
            'liabilities' => $liabRows,
            'equity' => $equityRows,
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'total_equity' => $totalEquity,
            'total_liabilities_equity' => $totalLiabilities + $totalEquity,
        ]);
    }
}