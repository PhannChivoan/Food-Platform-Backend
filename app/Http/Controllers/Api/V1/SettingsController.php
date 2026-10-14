<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingsController extends Controller
{
    private const BRAND_DISK = 'public';
    private const BRAND_DIR = 'platform/brand';

    public function index()
    {
        $settings = PlatformSetting::orderBy('group')->orderBy('key')->get();

        $grouped = $settings->groupBy('group')->map(function ($items) {
            return $items->mapWithKeys(function ($s) {
                return [$s->key => [
                    'value' => $s->value,
                    'type' => $s->type,
                ]];
            })->toArray();
        })->toArray();

        if (isset($grouped['general']['brand_logo']['value']) && $grouped['general']['brand_logo']['value']) {
            $grouped['general']['brand_logo']['url'] = asset('storage/' . $grouped['general']['brand_logo']['value']);
        }
        if (isset($grouped['general']['brand_favicon']['value']) && $grouped['general']['brand_favicon']['value']) {
            $grouped['general']['brand_favicon']['url'] = asset('storage/' . $grouped['general']['brand_favicon']['value']);
        }

        return response()->json(['settings' => $grouped]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'settings' => 'sometimes|array',
            'settings.*' => 'nullable',
            'brand_logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'brand_favicon' => 'nullable|image|mimes:jpg,jpeg,png,webp,ico|max:1024',
        ]);

        // Handle text/boolean settings
        $settingsData = $request->input('settings');
        // Fallback: parse JSON body for PUT requests
        if (empty($settingsData) && $request->isJson()) {
            $json = $request->json()->all();
            $settingsData = $json['settings'] ?? null;
        }
        if (!empty($settingsData) && is_array($settingsData)) {
            PlatformSetting::setValues($settingsData);
        }

        // Handle brand_logo upload
        if ($request->hasFile('brand_logo')) {
            $this->deleteFile('brand_logo');
            $path = $request->file('brand_logo')->store(self::BRAND_DIR, self::BRAND_DISK);
            PlatformSetting::updateOrCreate(
                ['key' => 'brand_logo'],
                ['value' => $path, 'type' => 'string', 'group' => 'general']
            );
        }

        // Handle brand_favicon upload
        if ($request->hasFile('brand_favicon')) {
            $this->deleteFile('brand_favicon');
            $path = $request->file('brand_favicon')->store(self::BRAND_DIR, self::BRAND_DISK);
            PlatformSetting::updateOrCreate(
                ['key' => 'brand_favicon'],
                ['value' => $path, 'type' => 'string', 'group' => 'general']
            );
        }

        // Handle brand image removal
        if ($request->input('remove_brand_logo') === '1') {
            $this->deleteFile('brand_logo');
            PlatformSetting::where('key', 'brand_logo')->update(['value' => null]);
        }
        if ($request->input('remove_brand_favicon') === '1') {
            $this->deleteFile('brand_favicon');
            PlatformSetting::where('key', 'brand_favicon')->update(['value' => null]);
        }

        return $this->indexResponse('Settings updated successfully');
    }

    private function deleteFile(string $key): void
    {
        $setting = PlatformSetting::where('key', $key)->first();
        if ($setting && $setting->value && !str_starts_with($setting->value, 'http')) {
            Storage::disk(self::BRAND_DISK)->delete($setting->value);
        }
    }

    private function indexResponse(string $message)
    {
        $settings = PlatformSetting::orderBy('group')->orderBy('key')->get();

        $grouped = $settings->groupBy('group')->map(function ($items) {
            return $items->mapWithKeys(function ($s) {
                return [$s->key => [
                    'value' => $s->value,
                    'type' => $s->type,
                ]];
            })->toArray();
        })->toArray();

        if (isset($grouped['general']['brand_logo']['value']) && $grouped['general']['brand_logo']['value']) {
            $grouped['general']['brand_logo']['url'] = asset('storage/' . $grouped['general']['brand_logo']['value']);
        }
        if (isset($grouped['general']['brand_favicon']['value']) && $grouped['general']['brand_favicon']['value']) {
            $grouped['general']['brand_favicon']['url'] = asset('storage/' . $grouped['general']['brand_favicon']['value']);
        }

        return response()->json([
            'message' => $message,
            'settings' => $grouped,
        ]);
    }
}
