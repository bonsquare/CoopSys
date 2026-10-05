<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Setting;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every money movement in CoopSys goes through here, so that
 * balances, loan balances and the accounting journal always agree.
 */
class TransactionService
{
    // Chart of accounts codes (see DatabaseSeeder::chartOfAccounts)
    private const CASH = '1010';

    private const BANK = '1011';

    private const LOANS = '1020';

    private const OTHER_RECEIVABLE = '1030';

    private const SAVINGS = '2010';

    private const INSURANCE_PAYABLE = '2020';

    private const SHARES = '3010';

    private const INTEREST = '4010';

    private const OTHER_INCOME = '4020';

    private const PENALTY_INCOME = '4030';

    private const EXPENSE = '5010';

    /** Types that always belong to a member. */
    private const MEMBER_TYPES = [
        'share_deposit', 'share_withdrawal', 'savings_deposit', 'savings_withdrawal', 'loan_payment', 'loan_release',
        'loan_principal', 'loan_interest', 'loan_penalty', 'loan_fees', 'membership_fee', 'insurance',
    ];

    public function __construct(private LoanScheduleService $schedule, private AccountingService $accounting) {}

    /**
     * Record a transaction.
     * $data: type, amount, member_id?, loan_id?, remarks?, posted_at?, payment_method?, payment_ref?,
     *        reference_no? (to use instead of an automatic number), collection_id? (the receipt it belongs to)
     */
    public function post(array $data): Transaction
    {
        $type = $data['type'];
        $meta = Transaction::TYPES[$type] ?? null;
        $amount = round((float) $data['amount'], 2);

        if (! $meta) {
            $this->fail('type', 'Unknown transaction type.');
        }
        if ($amount <= 0) {
            $this->fail('amount', 'Amount must be greater than zero.');
        }

        $member = ! empty($data['member_id']) ? Member::find($data['member_id']) : null;
        $loan = ! empty($data['loan_id']) ? Loan::find($data['loan_id']) : null;

        if (in_array($type, self::MEMBER_TYPES) && ! $member) {
            $this->fail('member_id', 'Please choose a member.');
        }
        // Inactive members cannot transact, except to take their share capital out when policy allows it
        if ($member && $member->status !== 'active' && $type !== 'share_withdrawal') {
            $this->fail('member_id', 'This member is inactive.');
        }

        $this->accounting->assertOpen($data['posted_at'] ?? now());
        $this->checkRules($type, $amount, $member, $loan);

        return DB::transaction(function () use ($data, $type, $meta, $amount, $member, $loan) {
            $tx = Transaction::create([
                'reference_no' => $data['reference_no'] ?? $this->nextReference($meta[2]),
                'collection_id' => $data['collection_id'] ?? null,
                'member_id' => $member?->id,
                'loan_id' => $loan?->id,
                'type' => $type,
                'direction' => $meta[1],
                'amount' => $amount,
                'status' => 'completed',
                'remarks' => $data['remarks'] ?? null,
                'payment_method' => $data['payment_method'] ?? 'cash',
                'payment_ref' => $data['payment_ref'] ?? null,
                'posted_at' => $data['posted_at'] ?? now(),
                'user_id' => auth()->id(),
            ]);

            $this->journalize($tx, $loan);

            $balanceAfter = match ($type) {
                'savings_deposit', 'savings_withdrawal' => $member->savingsBalance(),
                'share_deposit', 'share_withdrawal' => $member->paidUpShares(),
                'loan_payment', 'loan_principal', 'loan_interest', 'loan_fees' => $this->applyToLoan($tx, $loan),
                'loan_penalty' => $this->applyToLoan($tx, $loan),
                default => null,
            };

            // Running balance of the account this transaction touched (used on slips and statements)
            $tx->update(['balance_after' => $balanceAfter]);

            AuditLog::record('transaction.posted', "{$tx->reference_no} {$tx->label()} ".number_format($amount, 2));

            return $tx;
        });
    }

