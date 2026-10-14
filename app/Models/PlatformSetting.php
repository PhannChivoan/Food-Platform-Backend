<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class PlatformSetting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'group'];

    public function getValueAttribute($raw)
    {
        return match ($this->type) {
            'boolean' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $raw,
            'json' => json_decode($raw, true),
            default => $raw,
        };
    }

    public static function getGroup(string $group): array
    {
        return static::where('group', $group)
            ->get()
            ->pluck('value', 'key')
            ->toArray();
    }

    public static function setValues(array $data): void
    {
        foreach ($data as $key => $value) {
            $raw = is_array($value) ? json_encode($value) : (string) $value;
            DB::table('platform_settings')->where('key', $key)->update([
                'value' => $raw,
                'updated_at' => now(),
            ]);
        }
    }
}
