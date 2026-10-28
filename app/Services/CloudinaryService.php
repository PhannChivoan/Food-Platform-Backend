<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Minimal Cloudinary client over the REST API (signed uploads), no SDK needed.
 */
class CloudinaryService
{
    public static function upload(UploadedFile $file, string $folder): string
    {
        $cfg = self::config();
        $params = ['folder' => $folder, 'timestamp' => time()];

        $response = Http::asMultipart()
            ->attach('file', fopen($file->getRealPath(), 'r'), $file->getClientOriginalName())
            ->post("https://api.cloudinary.com/v1_1/{$cfg['cloud_name']}/image/upload", [
                ...$params,
                'api_key' => $cfg['api_key'],
                'signature' => self::sign($params, $cfg['api_secret']),
            ]);

        if (! $response->successful() || ! $response->json('secure_url')) {
            Log::error('Cloudinary upload failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new RuntimeException('Image upload failed.');
        }

        return $response->json('secure_url');
    }

    /** Public URL for a stored value: Cloudinary URLs pass through, legacy local paths map to /storage. */
    public static function url(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        return str_starts_with($value, 'http') ? $value : asset('storage/'.ltrim($value, '/'));
    }

    /** Delete a stored value from Cloudinary, or from the local public disk for legacy paths. */
    public static function remove(?string $value): void
    {
        if (! $value) {
            return;
        }

        if (self::isCloudinaryUrl($value)) {
            self::delete($value);
        } elseif (! str_starts_with($value, 'http')) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete(ltrim(preg_replace('#^/?storage/#', '', $value), '/'));
        }
    }

    public static function isCloudinaryUrl(?string $value): bool
    {
        return $value && str_contains($value, 'res.cloudinary.com/') && str_contains($value, '/upload/');
    }

    public static function delete(?string $url): void
    {
        if (! self::isCloudinaryUrl($url)) {
            return;
        }

        // .../upload/[transformations/][v123/]folder/name.ext -> folder/name
        $path = explode('/upload/', $url, 2)[1];
        $path = preg_replace('#^(?:[^/]+/)*?v\d+/#', '', $path);
        $publicId = preg_replace('/\.[^.\/]+$/', '', $path);

        $cfg = self::config();
        $params = ['public_id' => $publicId, 'timestamp' => time()];

        $response = Http::asForm()->post("https://api.cloudinary.com/v1_1/{$cfg['cloud_name']}/image/destroy", [
            ...$params,
            'api_key' => $cfg['api_key'],
            'signature' => self::sign($params, $cfg['api_secret']),
        ]);

        if (! $response->successful()) {
            Log::warning('Cloudinary delete failed', ['public_id' => $publicId, 'body' => $response->body()]);
        }
    }

    private static function sign(array $params, string $secret): string
    {
        ksort($params);

        return sha1(urldecode(http_build_query($params)).$secret);
    }

    private static function config(): array
    {
        $cfg = config('services.cloudinary');
        if (! $cfg['cloud_name'] || ! $cfg['api_key'] || ! $cfg['api_secret']) {
            throw new RuntimeException('Cloudinary is not configured.');
        }

        return $cfg;
    }
}
