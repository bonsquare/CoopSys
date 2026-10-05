<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Member extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['birthdate' => 'date', 'date_joined' => 'date', 'subscribed_shares' => 'decimal:2'];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    public function getFullNameAttribute(): string
    {
        return $this->first_name.' '.$this->last_name;
    }

    private function sumOf(string $type): float
    {
        return (float) $this->transactions()->completed()->where('type', $type)->sum('amount');
    }

    public function paidUpShares(): float
    {
        return $this->sumOf('share_deposit') - $this->sumOf('share_withdrawal');
    }

    public function savingsBalance(): float
    {
        return $this->sumOf('savings_deposit') - $this->sumOf('savings_withdrawal');
    }

    public function loanBalance(): float
    {
        return (float) $this->loans()->where('status', 'released')->sum('balance');
    }

    /** [id => "MEM-2026-0001 — Santos, Eduardo"] for dropdowns (active members only). */
    public static function options(): array
    {
        return static::where('status', 'active')->orderBy('last_name')->orderBy('first_name')->get()
            ->mapWithKeys(fn ($m) => [$m->id => "{$m->member_no} — {$m->last_name}, {$m->first_name}"])
            ->all();
    }

    /** Next free member number, e.g. MEM-2026-0007 */
    public static function nextNumber(): string
    {
        $year = date('Y');
        $count = static::where('member_no', 'like', "MEM-$year-%")->count() + 1;

        return sprintf('MEM-%s-%04d', $year, $count);
    }
}
