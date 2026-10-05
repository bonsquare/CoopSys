<?php

namespace App\Services;

use App\Models\LoanProduct;
use Illuminate\Support\Carbon;

/**
 * Works out a loan's numbers from a loan product's settings:
 * deductions, net proceeds, interest, total payable and the payment schedule.
 *
 * Interest types:
 *  - flat / add_on / simple : interest = amount x yearly rate x years, on the ORIGINAL amount, split evenly
 *  - diminishing            : interest is charged on the balance still owed each period
 *  - compound               : interest is added to the balance every period (payable = amount x (1 + i)^n)
 *  - interest_free          : no interest
 */
class LoanCalculator
{
    public function compute(LoanProduct $product, float $amount, int $term, ?string $frequency = null): array
    {
        $freq = $frequency ?: $product->payment_frequency;
        $ppy = config('loan_products.periods_per_year');

        $termYears = match ($product->term_unit) {
            'days' => $term / 365,
            'weeks' => $term / 52,
            'years' => $term,
            default => $term / 12,
        };
        $n = $freq === 'one_time' ? 1 : max(1, (int) round($termYears * $ppy[$freq]));

        // ---- Deductions ----
        $deductions = [];
        $atRelease = $addedToPayable = $firstPayment = 0.0;

        foreach ($product->deductions->where('is_active', true) as $d) {
            $value = match ($d->type) {
                'fixed' => (float) $d->value,
                'percentage' => $amount * (float) $d->value / 100,
                default => 0.0,
            };
            $value = round($value, 2);

            match ($d->timing) {
                'add_to_payable' => $addedToPayable += $value,
                'first_payment' => $firstPayment += $value,
                default => $atRelease += $value,
            };

            $deductions[] = [
                'name' => $d->name, 'type' => $d->type, 'value' => (float) $d->value, 'basis' => $d->basis,
                'timing' => $d->timing, 'amount' => $value, 'required' => $d->is_required, 'editable' => $d->is_editable,
            ];
        }

        // ---- Interest and schedule ----
        $rate = (float) $product->interest_rate / 100;
        $annual = $product->interest_basis === 'per_term'
            ? ($termYears > 0 ? $rate / $termYears : $rate)
            : $rate * $ppy[$product->interest_basis];
        $i = $freq === 'one_time' ? $annual * $termYears : $annual / $ppy[$freq];

        $principals = [];
        $interests = [];
        $equalPrincipal = $product->amortization_method === 'equal_principal';

        if ($product->interest_type === 'diminishing' && $rate > 0) {
            $payment = $i > 0 ? $amount * $i / (1 - pow(1 + $i, -$n)) : $amount / $n;
            $balance = $amount;
            for ($k = 1; $k <= $n; $k++) {
                $interest = round($balance * $i, 2);
                $principal = $k === $n ? round($balance, 2) : round($equalPrincipal ? $amount / $n : $payment - $interest, 2);
                $principals[] = $principal;
                $interests[] = $interest;
                $balance -= $principal;
            }
        } else {
            $totalInterest = match (true) {
                $product->interest_type === 'interest_free' || $rate <= 0 => 0.0,
                $product->interest_type === 'compound' => $amount * pow(1 + $i, $n) - $amount,
                default => $amount * $annual * $termYears,
            };
            $principals = $this->splitEvenly($amount, $n);
            $interests = $this->splitEvenly(round($totalInterest, 2), $n);
        }

        $totalInterest = round(array_sum($interests), 2);

        // ---- Fees that are paid back with the loan ----
        $feeEach = $this->splitEvenly(round($addedToPayable, 2), $n);
        $rows = [];
        $outstanding = $amount;
        foreach ($principals as $index => $principal) {
            $fee = $feeEach[$index] + ($index === 0 ? $firstPayment : 0);
            $outstanding = round($outstanding - $principal, 2);
            $rows[] = [
                'no' => $index + 1,
                'principal' => $principal,
                'interest' => $interests[$index],
                'fees' => round($fee, 2),
                'payment' => round($principal + $interests[$index] + $fee, 2),
                'balance' => max($outstanding, 0),
            ];
        }

        $feesPayable = round($addedToPayable + $firstPayment, 2);
        $atRelease = round($atRelease, 2);

        return [
            'frequency' => $freq,
            'payments' => $n,
            'approved_amount' => round($amount, 2),
            'deductions' => $deductions,
            'total_deductions' => $atRelease,
            'net_proceeds' => round($amount - $atRelease, 2),
            'interest' => $totalInterest,
            'fees_payable' => $feesPayable,
            'total_payable' => round($amount + $totalInterest + $feesPayable, 2),
            'first_payment' => $rows[0]['payment'],
            'payment_amount' => ($rows[1] ?? $rows[0])['payment'],
            'payments_vary' => (max(array_column($rows, 'payment')) - min(array_column($rows, 'payment'))) > 1, // ignores a few cents of rounding
            'custom_schedule' => $product->amortization_method === 'custom',
            'rows' => $rows,
        ];
    }

    /** Split $total into $n parts rounded to cents; the last part absorbs the rounding difference. */
    private function splitEvenly(float $total, int $n): array
    {
        $part = round($total / $n, 2);
        $parts = array_fill(0, $n, $part);
        $parts[$n - 1] = round($total - $part * ($n - 1), 2);

        return $parts;
    }

    /** Due date of payment number $k (1, 2, 3 ...). */
    public function dueDate(Carbon $start, string $frequency, int $k, int $term, string $unit): Carbon
    {
        return match ($frequency) {
            'daily' => $start->copy()->addDays($k),
            'weekly' => $start->copy()->addWeeks($k),
            'semi_monthly' => $start->copy()->addMonthsNoOverflow(intdiv($k, 2))->addDays($k % 2 ? 15 : 0),
            'monthly' => $start->copy()->addMonthsNoOverflow($k),
            'quarterly' => $start->copy()->addMonthsNoOverflow(3 * $k),
            'annual' => $start->copy()->addYears($k),
            default => $this->maturity($start, $term, $unit), // one-time payment
        };
    }

    public function maturity(Carbon $start, int $term, string $unit): Carbon
    {
        return match ($unit) {
            'days' => $start->copy()->addDays($term),
            'weeks' => $start->copy()->addWeeks($term),
            'years' => $start->copy()->addYears($term),
            default => $start->copy()->addMonthsNoOverflow($term),
        };
    }

    /** Approximate length in whole months (kept on the loan for older screens and reports). */
    public function termInMonths(int $term, string $unit): int
    {
        $months = match ($unit) {
            'days' => $term / 30.4375,
            'weeks' => $term / 4.345,
            'years' => $term * 12,
            default => $term,
        };

        return max(1, (int) ceil($months));
    }
}
