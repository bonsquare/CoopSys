<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollectionLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** Settings for this kind of row: label, ledger type, direction ... (see config/collections.php) */
    public function meta(): array
    {
        return config("collections.allocations.{$this->allocation_type}", []);
    }

    public function label(): string
    {
        return $this->meta()['label'] ?? $this->allocation_type;
    }

    public function isOut(): bool
    {
        return ($this->meta()['direction'] ?? 'in') === 'out';
    }
}
