<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Loan extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'applied_at' => 'date', 'approved_at' => 'date',
            'released_at' => 'date', 'maturity_date' => 'date',
            'schedule' => 'array',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class, 'loan_product_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** Part of the loan still unpaid, by component. */
    public function principalBalance(): float
    {
        return max(0, round((float) $this->principal - (float) $this->principal_paid, 2));
    }

    public function interestBalance(): float
    {
        return max(0, round((float) $this->total_interest - (float) $this->interest_paid, 2));
    }

    public function feesBalance(): float
    {
        return max(0, round((float) $this->fees_added - (float) $this->fees_paid, 2));
    }

    /** Principal + interest + fees still to be paid (penalties are tracked separately). */
    public function outstanding(): float
    {
        return round($this->principalBalance() + $this->interestBalance() + $this->feesBalance(), 2);
    }

    /**
     * Split a lump-sum payment into [principal, interest, fees], in the same proportion as the loan.
     * A payment that clears the loan clears every part.
     */
    public function splitPayment(float $amount): array
    {
        $principalLeft = $this->principalBalance();
        $interestLeft = $this->interestBalance();
        $feesLeft = $this->feesBalance();

        if ($amount >= $principalLeft + $interestLeft + $feesLeft - 0.005) {
            return [$principalLeft, $interestLeft, $feesLeft];
        }

        $total = (float) $this->total_payable;
        $interest = $total > 0 ? min($interestLeft, round($amount * (float) $this->total_interest / $total, 2)) : 0.0;
        $fees = $total > 0 ? min($feesLeft, round($amount * (float) $this->fees_added / $total, 2)) : 0.0;
        $principal = round($amount - $interest - $fees, 2);

        if ($principal > $principalLeft) {
            $extra = $principal - $principalLeft;
            $principal = $principalLeft;
            $interest = min($interestLeft, $interest + $extra);
        }

        return [$principal, $interest, $fees];
    }

    public function isPastDue(): bool
    {
        return $this->status === 'released'
            && $this->balance > 0
            && $this->maturity_date
            && $this->maturity_date->isPast();
    }

    public static function nextNumber(): string
    {
        $year = date('Y');
        $count = static::where('loan_no', 'like', "LN-$year-%")->count() + 1;

        return sprintf('LN-%s-%04d', $year, $count);
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'pending' => 'bg-surface-container text-on-surface-variant',
            'approved' => 'bg-surface-container-high text-primary',
            'released' => 'bg-secondary-fixed text-on-secondary-fixed',
            'paid' => 'bg-secondary-container text-on-secondary-container',
            'rejected' => 'bg-error-container text-on-error-container',
            default => 'bg-surface-container text-on-surface',
        };
    }
}
