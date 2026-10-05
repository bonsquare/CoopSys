<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Member;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoanService
{
    public function __construct(private TransactionService $transactions, private LoanCalculator $calculator) {}

    public function apply(Member $member, LoanProduct $product, float $principal, int $term, ?string $purpose): Loan
    {
        if ($member->status !== 'active') {
            throw ValidationException::withMessages(['member_id' => 'This member is inactive.']);
        }
        if ($member->savingsBalance() < $product->min_savings) {
            throw ValidationException::withMessages(['member_id' => 'This product needs at least ₱'.number_format($product->min_savings, 2).' in savings. The member has ₱'.number_format($member->savingsBalance(), 2).'.']);
        }
        if ($member->paidUpShares() < $product->min_shares) {
            throw ValidationException::withMessages(['member_id' => 'This product needs at least ₱'.number_format($product->min_shares, 2).' paid-up share capital. The member has ₱'.number_format($member->paidUpShares(), 2).'.']);
        }

        $calc = $this->calculator->compute($product->loadMissing('deductions'), $principal, $term);

        $loan = Loan::create([
            'loan_no' => Loan::nextNumber(),
            'member_id' => $member->id,
            'loan_product_id' => $product->id,
            'principal' => $principal,
            'interest_rate' => $product->interest_rate,
            'term_months' => $this->calculator->termInMonths($term, $product->term_unit),
            'term_value' => $term,
            'term_unit' => $product->term_unit,
            'payment_frequency' => $calc['frequency'],
            'number_of_payments' => $calc['payments'],
            'total_interest' => $calc['interest'],
            'total_payable' => $calc['total_payable'],
            'monthly_amortization' => $calc['payment_amount'],   // payment per schedule
            'net_proceeds' => $calc['net_proceeds'],
            'deductions_total' => $calc['total_deductions'],
            'fees_added' => $calc['fees_payable'],
            'schedule' => $calc['rows'],
            'purpose' => $purpose,
            'status' => 'pending',
            'applied_at' => now()->toDateString(),
            'user_id' => auth()->id(),
        ]);

        AuditLog::record('loan.applied', "{$loan->loan_no} for {$member->full_name}");

        return $loan;
    }

    public function approve(Loan $loan): void
    {
        $this->requireStatus($loan, 'pending');
        $loan->update(['status' => 'approved', 'approved_at' => now()->toDateString()]);
        AuditLog::record('loan.approved', $loan->loan_no);
    }

    public function reject(Loan $loan): void
    {
        $this->requireStatus($loan, 'pending');
        $loan->update(['status' => 'rejected']);
        AuditLog::record('loan.rejected', $loan->loan_no);
    }

    /** Hand the money to the member: posts a cash disbursement and starts the loan. */
    public function release(Loan $loan): void
    {
        $this->requireStatus($loan, 'approved');

        DB::transaction(function () use ($loan) {
            $this->transactions->post([
                'type' => 'loan_release',
                'amount' => $loan->net_proceeds ?? $loan->principal, // cash handed over, after deductions
                'member_id' => $loan->member_id,
                'loan_id' => $loan->id,
                'payment_method' => $loan->product?->release_method ?? 'cash',
                'remarks' => 'Release of '.$loan->loan_no,
            ]);

            $loan->update([
                'status' => 'released',
                'released_at' => now()->toDateString(),
                'maturity_date' => $this->calculator->maturity(now(), $loan->term_value ?? $loan->term_months, $loan->term_unit ?? 'months')->toDateString(),
                'balance' => $loan->total_payable,
            ]);

            AuditLog::record('loan.released', $loan->loan_no);
        });
    }

    private function requireStatus(Loan $loan, string $status): void
    {
        if ($loan->status !== $status) {
            throw ValidationException::withMessages(['loan' => "This loan is {$loan->status}; it must be $status for this action."]);
        }
    }
}
