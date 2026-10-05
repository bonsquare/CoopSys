<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Collection;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payment collection (the teller receipt).
 *
 * A collection is a receipt with one or more allocation lines. Saving it as a draft changes nothing.
 * Posting it creates one ledger transaction per line, so loan balances, savings, share capital and
 * the accounting journal are all updated together. A posted receipt is never edited: it is cancelled,
 * which reverses every line and keeps the receipt number and the audit trail.
 */
class CollectionService
{
    public function __construct(private TransactionService $transactions, private LoanScheduleService $schedule) {}

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    /** The order in which Auto Allocate uses up a payment, e.g. ['penalty', 'interest', 'fees', 'principal', 'savings', 'share']. */
    public function priority(): array
    {
        $preset = Setting::get('allocation_preset') ?: 'penalty_first';
        $order = config("collections.priority_presets.$preset.order");

        if ($order === null) {
            $items = array_keys(config('collections.priority_items'));
            $custom = array_values(array_filter(array_map('trim', explode(',', (string) Setting::get('allocation_custom'))), fn ($k) => in_array($k, $items)));
            $order = array_values(array_unique(array_merge($custom, $items)));   // anything missing goes last
        }

        return $order;
    }

    // ------------------------------------------------------------------
    // Reading a member's accounts
    // ------------------------------------------------------------------

    /** Everything the teller screen shows on the left: profile, balances, loans. */
    public function overview(Member $member): array
    {
        $loans = [];
        $totals = ['balance' => 0.0, 'principal' => 0.0, 'interest_due' => 0.0, 'penalty_due' => 0.0, 'total_due' => 0.0];
        $anyPastDue = false;

        foreach ($this->activeLoans($member) as $loan) {
            $a = $this->schedule->analyze($loan);

            $loans[] = [
                'id' => $loan->id,
                'loan_no' => $loan->loan_no,
                'product' => $loan->product?->name,
                'balance' => (float) $loan->balance,
                'principal_balance' => $loan->principalBalance(),
                'interest_balance' => $loan->interestBalance(),
                'fees_balance' => $loan->feesBalance(),
                'penalty_due' => $a['penalty']['due'],
                'due_now' => $a['due_now'],
                'total_due' => round(array_sum($a['due_now']) + $a['penalty']['due'], 2),
                'suggest' => $a['suggest'],
                'payment' => $a['payment'],
                'next_due' => $a['next_due']?->format('M d, Y'),
                'maturity' => $loan->maturity_date?->format('M d, Y'),
                'past_due' => $a['past_due'],
            ];

            $totals['balance'] += (float) $loan->balance;
            $totals['principal'] += $loan->principalBalance();
            $totals['interest_due'] += $a['due_now']['interest'];
            $totals['penalty_due'] += $a['penalty']['due'];
            // everything that is due today: installments that have fallen due (principal, interest, fees) plus the penalty
            $totals['total_due'] += array_sum($a['due_now']) + $a['penalty']['due'];
            $anyPastDue = $anyPastDue || $a['past_due'];
        }

        $savings = $member->savingsBalance();
        $minimum = (float) Setting::get('min_balance');
        $paidShares = $member->paidUpShares();
        $policy = Setting::get('share_withdrawal_policy') ?: 'not_allowed';

        $flag = null;
        if ($member->status !== 'active') {
            $flag = 'INACTIVE';
        } elseif ($totals['penalty_due'] > 0) {
            $flag = 'PENALTY DUE';
        } elseif ($anyPastDue) {
            $flag = 'PAST DUE';
        }

        return [
            'member' => [
                'id' => $member->id,
                'member_no' => $member->member_no,
                'name' => $member->full_name,
                'initials' => strtoupper(substr($member->first_name, 0, 1).substr($member->last_name, 0, 1)),
                'status' => $member->status,
                'contact' => $member->contact_no,
                'address' => $member->address,
                'joined' => $member->date_joined?->format('M d, Y'),
                'flag' => $flag,
            ],
            'loans' => $loans,
            'totals' => array_map(fn ($v) => round($v, 2), $totals),
            'savings' => $savings,
            'min_balance' => $minimum,
            'available_withdrawal' => max(round($savings - $minimum, 2), 0),
            'shares_paid' => $paidShares,
            'shares_subscribed' => (float) $member->subscribed_shares,
            'shares_unpaid' => max(round((float) $member->subscribed_shares - $paidShares, 2), 0),
            'share_withdrawal_allowed' => $policy === 'allowed' || ($policy === 'inactive_only' && $member->status !== 'active'),
        ];
    }