    /**
     * Cancel a transaction and reverse its accounting entry.
     * A transaction that belongs to a receipt can only be reversed by cancelling that receipt.
     */
    public function void(Transaction $tx, string $reason, bool $fromReceipt = false): void
    {
        if ($tx->status !== 'completed') {
            $this->fail('transaction', 'This transaction is already voided.');
        }
        if ($tx->collection_id && ! $fromReceipt) {
            $this->fail('transaction', 'This transaction is part of a collection receipt. Cancel the receipt instead.');
        }
        if ($tx->type === 'loan_release') {
            $this->fail('transaction', 'Loan releases cannot be voided.');
        }
        if ($tx->type === 'savings_deposit' && $tx->member && $tx->member->savingsBalance() < $tx->amount) {
            $this->fail('transaction', 'Cannot reverse: the member has already withdrawn this money.');
        }
        if ($tx->type === 'share_deposit' && $tx->member && $tx->member->paidUpShares() < $tx->amount) {
            $this->fail('transaction', 'Cannot reverse: this share capital has already been withdrawn.');
        }

        DB::transaction(function () use ($tx, $reason) {
            $original = $tx->journalEntry()->with('lines')->first();
            if ($original) {
                $reverse = JournalEntry::create([
                    'entry_date' => now()->toDateString(),
                    'reference' => $tx->reference_no,
                    'description' => 'VOID: '.$reason,
                    'transaction_id' => null,
                ]);
                foreach ($original->lines as $line) {
                    $reverse->lines()->create([
                        'account_id' => $line->account_id,
                        'debit' => $line->credit,
                        'credit' => $line->debit,
                    ]);
                }
            }

            $this->undoOnLoan($tx);

            $tx->update(['status' => 'voided', 'remarks' => trim(($tx->remarks ?? '').' [VOID: '.$reason.']')]);
            AuditLog::record('transaction.voided', "{$tx->reference_no}: $reason");
        });
    }

    private function checkRules(string $type, float $amount, ?Member $member, ?Loan $loan): void
    {
        if ($type === 'share_deposit') {
            if ($member->subscribed_shares <= 0) {
                $this->fail('amount', 'This member has no share subscription yet. Set one on the Share Capital page first.');
            }
            $left = $member->subscribed_shares - $member->paidUpShares();
            if ($amount > $left + 0.001) {
                $this->fail('amount', 'Amount is more than the unpaid subscription ('.number_format($left, 2).').');
            }
        }

        if ($type === 'share_withdrawal') {
            $policy = Setting::get('share_withdrawal_policy') ?: 'not_allowed';
            if ($policy === 'not_allowed') {
                $this->fail('amount', 'Share capital withdrawal is not allowed by the organization policy (see Settings).');
            }
            if ($policy === 'inactive_only' && $member->status === 'active') {
                $this->fail('amount', 'Share capital can only be withdrawn when the member is inactive.');
            }
            if ($amount > $member->paidUpShares() + 0.001) {
                $this->fail('amount', 'Not enough share capital. The member has paid in '.number_format($member->paidUpShares(), 2).'.');
            }
        }

        if ($type === 'savings_withdrawal') {
            $balance = $member->savingsBalance();
            $minimum = (float) Setting::get('min_balance');

            if ($amount > $balance + 0.001) {
                $this->fail('amount', 'Insufficient savings. Balance is '.number_format($balance, 2).'.');
            }
            if ($minimum > 0 && $balance - $amount < $minimum - 0.001) {
                $this->fail('amount', 'The account must keep a minimum balance of '.number_format($minimum, 2).'. You can withdraw up to '.number_format(max($balance - $minimum, 0), 2).'.');
            }
        }

        if (in_array($type, ['loan_payment', 'loan_principal', 'loan_interest', 'loan_fees', 'loan_penalty'])) {
            if (! $loan || $loan->status !== 'released' || $loan->member_id !== $member->id) {
                $this->fail('loan_id', 'Please choose one of the member\'s released loans.');
            }

            [$left, $name] = match ($type) {
                'loan_payment' => [$loan->outstanding(), 'the loan balance'],
                'loan_principal' => [$loan->principalBalance(), 'the principal balance'],
                'loan_interest' => [$loan->interestBalance(), 'the interest balance'],
                'loan_fees' => [$loan->feesBalance(), 'the fees balance'],
                'loan_penalty' => [$this->schedule->penaltyDue($loan), 'the penalty due'],
            };
            if ($amount > $left + 0.001) {
                $this->fail('amount', "{$loan->loan_no}: the amount is more than $name (".number_format($left, 2).').');
            }
        }

        if ($type === 'loan_release' && (! $loan || $loan->status !== 'approved')) {
            $this->fail('loan_id', 'Only approved loans can be released.');
        }
    }

