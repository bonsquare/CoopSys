<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    public static function get(string $key, $default = null)
    {
        $value = static::where('key', $key)->value('value');

        return $value ?? $default ?? config("coop.defaults.$key");
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