    private function activeLoans(Member $member, ?int $onlyLoan = null)
    {
        return Loan::with('product')->where('member_id', $member->id)->where('status', 'released')
            ->when($onlyLoan, fn ($q) => $q->where('id', $onlyLoan))
            ->orderBy('released_at')->orderBy('id')->get();
    }

    // ------------------------------------------------------------------
    // Suggested breakdown
    // ------------------------------------------------------------------

    /**
     * Suggest how to split $amount. Returns ['rows' => [...], 'unallocated' => float].
     * Each row: allocation_type, loan_id, loan_no, amount, due.
     */
    public function suggest(Member $member, float $amount, string $type = 'loan_payment', ?int $loanId = null): array
    {
        $amount = round($amount, 2);
        $single = config("collections.types.$type.row");

        // Receipts for one thing: the whole amount goes to that one row
        if ($single && $type !== 'penalty') {
            return ['rows' => [['allocation_type' => $single, 'loan_id' => null, 'loan_no' => null, 'amount' => $amount, 'due' => null]], 'unallocated' => 0.0];
        }

        $loans = $this->activeLoans($member, $loanId);
        $info = [];
        foreach ($loans as $loan) {
            $a = $this->schedule->analyze($loan);
            $info[$loan->id] = [
                'loan' => $loan,
                'penalty' => $a['penalty']['due'],
                'interest' => min($a['suggest']['interest'], $loan->interestBalance()),
                'fees' => min($a['suggest']['fees'], $loan->feesBalance()),
                'principal' => min($a['suggest']['principal'], $loan->principalBalance()),
            ];
        }

        $order = $type === 'penalty' ? ['penalty'] : $this->priority();
        $remaining = $amount;
        $rows = [];

        foreach ($order as $item) {
            if ($remaining <= 0.004) {
                break;
            }

            if (in_array($item, ['penalty', 'interest', 'fees', 'principal'])) {
                foreach ($info as $id => $i) {
                    $due = $i[$item];
                    if ($due <= 0.004 || $remaining <= 0.004) {
                        continue;
                    }
                    $take = round(min($remaining, $due), 2);
                    $rows[] = ['allocation_type' => 'loan_'.$item, 'loan_id' => $id, 'loan_no' => $i['loan']->loan_no, 'amount' => $take, 'due' => $due];
                    $remaining = round($remaining - $take, 2);
                }
            } elseif ($item === 'savings') {
                $regular = (float) Setting::get('regular_savings');
                if ($regular > 0) {
                    $take = round(min($remaining, $regular), 2);
                    $rows[] = ['allocation_type' => 'savings_deposit', 'loan_id' => null, 'loan_no' => null, 'amount' => $take, 'due' => $regular];
                    $remaining = round($remaining - $take, 2);
                }
            } elseif ($item === 'share') {
                $regular = min((float) Setting::get('regular_share'), max((float) $member->subscribed_shares - $member->paidUpShares(), 0));
                if ($regular > 0) {
                    $take = round(min($remaining, $regular), 2);
                    $rows[] = ['allocation_type' => 'share_contribution', 'loan_id' => null, 'loan_no' => null, 'amount' => $take, 'due' => $regular];
                    $remaining = round($remaining - $take, 2);
                }
            }
        }

        return ['rows' => $rows, 'unallocated' => max($remaining, 0.0)];
    }

