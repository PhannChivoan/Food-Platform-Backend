<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CloudinaryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Landing page content (Administration -> Landing Page).
 *
 * The storefront pages keep their own layout and wording; this stores replacements for their fixed
 * texts and images, keyed by the original:
 *   texts  : "<original English text>" => ["en" => "...", "km" => "..."]   (either side optional)
 *   images : "<original image src>"    => "<replacement url>"
 *   lists  : "<list id>"               => [["from" => <index of the original card>, "fields" => ["<original text>" => ["en" => ..., "km" => ...]]], ...]
 * Texts and images are swapped in when the storefront loads. A list is a group of cards: a list that was never
 * saved shows the page's own cards, a saved one shows exactly the cards in it (so cards can be added, removed,
 * reordered and edited). An empty document shows the site exactly as built.
 */
class LandingContentController extends Controller
{
    private const CACHE_KEY = 'landing_content:v1';
    private const IMAGE_DIR = 'platform/landing';
    private const MAX_ENTRIES = 4000;
    private const MAX_KEY = 1000;
    private const MAX_TEXT = 5000;
    private const MAX_URL = 1000;
    private const MAX_LISTS = 100;
    private const MAX_CARDS = 60;
    private const MAX_FIELDS = 40;

    /** Storefront: the replacements to apply (no token required). */
    public function publicShow()
    {
        return response()
            ->json(['content' => $this->present()])
            ->header('Cache-Control', 'public, max-age=30');
    }

    /** Admin editor: the same document plus who saved it last. */
    public function show()
    {
        $row = DB::table('landing_contents')->orderBy('id')->first();

        return response()->json([
            'content' => $this->present(),
            'updated_at' => $row?->updated_at,
            'updated_by' => $row?->updated_by ? DB::table('users')->where('id', $row->updated_by)->value('name') : null,
        ]);
    }

    public function update(Request $request)
    {
        $content = [
            'texts' => $this->cleanTexts($request->input('texts', [])),
            'images' => $this->cleanImages($request->input('images', [])),
            'lists' => $this->cleanLists($request->input('lists', [])),
        ];

        $this->persist($content, $request->user()?->id);

        return response()->json([
            'message' => 'Landing page content saved.',
            'content' => $this->present(),
            'updated_at' => now(),
            'updated_by' => $request->user()?->name,
        ]);
    }

    /**
     * One card group (a landing page module such as "home.categories"): saves its records and leaves every
     * other part of the document as it is, so two admins on different modules never overwrite each other.
     */
    public function updateList(Request $request, string $id)
    {
        $cards = $request->input('cards', []);
        $clean = $this->cleanLists([$id => $cards]);

        if (! array_key_exists($id, $clean)) {
            throw ValidationException::withMessages(['cards' => ['Unknown landing page module.']]);
        }

        return $this->writeList($request, $id, $clean[$id], 'Saved.');
    }

    /** Back to the cards the page was built with (the module is no longer customized). */
    public function resetList(Request $request, string $id)
    {
        return $this->writeList($request, $id, null, 'Back to the original cards.');
    }

    private function writeList(Request $request, string $id, ?array $cards, string $message)
    {
        $content = $this->read();
        if ($cards === null) {
            unset($content['lists'][$id]);
        } else {
            $content['lists'][$id] = $cards;
        }

        $this->persist($content, $request->user()?->id);

        return response()->json([
            'message' => $message,
            'content' => $this->present(),
            'updated_at' => now(),
            'updated_by' => $request->user()?->name,
        ]);
    }

