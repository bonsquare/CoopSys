<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoanProduct extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'auto_schedule' => 'boolean',
            'guarantor_required' => 'boolean',
            'allow_early_payment' => 'boolean',
            'early_payment_penalty' => 'boolean',
            'allow_renewal' => 'boolean',
            'allow_restructuring' => 'boolean',
            'required_documents' => 'array',
        ];
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(LoanProductDeduction::class)->orderBy('id');
    }

    /** Readable label for a stored option key, e.g. label('interest_types', 'add_on'). */
    public static function label(string $list, ?string $key): string
    {
        return config("loan_products.$list.$key", $key === null ? '—' : ucfirst(str_replace('_', ' ', $key)));
    }

    /** "6 Months" style text for the term range. */
    public function termRange(): string
    {
        $unit = self::label('term_units', $this->term_unit);

        return $this->min_term === $this->max_term ? "{$this->min_term} {$unit}" : "{$this->min_term}–{$this->max_term} {$unit}";
    }

    /** "12% Annual" style text. */
    public function rateText(): string
    {
        if ($this->interest_type === 'interest_free') {
            return '0% (Interest-Free)';
        }

        return (float) $this->interest_rate.'% '.self::label('interest_bases', $this->interest_basis);
    }

    /** [id => "Product name (12% Annual)"] for dropdowns, active products only. */
    public static function options(): array
    {
        return static::where('is_active', true)->orderBy('name')->get()
            ->mapWithKeys(fn ($p) => [$p->id => $p->name.' ('.$p->rateText().')'])
            ->all();
    }
}