    // ------------------------------------------------------------------
    // Saving, posting, cancelling
    // ------------------------------------------------------------------

    /**
     * Save a receipt as a draft (or update an existing draft). Nothing is posted.
     * $data: member_id, collection_type, collection_date, payment_method, payment_ref?, receipt_no?,
     *        total_amount, cash_tendered?, remarks?, lines[] (allocation_type, loan_id?, amount, due_amount?)
     */
    public function saveDraft(array $data, ?Collection $collection = null): Collection
    {
        if ($collection && ! $collection->isDraft()) {
            $this->fail('collection', 'A posted or cancelled receipt cannot be edited. Cancel it and enter a new one.');
        }

        $receiptNo = trim((string) ($data['receipt_no'] ?? '')) ?: ($collection?->receipt_no ?? Collection::nextReceiptNumber());
        if (Collection::where('receipt_no', $receiptNo)->when($collection, fn ($q) => $q->where('id', '!=', $collection->id))->exists()) {
            $this->fail('receipt_no', "The receipt number $receiptNo is already used.");
        }

        return DB::transaction(function () use ($data, $collection, $receiptNo) {
            $fields = [
                'receipt_no' => $receiptNo,
                'collection_date' => $data['collection_date'],
                'member_id' => $data['member_id'],
                'collection_type' => $data['collection_type'],
                'payment_method' => $data['payment_method'] ?? 'cash',
                'payment_ref' => $data['payment_ref'] ?? null,
                'total_amount' => round((float) ($data['total_amount'] ?? 0), 2),
                'direction' => $this->netOf($data['lines']) >= 0 ? 'in' : 'out',
                'cash_tendered' => isset($data['cash_tendered']) && $data['cash_tendered'] !== '' ? round((float) $data['cash_tendered'], 2) : null,
                'remarks' => $data['remarks'] ?? null,
                'collected_by' => auth()->id(),
            ];

            if ($collection) {
                $collection->update($fields);
                $collection->lines()->delete();
            } else {
                $collection = Collection::create($fields + ['status' => 'draft']);
            }

            foreach (array_values($data['lines']) as $index => $line) {
                $collection->lines()->create([
                    'sort' => $index + 1,
                    'allocation_type' => $line['allocation_type'],
                    'loan_id' => $line['loan_id'] ?? null,
                    'amount' => round((float) $line['amount'], 2),
                    'due_amount' => isset($line['due_amount']) && $line['due_amount'] !== '' ? $line['due_amount'] : null,
                ]);
            }

            AuditLog::record('collection.draft', "{$collection->receipt_no} saved as draft");

            return $collection->fresh('lines');
        });
    }

