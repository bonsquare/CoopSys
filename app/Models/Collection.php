<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One receipt. It can hold several allocation lines (loan payment + savings + fees ...). */
class Collection extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'collection_date' => 'date',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CollectionLine::class)->orderBy('sort');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function typeLabel(): string
    {
        return config("collections.types.{$this->collection_type}.label", ucfirst(str_replace('_', ' ', $this->collection_type)));
    }

    public function statusLabel(): string
    {
        return config("collections.statuses.{$this->status}", ucfirst($this->status));
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'posted' => 'bg-secondary-fixed text-on-secondary-fixed',
            'cancelled' => 'bg-error-container text-on-error-container',
            default => 'bg-surface-container text-on-surface-variant',
        };
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /** Next receipt number, e.g. OR-2026-000012. The user may type their own instead. */
    public static function nextReceiptNumber(): string
    {
        $year = date('Y');
        $count = static::where('receipt_no', 'like', "OR-$year-%")->count() + 1;

        do {
            $number = sprintf('OR-%s-%06d', $year, $count++);
        } while (static::where('receipt_no', $number)->exists());

        return $number;
    }
}
