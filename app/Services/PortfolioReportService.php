<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Transaction;
use Illuminate\Support\Carbon;

/**
 * Reports about the loan book and the member accounts behind the ledger's control accounts.
 */
class PortfolioReportService
{
    public function __construct(private LoanScheduleService $schedule, private AccountingService $accounting) {}

    // ------------------------------------------------------------------
    // Aging of the loan portfolio
    // ------------------------------------------------------------------

    /**
     * Group the released loans by how many days they are past due. The amount at stake is the balance
     * carried in the books (principal and fees still to collect). Today's date is used.
     *
     * Returns: buckets (key, label, count, balance, overdue, rate, allowance), loans (details), totals and 'par' (days => %).
     */
    public function aging(): array
    {
        $today = now()->startOfDay();
        $config = config('accounting.aging');

        $buckets = [];
        foreach ($config as $b) {
            $buckets[$b['key']] = $b + ['count' => 0, 'balance' => 0.0, 'overdue' => 0.0, 'allowance' => 0.0];
        }

        $details = [];
        foreach (Loan::with(['member', 'product'])->where('status', 'released')->orderBy('loan_no')->get() as $loan) {
            $a = $this->schedule->analyze($loan, $today);
            $overdueRows = collect($a['rows'])->where('overdue', true);
            $oldest = $overdueRows->first();
            $days = $oldest ? (int) max(1, abs($oldest['due']->diffInDays($today))) : 0;

            $book = round($loan->principalBalance() + $loan->feesBalance(), 2);
            $key = $this->bucketKey($days);
            $overdue = round($overdueRows->sum('shortfall'), 2);

            $buckets[$key]['count']++;
            $buckets[$key]['balance'] += $book;
            $buckets[$key]['overdue'] += $overdue;

            $details[] = [
                'loan' => $loan, 'days' => $days, 'bucket' => $buckets[$key]['label'], 'book' => $book,
                'overdue' => $overdue, 'penalty' => $a['penalty']['due'], 'installments' => $overdueRows->count(),
            ];
        }

        foreach ($buckets as $key => &$bucket) {
            $bucket['balance'] = round($bucket['balance'], 2);
            $bucket['overdue'] = round($bucket['overdue'], 2);
            $bucket['allowance'] = round($bucket['balance'] * $bucket['rate'] / 100, 2);
        }
        unset($bucket);

        $total = round(collect($buckets)->sum('balance'), 2);
        $atRisk = fn (int $days) => $total > 0 ? round(collect($buckets)->filter(fn ($b) => $b['from'] > $days)->sum('balance') / $total * 100, 1) : 0.0;

        return [
            'buckets' => array_values($buckets),
            'loans' => collect($details)->sortByDesc('days')->values()->all(),
            'total_balance' => $total,
            'total_count' => collect($buckets)->sum('count'),
            'total_overdue' => round(collect($buckets)->sum('overdue'), 2),
            'total_allowance' => round(collect($buckets)->sum('allowance'), 2),
            // portfolio at risk: share of the book more than N days past due
            'par' => [0 => $atRisk(0), 30 => $atRisk(30), 90 => $atRisk(90)],
            'as_of' => $today->toDateString(),
        ];
    }

    private function bucketKey(int $days): string
    {
        foreach (config('accounting.aging') as $b) {
            if ($days >= $b['from'] && ($b['to'] === null || $days <= $b['to'])) {
                return $b['key'];
            }
        }

        return 'current';
    }

    // ------------------------------------------------------------------
    // Subsidiary schedules (the detail behind the control accounts)
    // ------------------------------------------------------------------

    /** Each schedule lists the member balances and compares their total with the ledger. */
    public function schedules(): array
    {
        $today = now()->toDateString();
        $totals = $this->accounting->totals(null, $today);
        $gl = function (string $code) use ($totals) {
            $account = Account::where('code', $code)->first();

            return $account ? $account->balanceOf($totals[$account->id]['debit'] ?? 0, $totals[$account->id]['credit'] ?? 0) : 0.0;
        };

        // Loans receivable: principal and fees still to collect, loan by loan
        $loanRows = Loan::with(['member', 'product'])->where('status', 'released')->orderBy('loan_no')->get()->map(fn ($l) => [
            'ref' => $l->loan_no, 'name' => $l->member->full_name, 'extra' => $l->product?->name,
            'amount' => round($l->principalBalance() + $l->feesBalance(), 2), 'note' => 'Interest to come: ₱'.number_format($l->interestBalance(), 2),
        ])->all();

        // Savings and share capital, member by member
        $savings = [];
        foreach (Transaction::completed()->whereIn('type', ['savings_deposit', 'savings_withdrawal'])->selectRaw('member_id, type, sum(amount) as t')->groupBy('member_id', 'type')->get() as $r) {
            $savings[$r->member_id] = ($savings[$r->member_id] ?? 0) + ($r->type === 'savings_deposit' ? $r->t : -$r->t);
        }
        $shares = Transaction::shareBalances();

        $members = Member::orderBy('last_name')->orderBy('first_name')->get();
        $rowsFor = fn (array $balances) => $members->map(fn ($m) => [
            'ref' => $m->member_no, 'name' => $m->full_name, 'extra' => ucfirst($m->status), 'amount' => round((float) ($balances[$m->id] ?? 0), 2), 'note' => null,
        ])->filter(fn ($r) => abs($r['amount']) >= 0.005)->values()->all();

        $schedule = function (string $title, string $code, array $rows) use ($gl) {
            $sum = round(collect($rows)->sum('amount'), 2);
            $control = $gl($code);

            return ['title' => $title, 'code' => $code, 'rows' => $rows, 'total' => $sum, 'control' => $control, 'difference' => round($control - $sum, 2), 'agrees' => abs($control - $sum) < 0.005];
        };

        return [
            'loans' => $schedule('Loans receivable by borrower', '1020', $loanRows),
            'savings' => $schedule('Member savings deposits', '2010', $rowsFor($savings)),
            'shares' => $schedule('Share capital by member', '3010', $rowsFor($shares)),
            'as_of' => Carbon::parse($today)->toDateString(),
        ];
    }
}