    /** Post a draft: validates it, then creates one ledger transaction per line. */
    public function post(Collection $collection): Collection
    {
        if (! $collection->isDraft()) {
            $this->fail('collection', 'Only a draft receipt can be posted.');
        }

        $collection->loadMissing(['lines', 'member']);
        $lines = $collection->lines;

        if ($lines->isEmpty()) {
            $this->fail('lines', 'Add at least one line to the payment breakdown.');
        }
        if ($collection->collection_date->isFuture()) {
            $this->fail('collection_date', 'The collection date cannot be in the future.');
        }

        // Every line must be a valid kind of row for this member
        foreach ($lines as $line) {
            if (! $line->meta()) {
                $this->fail('lines', "Unknown allocation type {$line->allocation_type}.");
            }
            if (($line->meta()['kind'] ?? null) === 'loan' && ! $line->loan_id) {
                $this->fail('lines', "{$line->label()}: choose the loan.");
            }
            if ($line->loan_id && ! Loan::where('id', $line->loan_id)->where('member_id', $collection->member_id)->exists()) {
                $this->fail('lines', "{$line->label()}: that loan does not belong to this member.");
            }
        }

        // The money received must equal what is allocated
        $in = round($lines->filter(fn ($l) => ! $l->isOut())->sum('amount'), 2);
        $out = round($lines->filter(fn ($l) => $l->isOut())->sum('amount'), 2);
        $net = round($in - $out, 2);

        if (abs($net) < 0.005) {
            $this->fail('lines', 'The breakdown nets to zero. Enter an amount received or paid out.');
        }
        if (abs($net) - (float) $collection->total_amount > 0.005 || (float) $collection->total_amount - abs($net) > 0.005) {
            $this->fail('total_amount', 'The total amount ('.number_format($collection->total_amount, 2).') must equal the total allocated ('.number_format(abs($net), 2).').');
        }

        // Cash tendered and change
        $tendered = null;
        $change = 0.0;
        if ($net > 0 && $collection->payment_method === 'cash') {
            $tendered = $collection->cash_tendered ?? $net;
            if ($tendered + 0.005 < $net) {
                $this->fail('cash_tendered', 'The cash received is less than the amount to collect.');
            }
            $change = round($tendered - $net, 2);
        }

        DB::transaction(function () use ($collection, $lines, $net, $tendered, $change) {
            $postedAt = Carbon::parse($collection->collection_date)->setTimeFrom(now());

            // Money in first, so a deposit on the same receipt can fund a withdrawal
            foreach ($lines->sortBy(fn ($l) => [$l->isOut() ? 1 : 0, $l->sort])->values() as $line) {
                try {
                    $tx = $this->transactions->post([
                        'type' => $line->meta()['tx_type'],
                        'amount' => $line->amount,
                        'member_id' => $collection->member_id,
                        'loan_id' => $line->loan_id,
                        'payment_method' => $collection->payment_method,
                        'payment_ref' => $collection->payment_ref,
                        'remarks' => $collection->remarks,
                        'posted_at' => $postedAt,
                        'reference_no' => $collection->receipt_no.'/'.$line->sort,
                        'collection_id' => $collection->id,
                    ]);
                } catch (ValidationException $e) {
                    $this->fail('lines', "{$line->label()}: ".collect($e->errors())->flatten()->first());
                }

                $line->update(['transaction_id' => $tx->id, 'balance_after' => $tx->balance_after]);
            }

            $collection->update([
                'status' => 'posted',
                'direction' => $net > 0 ? 'in' : 'out',
                'total_amount' => abs($net),
                'cash_tendered' => $tendered,
                'change_due' => $change,
                'posted_at' => now(),
                'collected_by' => $collection->collected_by ?? auth()->id(),
            ]);

            AuditLog::record('collection.posted', "{$collection->receipt_no} posted for {$collection->member->full_name}, net ".number_format($net, 2));
        });

        return $collection->fresh(['lines', 'member']);
    }

    /**
     * Cancel a receipt. A draft is simply cancelled. A posted receipt is reversed line by line.
     * Either way the receipt number stays on file with who cancelled it, when and why.
     */
    public function cancel(Collection $collection, string $reason): Collection
    {
        if ($collection->status === 'cancelled') {
            $this->fail('collection', 'This receipt is already cancelled.');
        }

        DB::transaction(function () use ($collection, $reason) {
            if ($collection->status === 'posted') {
                foreach ($collection->transactions()->where('status', 'completed')->orderByDesc('id')->get() as $tx) {
                    try {
                        $this->transactions->void($tx, $reason, true);
                    } catch (ValidationException $e) {
                        $this->fail('collection', 'Cannot cancel this receipt: '.collect($e->errors())->flatten()->first());
                    }
                }
            }

            $collection->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
                'cancel_reason' => $reason,
            ]);

            AuditLog::record('collection.cancelled', "{$collection->receipt_no}: $reason");
        });

        return $collection->fresh();
    }

    /** Money in minus money out for a list of lines. */
    private function netOf(array $lines): float
    {
        $net = 0.0;
        foreach ($lines as $line) {
            $direction = config("collections.allocations.{$line['allocation_type']}.direction", 'in');
            $net += $direction === 'out' ? -(float) $line['amount'] : (float) $line['amount'];
        }

        return round($net, 2);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