    /**
     * Update the loan after a payment and return the number to show as "balance after".
     * Principal, interest and fees reduce the loan balance. A penalty is tracked on its own.
     */
    private function applyToLoan(Transaction $tx, Loan $loan): float
    {
        $amount = (float) $tx->amount;

        switch ($tx->type) {
            case 'loan_principal':
                $loan->principal_paid += $amount;
                break;
            case 'loan_interest':
                $loan->interest_paid += $amount;
                break;
            case 'loan_fees':
                $loan->fees_paid += $amount;
                break;
            case 'loan_penalty':
                $loan->penalty_paid += $amount;
                break;
            case 'loan_payment':
                [$principal, $interest, $fees] = $loan->splitPayment($amount);
                $loan->principal_paid += $principal;
                $loan->interest_paid += $interest;
                $loan->fees_paid += $fees;
                $tx->update(['breakdown' => json_encode(compact('principal', 'interest', 'fees'))]);
                break;
        }

        $this->refreshLoan($loan);

        return $tx->type === 'loan_penalty' ? $this->schedule->penaltyDue($loan) : (float) $loan->balance;
    }

    /** Take a reversed payment back off the loan. */
    private function undoOnLoan(Transaction $tx): void
    {
        $loan = $tx->loan;
        if (! $loan) {
            return;
        }
        $amount = (float) $tx->amount;

        switch ($tx->type) {
            case 'loan_principal':
                $loan->principal_paid -= $amount;
                break;
            case 'loan_interest':
                $loan->interest_paid -= $amount;
                break;
            case 'loan_fees':
                $loan->fees_paid -= $amount;
                break;
            case 'loan_penalty':
                $loan->penalty_paid -= $amount;
                break;
            case 'loan_payment':
                $parts = $tx->breakdown ? json_decode($tx->breakdown, true) : null;
                if (! $parts) {
                    // Older payment without a stored split: use the loan's proportions
                    $total = (float) $loan->total_payable;
                    $interest = $total > 0 ? round($amount * (float) $loan->total_interest / $total, 2) : 0.0;
                    $fees = $total > 0 ? round($amount * (float) $loan->fees_added / $total, 2) : 0.0;
                    $parts = ['principal' => round($amount - $interest - $fees, 2), 'interest' => $interest, 'fees' => $fees];
                }
                $loan->principal_paid -= $parts['principal'];
                $loan->interest_paid -= $parts['interest'];
                $loan->fees_paid -= $parts['fees'];
                break;
            default:
                return;
        }

        foreach (['principal_paid', 'interest_paid', 'fees_paid', 'penalty_paid'] as $column) {
            $loan->$column = max(0, round((float) $loan->$column, 2));
        }
        $this->refreshLoan($loan);
    }

    /** Recalculate the loan balance and mark it paid / unpaid. */
    private function refreshLoan(Loan $loan): void
    {
        foreach (['principal_paid', 'interest_paid', 'fees_paid', 'penalty_paid'] as $column) {
            $loan->$column = round((float) $loan->$column, 2);
        }

        $loan->balance = $loan->outstanding();
        if ($loan->balance <= 0.005) {
            $loan->balance = 0;
            $loan->status = 'paid';
        } elseif ($loan->status === 'paid') {
            $loan->status = 'released';
        }
        $loan->save();
    }

