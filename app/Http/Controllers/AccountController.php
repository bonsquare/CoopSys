<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AuditLog;
use App\Services\AccountingService;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The chart of accounts. */
class AccountController extends Controller
{
    public function index(Request $request, AccountingService $accounting)
    {
        $type = $request->query('type');
        $totals = $accounting->totals(null, now()->toDateString());

        $accounts = Account::withCount('lines')
            ->when(array_key_exists($type ?? '', Account::TYPES), fn ($q) => $q->where('type', $type))
            ->orderBy('code')->get()
            ->each(function ($a) use ($totals) {
                $a->balance = $a->balanceOf($totals[$a->id]['debit'] ?? 0, $totals[$a->id]['credit'] ?? 0);
            });

        return view('accounting.accounts.index', ['accounts' => $accounts, 'type' => $type]);
    }

    public function create()
    {
        return view('accounting.accounts.form', ['account' => new Account(['type' => 'asset', 'is_active' => true])]);
    }

    public function store(Request $request)
    {
        $account = Account::create($request->validate($this->rules()) + ['is_active' => true, 'is_system' => false]);
        AuditLog::record('account.created', $account->label());

        return redirect()->route('accounting.accounts.index')->with('status', "Account {$account->label()} added.");
    }

    public function edit(Account $account)
    {
        return view('accounting.accounts.form', ['account' => $account->loadCount('lines')]);
    }

    public function update(Request $request, Account $account)
    {
        $data = $request->validate($this->rules($account));

        // The code and type of an account the system uses, or one that already has entries, cannot change
        if ($account->is_system || $account->lines()->exists()) {
            unset($data['code'], $data['type']);
        }

        $account->update($data);
        AuditLog::record('account.updated', $account->label());

        return redirect()->route('accounting.accounts.index')->with('status', "Account {$account->label()} updated.");
    }

    public function toggle(Account $account, AccountingService $accounting)
    {
        if ($account->is_active) {
            if ($account->is_system) {
                return back()->withErrors(['account' => 'This account is used for automatic postings and cannot be deactivated.']);
            }
            $totals = $accounting->totals(null, now()->addYear()->toDateString())[$account->id] ?? null;
            if ($totals && abs($account->balanceOf($totals['debit'], $totals['credit'])) > 0.004) {
                return back()->withErrors(['account' => 'This account still has a balance. Clear it with a journal entry first.']);
            }
        }

        $account->update(['is_active' => ! $account->is_active]);
        AuditLog::record('account.status', $account->label().($account->is_active ? ' activated' : ' deactivated'));

        return back()->with('status', "{$account->name} is now ".($account->is_active ? 'active' : 'inactive').'.');
    }

    public function destroy(Account $account)
    {
        if ($account->is_system) {
            return back()->withErrors(['account' => 'This account is used for automatic postings and cannot be deleted.']);
        }
        if ($account->lines()->exists()) {
            return back()->withErrors(['account' => 'This account has journal entries, so it cannot be deleted. Deactivate it instead.']);
        }

        $account->delete();
        AuditLog::record('account.deleted', $account->label());

        return redirect()->route('accounting.accounts.index')->with('status', 'Account deleted.');
    }

    private function rules(?Account $account = null): array
    {
        return [
            'code' => ['required', 'regex:/^[A-Za-z0-9\-\.]{2,12}$/', Tenant::unique('accounts', 'code', $account?->id)],
            'name' => 'required|string|max:100',
            'type' => ['required', Rule::in(array_keys(Account::TYPES))],
            'description' => 'nullable|string|max:255',
            'report_group' => 'nullable|string|max:60',
        ];
    }
}
