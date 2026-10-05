<?php

namespace App\Models;

use App\Support\Tenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        // Not a global scope (the sign-in lookup itself loads users); lists are limited with inOrganization()
        static::creating(function (User $user) {
            if (! $user->organization_id && ($id = Tenant::id())) {
                $user->organization_id = $id;
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeInOrganization(Builder $query): Builder
    {
        return $query->where('users.organization_id', Tenant::id());
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function roleLabel(): string
    {
        return config('coop.roles.'.$this->role, ucfirst((string) $this->role));
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name));

        return strtoupper(substr($parts[0] ?? '', 0, 1).substr(end($parts) ?: '', 0, 1));
    }

    /** Can this user do $level (view/manage/...) in $module? Super Admin can always. */
    public function canAccess(string $module, string $level = 'view'): bool
    {
        if ($this->role === 'super_admin') {
            return true;
        }
        $allowed = config("coop.permissions.$module.$level", []);

        return in_array($this->role, $allowed, true);
    }
}
