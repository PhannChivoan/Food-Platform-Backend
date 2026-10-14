<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Services\PlatformSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Platform settings.
 *
 * SCHEMA is the single source of truth for what /admin/settings can change: it
 * validates every value, stores it with the right type, and documents what the
 * option actually drives in the running system.
 */
class SettingsController extends Controller
{
    private const BRAND_DISK = 'public';
    private const BRAND_DIR = 'platform/brand';

    /** key => [type, group, validation rules] */
    private const SCHEMA = [
        'platform_name' => ['string', 'general', 'required|string|max:120'],
        'support_email' => ['string', 'general', 'nullable|email|max:160'],
        'timezone' => ['string', 'general', 'required|string|max:64|timezone'],
        'currency' => ['string', 'ordering', 'required|in:USD,KHR,THB,VND'],
        'prep_time_minutes' => ['integer', 'ordering', 'required|integer|min:1|max:240'],
        'auto_accept_orders' => ['boolean', 'ordering', 'nullable'],

        'order_alerts' => ['boolean', 'notifications', 'nullable'],
        'rider_alerts' => ['boolean', 'notifications', 'nullable'],
        'refund_alerts' => ['boolean', 'notifications', 'nullable'],

        'commission_rate' => ['number', 'payments', 'required|numeric|min:0|max:50'],
        'payment_provider' => ['string', 'payments', 'required|in:cod,aba,stripe,wing'],
        'cash_on_delivery' => ['boolean', 'payments', 'nullable'],
        'auto_settlement' => ['boolean', 'payments', 'nullable'],

        'maintenance_mode' => ['boolean', 'maintenance', 'nullable'],
    ];

    /** Live, public-facing settings for the storefront (no token required). */
    public function publicConfig()
    {
        return response()->json(['config' => PlatformSettings::publicConfig()]);
    }

    public function index()
    {
        return response()->json(['settings' => $this->grouped()]);
    }

    public function update(Request $request)
    {
        $incoming = $request->input('settings');

        // a PUT with a JSON body puts everything under "settings"
        if (empty($incoming) && $request->isJson()) {
            $incoming = $request->json()->all()['settings'] ?? null;
        }

        $incoming = is_array($incoming) ? $incoming : [];

        $unknown = array_values(array_diff(array_keys($incoming), array_keys(self::SCHEMA)));

        if ($unknown) {
            throw ValidationException::withMessages([
                'settings' => ['Unknown setting: ' . implode(', ', $unknown) . '.'],
            ]);
        }

        // validate every provided key with the rules declared in the schema
        $rules = [];
        foreach (array_keys($incoming) as $key) {
            $rules["settings.$key"] = self::SCHEMA[$key][2];
        }
        if ($rules) {
            $validated = $request->validate($rules);
        }

        $stored = [];
        foreach ($incoming as $key => $value) {
            [$type, $group] = self::SCHEMA[$key];
            $raw = $this->normalize($type, $value);

            PlatformSetting::updateOrCreate(
                ['key' => $key],
                ['value' => $raw, 'type' => $type, 'group' => $group],
            );

            $stored[$key] = $raw;
        }

        // ---- brand images -------------------------------------------------
        if ($request->hasFile('brand_logo')) {
            $this->deleteFile('brand_logo');
            $path = $request->file('brand_logo')->store(self::BRAND_DIR, self::BRAND_DISK);
            PlatformSetting::updateOrCreate(
                ['key' => 'brand_logo'],
                ['value' => $path, 'type' => 'string', 'group' => 'general'],
            );
        }

        if ($request->hasFile('brand_favicon')) {
            $this->deleteFile('brand_favicon');
            $path = $request->file('brand_favicon')->store(self::BRAND_DIR, self::BRAND_DISK);
            PlatformSetting::updateOrCreate(
                ['key' => 'brand_favicon'],
                ['value' => $path, 'type' => 'string', 'group' => 'general'],
            );
        }

        if ($request->input('remove_brand_logo') === '1') {
            $this->deleteFile('brand_logo');
            PlatformSetting::where('key', 'brand_logo')->update(['value' => null]);
        }
        if ($request->input('remove_brand_favicon') === '1') {
            $this->deleteFile('brand_favicon');
            PlatformSetting::where('key', 'brand_favicon')->update(['value' => null]);
        }

        // the values just changed: drop the cached copy so they take effect at once
        PlatformSettings::forget();

        return response()->json([
            'message' => 'Settings updated successfully.',
            'settings' => $this->grouped(),
            'stored' => $stored,
        ]);
    }

    /** Turns whatever the form sent into the exact string stored in the column. */
    private function normalize(string $type, $value): string
    {
        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false',
            'integer' => (string) (int) $value,
            'number' => (string) (float) $value,
            default => is_array($value) ? json_encode($value) : (string) $value,
        };
    }

    private function grouped(): array
    {
        $grouped = PlatformSetting::orderBy('group')->orderBy('key')->get()
            ->groupBy('group')
            ->map(fn ($items) => $items->mapWithKeys(fn ($s) => [
                $s->key => ['value' => $s->value, 'type' => $s->type],
            ])->toArray())
            ->toArray();

        foreach (['brand_logo', 'brand_favicon'] as $key) {
            $value = $grouped['general'][$key]['value'] ?? null;
            $grouped['general'][$key]['url'] = $value ? asset('storage/' . $value) : null;
        }

        return $grouped;
    }

    private function deleteFile(string $key): void
    {
        $setting = PlatformSetting::where('key', $key)->first();
        if ($setting && $setting->value && !str_starts_with($setting->value, 'http')) {
            Storage::disk(self::BRAND_DISK)->delete($setting->value);
        }
    }
}