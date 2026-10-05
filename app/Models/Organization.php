<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A customer of the system: one cooperative or lending organization with its own private data. */
class Organization extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['trial_ends_at' => 'date'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function onTrial(): bool
    {
        return $this->plan === 'trial';
    }

    /** Days left in the free trial (0 when it has ended). */
    public function trialDaysLeft(): int
    {
        return $this->trial_ends_at ? max(0, (int) ceil(now()->startOfDay()->diffInDays($this->trial_ends_at, false))) : 0;
    }

    public function trialExpired(): bool
    {
        return $this->onTrial() && $this->trial_ends_at && $this->trial_ends_at->lt(now()->startOfDay());
    }

    /** The limit for a resource on this plan (null means unlimited). */
    public function limit(string $resource): ?int
    {
        return config("coop.plans.{$this->plan}.limits.$resource");
    }

    /** A short, readable, unique Organization ID such as CS-7K4Q2M. */
    public static function generateCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no 0/O/1/I to avoid mix-ups

        do {
            $code = 'CS-'.substr(str_shuffle(str_repeat($alphabet, 3)), 0, 6);
        } while (static::where('code', $code)->exists());

        return $code;
    }

    /** Find an organization from what the user typed (any case, spaces allowed). */
    public static function findByCode(string $code): ?self
    {
        return static::where('code', Str::upper(str_replace(' ', '', trim($code))))->first();
    }
}
