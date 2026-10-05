<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use BelongsToOrganization;

    public const TYPES = [
        'asset' => 'Asset',
        'liability' => 'Liability',
        'equity' => 'Equity',
        'income' => 'Income',
        'expense' => 'Expense',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_system' => 'boolean'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public static function byCode(string $code): self
    {
        return static::where('code', $code)->firstOrFail();
    }

    /** Assets and expenses grow with a debit; liabilities, equity and income grow with a credit. */
    public function normalSide(): string
    {
        return in_array($this->type, ['asset', 'expense'], true) ? 'debit' : 'credit';
    }

    /** Balance in the account's own direction (a positive number is a normal balance). */
    public function balanceOf(float $debit, float $credit): float
    {
        return round($this->normalSide() === 'debit' ? $debit - $credit : $credit - $debit, 2);
    }

    /** The section of a financial statement this account is shown under. */
    public function groupName(): string
    {
        return $this->report_group ?: config("accounting.default_group.{$this->type}", 'Other');
    }

    public function label(): string
    {
        return $this->code.' · '.$this->name;
    }

    /** [id => "1010 · Cash on Hand"] for dropdowns. */
    public static function options(bool $activeOnly = true): array
    {
        return static::when($activeOnly, fn ($q) => $q->where('is_active', true))->orderBy('code')->get()
            ->mapWithKeys(fn ($a) => [$a->id => $a->label()])->all();
    }
}
