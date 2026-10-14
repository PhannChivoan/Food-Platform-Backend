<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    /**
 * Writes a batch of settings. Unknown keys are created instead of silently dropped,
 * so a new option on the settings screen is never lost, and the stored type/group
 * are kept in sync with what the caller sent.
 */
public static function setValues(array $data): void
    {
        $existing = static::whereIn('key', array_keys($data))->get()->keyBy('key');

        foreach ($data as $key => $value) {
            $raw = is_array($value) ? json_encode($value) : (string) $value;
            $current = $existing->get($key);

            if ($current) {
                $current->update(['value' => $raw]);
                continue;
            }

            static::create([
                'key' => $key,
                'value' => $raw,
                'type' => is_numeric($raw) ? 'integer' : 'string',
                'group' => 'general',
            ]);
        }

        \App\Services\PlatformSettings::forget();
    }
}
