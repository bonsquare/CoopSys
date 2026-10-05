<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Transaction extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    /** type => [label, cash direction, reference prefix, badge colors] */
    public const TYPES = [
        'share_deposit' => ['Share Capital Deposit', 'in',  'OR', 'bg-secondary-fixed text-on-secondary-fixed'],
        'savings_deposit' => ['Savings Deposit',       'in',  'OR', 'bg-surface-container text-on-surface'],
        'loan_payment' => ['Loan Repayment',        'in',  'OR', 'bg-surface-container-high text-primary'],
        'other_receipt' => ['Other Receipt',         'in',  'OR', 'bg-surface-container-high text-on-surface'],
        'savings_withdrawal' => ['Withdrawal',            'out', 'WD', 'bg-error-container text-on-error-container'],
        'loan_release' => ['Loan Disbursement',     'out', 'DV', 'bg-surface-container-high text-on-surface'],
        'loan_principal' => ['Loan Principal', 'in', 'OR', 'bg-surface-container-high text-primary'],
        'loan_interest' => ['Loan Interest', 'in', 'OR', 'bg-surface-container-high text-primary'],
        'loan_penalty' => ['Loan Penalty', 'in', 'OR', 'bg-error-container text-on-error-container'],
        'loan_fees' => ['Loan Fees / Charges', 'in', 'OR', 'bg-surface-container-high text-primary'],
        'share_withdrawal' => ['Share Capital Withdrawal', 'out', 'WD', 'bg-error-container text-on-error-container'],
        'membership_fee' => ['Membership Fee', 'in', 'OR', 'bg-secondary-fixed text-on-secondary-fixed'],
        'insurance' => ['Insurance', 'in', 'OR', 'bg-surface-container text-on-surface'],
        'other_receivable' => ['Other Receivable Payment', 'in', 'OR', 'bg-surface-container text-on-surface'],
        'other_deduction' => ['Other Deduction / Adjustment', 'in', 'OR', 'bg-surface-container text-on-surface'],
        'expense' => ['Expense',               'out', 'DV', 'bg-error-container text-on-error-container'],
    ];

    protected function casts(): array
    {
        return ['posted_at' => 'datetime'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function journalEntry(): HasOne
    {
        return $this->hasOne(JournalEntry::class);
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    /** [member_id => share capital paid in] (contributions minus withdrawals). */
    public static function shareBalances(): array
    {
        $balances = [];
        foreach (static::completed()->whereIn('type', ['share_deposit', 'share_withdrawal'])->selectRaw('member_id, type, sum(amount) as total')->groupBy('member_id', 'type')->get() as $row) {
            $balances[$row->member_id] = ($balances[$row->member_id] ?? 0) + ($row->type === 'share_deposit' ? $row->total : -$row->total);
        }

        return $balances;
    }

    public function scopeCompleted(Builder $q): Builder
    {
        return $q->where('status', 'completed');
    }

    public function label(): string
    {
        return self::TYPES[$this->type][0] ?? $this->type;
    }

    public function badge(): string
    {
        return self::TYPES[$this->type][3] ?? 'bg-surface-container text-on-surface';
    }

    public function signedAmount(): float
    {
        return $this->direction === 'in' ? (float) $this->amount : -(float) $this->amount;
    }

    public static function typesFor(string $direction): array
    {
        return array_filter(self::TYPES, fn ($t) => $t[1] === $direction);
    }
}