    /** Create the double-entry journal for a transaction. */
    private function journalize(Transaction $tx, ?Loan $loan): void
    {
        $amount = (float) $tx->amount;

        $lines = match ($tx->type) {
            'share_deposit' => [[self::CASH, $amount, 0], [self::SHARES, 0, $amount]],
            'share_withdrawal' => [[self::SHARES, $amount, 0], [self::CASH, 0, $amount]],
            'savings_deposit' => [[self::CASH, $amount, 0], [self::SAVINGS, 0, $amount]],
            'savings_withdrawal' => [[self::SAVINGS, $amount, 0], [self::CASH, 0, $amount]],
            'loan_release' => $this->loanReleaseLines($amount, $loan),
            'other_receipt', 'other_deduction', 'membership_fee' => [[self::CASH, $amount, 0], [self::OTHER_INCOME, 0, $amount]],
            'other_receivable' => [[self::CASH, $amount, 0], [self::OTHER_RECEIVABLE, 0, $amount]],
            'insurance' => [[self::CASH, $amount, 0], [self::INSURANCE_PAYABLE, 0, $amount]],
            'expense' => [[self::EXPENSE, $amount, 0], [self::CASH, 0, $amount]],
            'loan_payment' => $this->loanPaymentLines($amount, $loan),
            // Explicit parts of a loan payment
            'loan_principal', 'loan_fees' => [[self::CASH, $amount, 0], [self::LOANS, 0, $amount]],
            'loan_interest' => [[self::CASH, $amount, 0], [self::INTEREST, 0, $amount]],
            'loan_penalty' => [[self::CASH, $amount, 0], [self::PENALTY_INCOME, 0, $amount]],
        };

        // Money received or paid by bank transfer, check or e-wallet goes through the bank account
        $cash = ($tx->payment_method ?? 'cash') === 'cash' ? self::CASH : self::BANK;

        $entry = JournalEntry::create([
            'entry_date' => $tx->posted_at->toDateString(),
            'reference' => $tx->reference_no,
            'description' => $tx->label().($tx->member ? ' - '.$tx->member->full_name : ''),
            'transaction_id' => $tx->id,
            'user_id' => auth()->id(),
        ]);

        foreach ($lines as [$code, $debit, $credit]) {
            $entry->lines()->create([
                'account_id' => Account::byCode($code === self::CASH ? $cash : $code)->id,
                'debit' => $debit,
                'credit' => $credit,
            ]);
        }
    }

    /**
     * Releasing a loan: the receivable is the principal plus any fees added to what the borrower repays.
     * Cash out is the net proceeds; deductions taken at release and added fees are fee income.
     */
    private function loanReleaseLines(float $cashOut, Loan $loan): array
    {
        $principal = (float) $loan->principal;
        $feesAdded = (float) $loan->fees_added;
        $feeIncome = round(($principal - $cashOut) + $feesAdded, 2);

        $lines = [[self::LOANS, round($principal + $feesAdded, 2), 0], [self::CASH, 0, $cashOut]];
        if ($feeIncome > 0) {
            $lines[] = [self::OTHER_INCOME, 0, $feeIncome];
        }

        return $lines;
    }

    /** Part of each lump-sum loan payment is interest income, the rest reduces the receivable. */
    private function loanPaymentLines(float $amount, Loan $loan): array
    {
        $interest = $loan->total_payable > 0
            ? round($amount * ($loan->total_interest / $loan->total_payable), 2)
            : 0;
        $principal = round($amount - $interest, 2);

        $lines = [[self::CASH, $amount, 0], [self::LOANS, 0, $principal]];
        if ($interest > 0) {
            $lines[] = [self::INTEREST, 0, $interest];
        }

        return $lines;
    }

    private function nextReference(string $prefix): string
    {
        $year = date('Y');
        $count = Transaction::where('reference_no', 'like', "$prefix# $year-%")->count() + 1;

        return sprintf('%s# %s-%04d', $prefix, $year, $count);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
