<?php

namespace App\Services;

use App\Models\Loan;
use Illuminate\Support\Carbon;

/**
 * Looks at a released loan and works out what is due: which installments are paid or overdue,
 * what is due right now (principal, interest, fees) and how much penalty has built up.
 *
 * Penalty rules: a penalty only starts after the product's grace period, and only on installments
 * that are still unpaid. A fixed penalty is charged per period overdue (day / week / month) or once
 * per missed payment. A percentage penalty is a percentage of the unpaid part of the installment.
 */
class LoanScheduleService
{
    public function __construct(private LoanCalculator $calculator) {}

    /** The loan's installments with due dates: no, due, principal, interest, fees, payment. */
    public function schedule(Loan $loan): array
    {
        if (! $loan->released_at) {
            return [];
        }

        $term = (int) ($loan->term_value ?? $loan->term_months);
        $unit = $loan->term_unit ?: 'months';
        $frequency = $loan->payment_frequency ?: 'monthly';

        if ($loan->schedule) {
            $rows = $loan->schedule;
        } else {
            // Older loans have no stored schedule: equal monthly installments
            $n = max((int) $loan->term_months, 1);
            $principal = round($loan->principal / $n, 2);
            $interest = round($loan->total_interest / $n, 2);
            $rows = [];
            for ($k = 1; $k <= $n; $k++) {
                $p = $k === $n ? round($loan->principal - $principal * ($n - 1), 2) : $principal;
                $i = $k === $n ? round($loan->total_interest - $interest * ($n - 1), 2) : $interest;
                $rows[] = ['no' => $k, 'principal' => $p, 'interest' => $i, 'fees' => 0.0, 'payment' => round($p + $i, 2)];
            }
        }

        foreach ($rows as &$row) {
            $row['due'] = $this->calculator->dueDate($loan->released_at, $frequency, $row['no'], $term, $unit);
        }

        return $rows;
    }

    /**
     * Everything the collection screen and the ledger need to know about one loan.
     * Returns: rows, paid_total, due_now, suggest, penalty, past_due, next_due, payment.
     */
    public function analyze(Loan $loan, ?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? now())->copy()->startOfDay();
        $rows = $this->schedule($loan);
        $paidTotal = (float) $loan->principal_paid + (float) $loan->interest_paid + (float) $loan->fees_paid;

        $cum = ['payment' => 0.0, 'principal' => 0.0, 'interest' => 0.0, 'fees' => 0.0];
        $lastDue = null;   // index of the last installment whose due date has arrived
        $nextDue = null;   // first installment not yet fully paid

        foreach ($rows as $index => &$row) {
            foreach ($cum as $key => $value) {
                $cum[$key] = $value + (float) $row[$key];
                $row['cum_'.$key] = round($cum[$key], 2);
            }
            $row['covered'] = $paidTotal >= $row['cum_payment'] - 0.005;
            $row['shortfall'] = $row['covered'] ? 0.0 : round(min((float) $row['payment'], $row['cum_payment'] - $paidTotal), 2);
            $row['overdue'] = ! $row['covered'] && $row['due']->lt($asOf);

            if ($row['due']->lte($asOf)) {
                $lastDue = $index;
            }
            if ($nextDue === null && ! $row['covered']) {
                $nextDue = $index;
            }
        }
        unset($row);

        $components = fn (?int $at) => $at === null ? ['principal' => 0.0, 'interest' => 0.0, 'fees' => 0.0] : [
            'principal' => max(0, round($rows[$at]['cum_principal'] - (float) $loan->principal_paid, 2)),
            'interest' => max(0, round($rows[$at]['cum_interest'] - (float) $loan->interest_paid, 2)),
            'fees' => max(0, round($rows[$at]['cum_fees'] - (float) $loan->fees_paid, 2)),
        ];

        // What is due today, strictly
        $dueNow = $components($lastDue);

        // What to suggest: what is due, or the next installment when nothing is due yet
        $suggest = $dueNow;
        if (array_sum($suggest) <= 0 && $nextDue !== null) {
            $suggest = $components($nextDue);
        }

        $penalty = $this->penalty($loan, $rows, $asOf);

        return [
            'rows' => $rows,
            'paid_total' => round($paidTotal, 2),
            'due_now' => $dueNow,
            'suggest' => $suggest,
            'penalty' => $penalty,
            'past_due' => $loan->status === 'released' && ($penalty['missed'] > 0 || $loan->isPastDue() || collect($rows)->contains('overdue', true)),
            'next_due' => $nextDue !== null ? $rows[$nextDue]['due'] : null,
            'payment' => $nextDue !== null ? (float) $rows[$nextDue]['payment'] : 0.0,
        ];
    }

    /** Penalty still to be paid on this loan. */
    public function penaltyDue(Loan $loan, ?Carbon $asOf = null): float
    {
        return $this->analyze($loan, $asOf)['penalty']['due'];
    }

    private function penalty(Loan $loan, array $rows, Carbon $asOf): array
    {
        $product = $loan->product;
        $accrued = 0.0;
        $missed = 0;

        if ($loan->status === 'released' && $product && $product->penalty_type !== 'none' && (float) $product->penalty_value > 0) {
            foreach ($rows as $row) {
                if (! $row['overdue'] || $row['shortfall'] <= 0.005) {
                    continue;
                }

                $graceEnds = $row['due']->copy()->addDays((int) $product->grace_days);
                if ($asOf->lte($graceEnds)) {
                    continue;   // still inside the grace period
                }

                $days = (int) abs($graceEnds->diffInDays($asOf));
                $periods = match ($product->penalty_basis) {
                    'per_day' => $days,
                    'per_week' => (int) ceil($days / 7),
                    'per_month' => (int) ceil($days / 30),
                    default => 1,   // per missed payment
                };

                $accrued += $product->penalty_type === 'fixed'
                    ? (float) $product->penalty_value * $periods
                    : $row['shortfall'] * ((float) $product->penalty_value / 100) * $periods;
                $missed++;
            }
        }

        $accrued = round($accrued, 2);
        $paid = round((float) $loan->penalty_paid, 2);

        return ['accrued' => $accrued, 'paid' => $paid, 'due' => max(0, round($accrued - $paid, 2)), 'missed' => $missed];
    }
}
