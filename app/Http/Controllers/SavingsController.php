<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\Member;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\TransactionService;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The teller window: deposits, withdrawals and loan payments,
 * plus a passbook-style statement for each member.
 */
class SavingsController extends Controller
{
    private const TELLER_TYPES = ['savings_deposit', 'savings_withdrawal', 'loan_payment'];

    public function index()
    {
        $user = auth()->user();
        $todayRange = [now()->startOfDay(), now()->endOfDay()];
        $today = fn (string $type) => (float) Transaction::completed()->where('type', $type)->whereBetween('posted_at', $todayRange)->sum('amount');

        // Which tabs this user may use
        $kinds = [];
        if ($user->canAccess('savings', 'manage')) {
            $kinds += ['savings_deposit' => 'Deposit', 'savings_withdrawal' => 'Withdrawal'];
        }
        if ($user->canAccess('receipts', 'manage')) {
            $kinds += ['loan_payment' => 'Loan Payment'];
        }

        // Savings balance of every active member
        $balances = [];
        $totals = Transaction::completed()->whereIn('type', ['savings_deposit', 'savings_withdrawal'])
            ->selectRaw('member_id, type, sum(amount) as total')->groupBy('member_id', 'type')->get();
        foreach ($totals as $row) {
            $balances[$row->member_id] = ($balances[$row->member_id] ?? 0) + ($row->type === 'savings_deposit' ? $row->total : -$row->total);
        }

        $members = Member::where('status', 'active')->orderBy('last_name')->orderBy('first_name')->get()
            ->each(fn ($m) => $m->savings = (float) ($balances[$m->id] ?? 0));

        return view('savings.index', [
            'kinds' => $kinds,
            'members' => $members,
            'options' => Member::options(),
            'methods' => config('coop.payment_methods'),
            'minBalance' => (float) Setting::get('min_balance'),
            'total' => (float) array_sum($balances),
            'todayDeposits' => $today('savings_deposit'),
            'todayWithdrawals' => $today('savings_withdrawal'),
            'todayPayments' => $today('loan_payment'),
            'recent' => Transaction::with(['member', 'user'])->whereIn('type', self::TELLER_TYPES)
                ->latest('posted_at')->latest('id')->limit(12)->get(),
        ]);
    }

    /** Account summary shown beside the form once a member is chosen. */
    public function account(Member $member)
    {
        $loans = Loan::where('member_id', $member->id)->where('status', 'released')->where('balance', '>', 0)->orderBy('id')->get()
            ->map(fn ($l) => [
                'id' => $l->id,
                'loan_no' => $l->loan_no,
                'balance' => (float) $l->balance,
                'payment' => (float) $l->monthly_amortization,
                'maturity' => $l->maturity_date?->format('M d, Y'),
                'past_due' => $l->isPastDue(),
            ]);

        $recent = $member->transactions()->completed()->whereIn('type', self::TELLER_TYPES)
            ->latest('posted_at')->latest('id')->limit(5)->get()
            ->map(fn ($t) => [
                'reference' => $t->reference_no,
                'label' => $t->label(),
                'amount' => $t->signedAmount(),
                'date' => $t->posted_at->format('M d, Y'),
                'balance_after' => $t->balance_after,
            ]);

        return response()->json([
            'name' => $member->full_name,
            'member_no' => $member->member_no,
            'status' => $member->status,
            'savings' => $member->savingsBalance(),
            'min_balance' => (float) Setting::get('min_balance'),
            'shares_paid' => $member->paidUpShares(),
            'shares_subscribed' => (float) $member->subscribed_shares,
            'loans' => $loans,
            'recent' => $recent,
            'statement_url' => route('savings.statement', $member),
        ]);
    }

    public function store(Request $request, TransactionService $service)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(self::TELLER_TYPES)],
            'member_id' => ['required', Tenant::exists('members')],
            'loan_id' => ['nullable', 'required_if:kind,loan_payment', Tenant::exists('loans')],
            'amount' => 'required|numeric|min:0.01|max:100000000',
            'payment_method' => ['nullable', Rule::in(array_keys(config('coop.payment_methods')))],
            'payment_ref' => 'nullable|string|max:60',
            'remarks' => 'nullable|string|max:255',
        ]);

        // Loan payments follow the Cash Receipts permission; deposits and withdrawals follow Savings
        $module = $data['kind'] === 'loan_payment' ? 'receipts' : 'savings';
        abort_unless($request->user()->canAccess($module, 'manage'), 403, 'Your role cannot post this kind of transaction.');

        $tx = $service->post([
            'type' => $data['kind'],
            'member_id' => $data['member_id'],
            'loan_id' => $data['kind'] === 'loan_payment' ? $data['loan_id'] : null,
            'amount' => $data['amount'],
            'payment_method' => $data['payment_method'] ?? 'cash',
            'payment_ref' => $data['payment_ref'] ?? null,
            'remarks' => $data['remarks'] ?? null,
        ]);

        return redirect()->route('transactions.receipt', $tx)->with('status', "{$tx->label()} posted: {$tx->reference_no}");
    }

    /** Passbook-style statement of a member's savings account. */
    public function statement(Request $request, Member $member)
    {
        $input = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $from = $input['from'] ?? now()->startOfYear()->toDateString();
        $to = $input['to'] ?? now()->toDateString();

        $base = $member->transactions()->completed()->whereIn('type', ['savings_deposit', 'savings_withdrawal']);

        $opening = (float) (clone $base)->where('posted_at', '<', $from.' 00:00:00')
            ->get()->sum(fn ($t) => $t->signedAmount());

        $rows = (clone $base)->whereBetween('posted_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->orderBy('posted_at')->orderBy('id')->get();

        $running = $opening;
        $lines = $rows->map(function ($t) use (&$running) {
            $running += $t->signedAmount();

            return ['tx' => $t, 'balance' => round($running, 2)];
        });

        return view('savings.statement', [
            'member' => $member,
            'from' => $from,
            'to' => $to,
            'opening' => $opening,
            'lines' => $lines,
            'deposits' => (float) $rows->where('type', 'savings_deposit')->sum('amount'),
            'withdrawals' => (float) $rows->where('type', 'savings_withdrawal')->sum('amount'),
            'closing' => round($running, 2),
        ]);
    }
}
