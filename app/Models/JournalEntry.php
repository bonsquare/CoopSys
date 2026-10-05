<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class JournalEntry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['entry_date' => 'date'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of');
    }

    public function isManual(): bool
    {
        return $this->source === 'manual';
    }

    public function number(): string
    {
        return $this->entry_no ?: $this->reference;
    }

    /** Next manual journal voucher number, e.g. JV-2026-0007. */
    public static function nextNumber(): string
    {
        $year = date('Y');
        $count = static::where('entry_no', 'like', "JV-$year-%")->count() + 1;

        do {
            $number = sprintf('JV-%s-%04d', $year, $count++);
        } while (static::where('entry_no', $number)->exists());

        return $number;
    }
}