    /** Stores the whole document (one row). */
    private function persist(array $content, ?int $userId): void
    {
        $now = now();
        $values = ['content' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_by' => $userId, 'updated_at' => $now];
        $id = DB::table('landing_contents')->orderBy('id')->value('id');

        if ($id) {
            DB::table('landing_contents')->where('id', $id)->update($values);
        } else {
            DB::table('landing_contents')->insert($values + ['created_at' => $now]);
        }

        Cache::forget(self::CACHE_KEY);
    }

    /** Uploads a replacement image and returns its public URL (it is only used once the editor is saved). */
    public function uploadImage(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        $path = CloudinaryService::upload($request->file('image'), self::IMAGE_DIR);

        return response()->json(['url' => CloudinaryService::url($path)]);
    }

    /** @return array{texts: array<string, array<string, string>>, images: array<string, string>} */
    private function read(): array
    {
        return Cache::remember(self::CACHE_KEY, 300, function () {
            $raw = DB::table('landing_contents')->orderBy('id')->value('content');
            $data = $raw ? json_decode($raw, true) : null;

            return [
                'texts' => is_array($data['texts'] ?? null) ? $data['texts'] : [],
                'images' => is_array($data['images'] ?? null) ? $data['images'] : [],
                'lists' => is_array($data['lists'] ?? null) ? $data['lists'] : [],
            ];
        });
    }

    /** As sent to the browser: objects, never lists (an empty PHP array would otherwise become []). */
    private function present(): array
    {
        $content = $this->read();

        return [
            'texts' => (object) $content['texts'],
            'images' => (object) $content['images'],
            'lists' => (object) $content['lists'],
        ];
    }

    private function cleanTexts($texts): array
    {
        if (! is_array($texts)) {
            throw ValidationException::withMessages(['texts' => ['Texts must be a list of replacements.']]);
        }
        if (count($texts) > self::MAX_ENTRIES) {
            throw ValidationException::withMessages(['texts' => ['Too many text replacements.']]);
        }

        $clean = [];
        foreach ($texts as $original => $value) {
            $original = trim((string) $original);
            if ($original === '' || mb_strlen($original) > self::MAX_KEY || ! is_array($value)) {
                continue;
            }

            $entry = [];
            foreach (['en', 'km'] as $lang) {
                $text = isset($value[$lang]) && is_scalar($value[$lang]) ? trim((string) $value[$lang]) : '';
                if ($text === '') {
                    continue;
                }
                if (mb_strlen($text) > self::MAX_TEXT) {
                    throw ValidationException::withMessages(['texts' => ["A replacement for \"" . mb_substr($original, 0, 40) . "\" is too long."]]);
                }
                // plain text only: the storefront writes it into a text node, markup would show as typed
                $entry[$lang] = strip_tags($text);
            }

            if ($entry) {
                $clean[$original] = $entry;
            }
        }

        return $clean;
    }

    /** @return array<string, array<int, array{from:int, fields: array<string, array<string,string>>}>> */
    private function cleanLists($lists): array
    {
        if (! is_array($lists)) {
            throw ValidationException::withMessages(['lists' => ['Lists must be a set of card groups.']]);
        }
        if (count($lists) > self::MAX_LISTS) {
            throw ValidationException::withMessages(['lists' => ['Too many card groups.']]);
        }

        $clean = [];
        foreach ($lists as $id => $cards) {
            $id = trim((string) $id);
            if (! preg_match('/^[a-z0-9][a-z0-9._-]{0,59}$/i', $id) || ! is_array($cards)) {
                continue;
            }
            if (count($cards) > self::MAX_CARDS) {
                throw ValidationException::withMessages(['lists' => ["\"$id\" has more than " . self::MAX_CARDS . ' cards.']]);
            }

            $kept = [];
            foreach (array_values($cards) as $card) {
                if (! is_array($card) || ! isset($card['from']) || ! is_numeric($card['from'])) {
                    continue;
                }
                $from = (int) $card['from'];
                if ($from < 0 || $from > 99) {
                    continue;
                }

                $fields = is_array($card['fields'] ?? null) ? $card['fields'] : [];
                if (count($fields) > self::MAX_FIELDS) {
                    throw ValidationException::withMessages(['lists' => ["A card in \"$id\" has too many fields."]]);
                }

                $cleanFields = $this->cleanTexts($fields);
                // image fields (keyed by the original address) must hold a safe web address
                foreach ($cleanFields as $key => $value) {
                    if (preg_match('#^(/|https?://)#', $key) && isset($value['en']) && ! preg_match('#^(https://|http://|/)[^\s"\'<>]+$#', $value['en'])) {
                        throw ValidationException::withMessages(['lists' => ['"' . mb_substr($value['en'], 0, 60) . '" is not a valid image address.']]);
                    }
                }

                // the card's link: a site route ("/partner/register"), a full address or mail / phone, never script
                $link = $cleanFields['__url']['en'] ?? null;
                if ($link !== null && ! preg_match('#^(/(?!/)[^\s"\'<>]*|https?://[^\s"\'<>]+|mailto:[^\s"\'<>]+|tel:[^\s"\'<>]+|\#)$#', $link)) {
                    throw ValidationException::withMessages(['lists' => ['"' . mb_substr($link, 0, 60) . '" is not a valid link. Use a page like /partner/register, or a full address starting with https://.']]);
                }
                if (isset($cleanFields['__url'])) {
                    $cleanFields['__url'] = ['en' => $link]; // a link has no Khmer version
                }

                $kept[] = ['from' => $from, 'fields' => $cleanFields];
            }

            // an empty list is valid: it means every card was removed
            $clean[$id] = $kept;
        }

        return $clean;
    }

    private function cleanImages($images): array
    {
        if (! is_array($images)) {
            throw ValidationException::withMessages(['images' => ['Images must be a list of replacements.']]);
        }
        if (count($images) > self::MAX_ENTRIES) {
            throw ValidationException::withMessages(['images' => ['Too many image replacements.']]);
        }

        $clean = [];
        foreach ($images as $original => $url) {
            $original = trim((string) $original);
            $url = is_scalar($url) ? trim((string) $url) : '';
            if ($original === '' || $url === '' || mb_strlen($original) > self::MAX_KEY) {
                continue;
            }
            // only web addresses or site paths: never javascript: / data: URLs
            if (mb_strlen($url) > self::MAX_URL || ! preg_match('#^(https://|http://|/)[^\s"\'<>]+$#', $url)) {
                throw ValidationException::withMessages(['images' => ['"' . mb_substr($url, 0, 60) . '" is not a valid image address.']]);
            }
            $clean[$original] = $url;
        }

        return $clean;
    }
}
