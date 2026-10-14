<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Server-side carts for the customer storefront. One cart per restaurant;
 * every cart is checked out separately. Uses the query builder because the
 * Eloquent tenant scopes hide restaurant data from customer accounts.
 */
class CartController extends Controller
{
    private function key(array $ids): string
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        return implode(',', $ids);
    }

    /** Only modifiers that really belong to this dish are accepted. */
    private function validModifierIds(int $menuItemId, array $ids): array
    {
        if (!$ids) return [];
        return DB::table('modifiers')
            ->join('menu_item_modifier_groups', 'menu_item_modifier_groups.modifier_group_id', '=', 'modifiers.modifier_group_id')
            ->where('menu_item_modifier_groups.menu_item_id', $menuItemId)
            ->whereIn('modifiers.id', $ids)
            ->pluck('modifiers.id')->map(fn ($i) => (int) $i)->all();
    }

    /** Full cart state for the user: [{restaurant, items, subtotal, count}]. */
    /**
     * Who owns the cart: the signed-in customer, or an anonymous guest identified by the X-Guest-Token header.
     * (The Sanctum token is read here directly so guests can use the same endpoints.)
     */
    private function owner(Request $request): array
    {
        if ($user = $request->user('sanctum')) {
            return ['user_id' => $user->id];
        }
        $token = (string) $request->header('X-Guest-Token');
        abort_unless(preg_match('/^[A-Za-z0-9\-]{16,64}$/', $token), 401, 'Missing cart session.');
        return ['guest_token' => $token];
    }

    private function normalize(int|array $owner): array
    {
        return is_int($owner) ? ['user_id' => $owner] : $owner;
    }

    public function payload(int|array $owner): array
    {
        $owner = $this->normalize($owner);
        $carts = DB::table('carts')->where($owner)->orderByDesc('updated_at')->get();
        if ($carts->isEmpty()) return [];

        $restaurants = DB::table('restaurants')->whereIn('id', $carts->pluck('restaurant_id'))
            ->whereNull('deleted_at')->get()->keyBy('id');
        $rows = DB::table('cart_items')->whereIn('cart_id', $carts->pluck('id'))->orderBy('id')->get();
        $dishes = DB::table('menu_items')->whereIn('id', $rows->pluck('menu_item_id'))->get()->keyBy('id');

        $modIds = $rows->flatMap(fn ($r) => json_decode($r->selected_modifiers ?? '[]', true) ?: [])->unique()->all();
        $mods = DB::table('modifiers')->whereIn('id', $modIds)->get()->keyBy('id');

        $branchNames = DB::table('branches')->whereIn('id', $dishes->pluck('branch_id')->filter()->unique())->pluck('name', 'id');
        $out = [];
        foreach ($carts as $cart) {
            $r = $restaurants->get($cart->restaurant_id);
            if (!$r) continue;
            $items = [];
            $subtotal = 0;
            $count = 0;
            $cartBranch = null;
            foreach ($rows->where('cart_id', $cart->id) as $row) {
                $d = $dishes->get($row->menu_item_id);
                if (!$d) continue;
                $chosen = collect(json_decode($row->selected_modifiers ?? '[]', true) ?: [])
                    ->map(fn ($id) => $mods->get($id))->filter()->values();
                $unit = (float) ($d->discounted_price ?? $d->price) + (float) $chosen->sum('price_adjustment');
                $available = (bool) $d->is_available;
                $cartBranch ??= $d->branch_id;
                $line = $unit * $row->quantity;
                if ($available) {
                    $subtotal += $line;
                    $count += $row->quantity;
                }
                $items[] = [
                    'id' => $row->id,
                    'menu_item_id' => $d->id,
                    'name' => $d->name,
                    'options' => $chosen->pluck('name')->all(),
                    'modifier_ids' => $chosen->pluck('id')->all(),
                    'unit_price' => round($unit, 2),
                    'quantity' => (int) $row->quantity,
                    'line_total' => round($line, 2),
                    'image_url' => $d->image ? asset('storage/' . $d->image) : null,
                    'available' => $available,
                ];
            }
            $out[] = [
                'restaurant' => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'cuisine_type' => $r->cuisine_type,
                    'logo_url' => $r->logo ? asset('storage/' . $r->logo) : null,
                ],
                'branch' => $cartBranch ? ['id' => (int) $cartBranch, 'name' => $branchNames[$cartBranch] ?? null] : null,
                'items' => $items,
                'subtotal' => round($subtotal, 2),
                'count' => $count,
            ];
        }
        return $out;
    }

    public function index(Request $request)
    {
        return response()->json(['data' => $this->payload($this->owner($request))]);
    }

    public function addLine(int|array $owner, int $menuItemId, int $qty, array $modIds): void
    {
        $owner = $this->normalize($owner);
        $dish = DB::table('menu_items')->where('id', $menuItemId)->where('is_available', true)->first();
        abort_unless($dish, 422, 'This dish is not available right now.');
        $restaurant = DB::table('restaurants')->where('id', $dish->restaurant_id)
            ->where('status', 'verified')->whereNull('deleted_at')->first();
        abort_unless($restaurant, 422, 'This restaurant is not accepting orders.');

        $modIds = $this->validModifierIds($menuItemId, $modIds);
        $key = $this->key($modIds);
        $now = now();

        $cart = DB::table('carts')->where($owner)->where('restaurant_id', $dish->restaurant_id)->first();
        if ($cart && $dish->branch_id) {
            // a cart is served by one branch: dishes of different branches (different menus) cannot share an order
            $otherBranch = DB::table('cart_items')->join('menu_items', 'menu_items.id', '=', 'cart_items.menu_item_id')
                ->where('cart_items.cart_id', $cart->id)->whereNotNull('menu_items.branch_id')
                ->where('menu_items.branch_id', '!=', $dish->branch_id)->exists();
            abort_if($otherBranch, 422, 'Your cart has dishes from another branch of this restaurant. Finish or clear that cart first, then order from this branch.');
        }
        $cartId = $cart->id ?? DB::table('carts')->insertGetId($owner + [
            'user_id' => null, 'guest_token' => null, 'restaurant_id' => $dish->restaurant_id, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $line = DB::table('cart_items')->where('cart_id', $cartId)
            ->where('menu_item_id', $menuItemId)->where('modifiers_key', $key)->first();
        if ($line) {
            DB::table('cart_items')->where('id', $line->id)
                ->update(['quantity' => min(99, $line->quantity + $qty), 'updated_at' => $now]);
        } else {
            DB::table('cart_items')->insert([
                'cart_id' => $cartId, 'menu_item_id' => $menuItemId, 'quantity' => min(99, $qty),
                'selected_modifiers' => json_encode($modIds), 'modifiers_key' => $key,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        DB::table('carts')->where('id', $cartId)->update(['updated_at' => $now]);
    }

    public function addItem(Request $request)
    {
        $v = $request->validate([
            'menu_item_id' => 'required|integer|exists:menu_items,id',
            'quantity' => 'nullable|integer|min:1|max:50',
            'selected_modifiers' => 'nullable|array',
            'selected_modifiers.*' => 'integer',
        ]);
        $owner = $this->owner($request);
        $this->addLine($owner, $v['menu_item_id'], $v['quantity'] ?? 1, $v['selected_modifiers'] ?? []);
        return response()->json(['data' => $this->payload($owner)]);
    }

    /** After login: move the guest cart (identified by its token) into the customer's own cart. */
    public function merge(Request $request)
    {
        $v = $request->validate(['guest_token' => ['required', 'regex:/^[A-Za-z0-9\-]{16,64}$/']]);
        $userId = $request->user()->id;

        $guestCarts = DB::table('carts')->where('guest_token', $v['guest_token'])->pluck('id');
        foreach (DB::table('cart_items')->whereIn('cart_id', $guestCarts)->orderBy('id')->get() as $line) {
            try {
                $this->addLine($userId, (int) $line->menu_item_id, (int) $line->quantity, json_decode($line->selected_modifiers ?? '[]', true) ?: []);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                // dish went away since it was added; skip it
            }
        }
        DB::table('carts')->whereIn('id', $guestCarts)->delete(); // cart_items cascade
        return response()->json(['data' => $this->payload($userId)]);
    }
    private function ownLine(Request $request, int $id)
    {
        $line = DB::table('cart_items')->join('carts', 'carts.id', '=', 'cart_items.cart_id')
            ->where('cart_items.id', $id)
            ->where(collect($this->owner($request))->mapWithKeys(fn ($v, $k) => ['carts.' . $k => $v])->all())
            ->select('cart_items.id', 'cart_items.cart_id')->first();
        abort_unless($line, 404, 'Cart item not found');
        return $line;
    }

    public function updateItem(Request $request, int $id)
    {
        $v = $request->validate(['quantity' => 'required|integer|min:0|max:99']);
        $line = $this->ownLine($request, $id);
        if ($v['quantity'] === 0) {
            return $this->removeItem($request, $id);
        }
        DB::table('cart_items')->where('id', $line->id)->update(['quantity' => $v['quantity'], 'updated_at' => now()]);
        return response()->json(['data' => $this->payload($this->owner($request))]);
    }

    public function removeItem(Request $request, int $id)
    {
        $line = $this->ownLine($request, $id);
        DB::table('cart_items')->where('id', $line->id)->delete();
        if (!DB::table('cart_items')->where('cart_id', $line->cart_id)->exists()) {
            DB::table('carts')->where('id', $line->cart_id)->delete();
        }
        return response()->json(['data' => $this->payload($this->owner($request))]);
    }

    public function clear(Request $request, int $restaurantId)
    {
        $owner = $this->owner($request);
        DB::table('carts')->where($owner)->where('restaurant_id', $restaurantId)->delete();
        return response()->json(['data' => $this->payload($owner)]);
    }

    /** Preview a promo code against one cart (nothing is used up until the order is placed). */
    public function coupon(Request $request, int $restaurantId)
    {
        $v = $request->validate(['code' => 'required|string|max:50']);
        $user = $request->user();
        $cart = collect($this->payload($user->id))->firstWhere('restaurant.id', $restaurantId);
        abort_unless($cart, 404, 'This cart is empty.');

        $r = \App\Services\CouponService::evaluate($v['code'], $user->id, $restaurantId, (float) $cart['subtotal']);
        if (!$r['ok']) {
            return response()->json(['valid' => false, 'message' => $r['message']], 422);
        }
        return response()->json([
            'valid' => true, 'message' => $r['message'], 'discount' => $r['discount'],
            'code' => $r['promotion']->code, 'name' => $r['promotion']->name, 'description' => $r['promotion']->description,
        ]);
    }

    /** Turn one restaurant's cart into an order. Other carts are untouched. */
    public function checkout(Request $request, int $restaurantId)
    {
        $v = $request->validate([
            'phone' => ['required', 'string', 'min:6', 'max:20', 'regex:/^[0-9+\-\s()]+$/'],
            'address_id' => 'nullable|integer',
            'address_details' => 'nullable|string|max:255', // floor / house number for this delivery
            'coupon_code' => 'nullable|string|max:50',
            'address' => 'required_without:address_id|nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'address_label' => 'nullable|string|max:50',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'payment_method' => 'required|in:cash,aba_khqr,card',
            'notes' => 'nullable|string|max:500',
        ]);

        $user = $request->user();

        // ── platform settings that actually gate a new order ──
        abort_if(
            \App\Services\PlatformSettings::bool('maintenance_mode'),
            503,
            'Ordering is paused for maintenance. Please try again shortly.'
        );

        $allowedMethods = \App\Services\PlatformSettings::paymentMethods();
        abort_if(
            !in_array($v['payment_method'], $allowedMethods, true),
            422,
            'This payment method is not available right now.'
        );

        if (!empty($v['address_id'])) {
            abort_unless(
                DB::table('customer_addresses')->where('id', $v['address_id'])->where('user_id', $user->id)->where('is_hidden', false)->exists(),
                422,
                'Please choose one of your saved addresses.'
            );
        }
        $cart = collect($this->payload($user->id))->firstWhere('restaurant.id', $restaurantId);
        abort_unless($cart, 404, 'This cart is empty.');

        $items = collect($cart['items'])->where('available', true)->values();
        abort_if($items->isEmpty(), 422, 'None of the dishes in this cart are available any more.');

        $dishBranch = DB::table('menu_items')->whereIn('id', $items->pluck('menu_item_id'))->whereNotNull('branch_id')->value('branch_id');
        $branch = $dishBranch
            ? DB::table('branches')->where('id', $dishBranch)->where('restaurant_id', $restaurantId)->where('is_active', true)->first()
            : DB::table('branches')->where('restaurant_id', $restaurantId)->where('is_active', true)->orderByDesc('is_main')->orderBy('id')->first();
        abort_unless($branch, 422, 'The branch for this order is closed right now.');

        return DB::transaction(function () use ($v, $user, $restaurantId, $items, $branch, $cart) {
            $addressId = $v['address_id'] ?? null;
            if (!$addressId) {
                $lat = (float) ($v['latitude'] ?? 0);
                $lng = (float) ($v['longitude'] ?? 0);
                $addressId = DB::table('customer_addresses')->insertGetId([
                    'user_id' => $user->id,
                    'label' => $v['address_label'] ?? 'Delivery',
                    'address' => $v['address'],
                    'details' => $v['address_details'] ?? null,
                    'city' => $v['city'] ?? '',
                    'postal_code' => $v['postal_code'] ?? null,
                    'location' => DB::raw("ST_GeomFromText('POINT({$lng} {$lat})')"),
                    'is_default' => false,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            // keep the floor / house number on the saved address for next time
            if (!empty($v['address_id']) && array_key_exists('address_details', $v)) {
                DB::table('customer_addresses')->where('id', $addressId)->update(['details' => $v['address_details'], 'updated_at' => now()]);
            }

            if (!$user->phone) {
                DB::table('users')->where('id', $user->id)->update(['phone' => $v['phone']]);
            }

            $subtotal = (float) $items->sum('line_total');

            // promo code (re-checked here; the checkout page only previews it)
            $coupon = null;
            if (!empty($v['coupon_code'])) {
                $coupon = \App\Services\CouponService::evaluate($v['coupon_code'], $user->id, $restaurantId, $subtotal);
                abort_unless($coupon['ok'], 422, $coupon['message']);
            }
            $discount = $coupon['discount'] ?? 0.0;
            $split = \App\Services\Commission::split($restaurantId, $subtotal);
            // a restaurant's own coupon comes out of its payout; a platform-wide coupon is paid by the platform
            if ($coupon && $coupon['promotion']->restaurant_id) {
                $split['restaurant_payout'] = round($split['restaurant_payout'] - $discount, 2);
            }

            // auto-accept (ordering.auto_accept_orders) skips the pending queue
            $autoAccept = \App\Services\PlatformSettings::bool('auto_accept_orders', true);

            $order = Order::create([
                'order_number' => 'ORD-' . now()->format('Ymd') . '-' . Str::random(6),
                'customer_id' => $user->id,
                'restaurant_id' => $restaurantId,
                'branch_id' => $branch->id,
                'delivery_address_id' => $addressId,
                'status' => $autoAccept ? 'accepted' : 'pending',
                'accepted_at' => $autoAccept ? now() : null,
                'subtotal' => $subtotal,
                'delivery_fee' => 0,
                'surge_fee' => 0,
                'discount' => $discount,
                'tax' => 0,
                'total' => round($subtotal - $discount, 2),
                'payment_method' => $v['payment_method'],
                'is_paid' => false,
                'customer_notes' => $v['notes'] ?? null,
                // platform commission (no subscription): deducted from the food subtotal, the rest is the restaurant's payout
                'meta' => ['contact_phone' => $v['phone'], 'unit_details' => $v['address_details'] ?? null]
                    + $split
                    + ($coupon ? ['coupon' => ['code' => $coupon['promotion']->code, 'discount' => $discount, 'funded_by' => $coupon['promotion']->restaurant_id ? 'restaurant' : 'platform']] : []),
            ]);

            $created = [];
            foreach ($items as $i) {
                $created[] = OrderItem::create([
                    'order_id' => $order->id,
                    'menu_item_id' => $i['menu_item_id'],
                    'item_name' => $i['name'],
                    'unit_price' => $i['unit_price'],
                    'quantity' => $i['quantity'],
                    'selected_modifiers' => collect($i['modifier_ids'])->map(fn ($id) => ['id' => $id])->all(),
                    'subtotal' => $i['line_total'],
                ]);
            }

            if ($coupon) {
                DB::table('promotion_redemptions')->insert([
                    'promotion_id' => $coupon['promotion']->id, 'user_id' => $user->id, 'order_id' => $order->id,
                    'code_used' => $coupon['promotion']->code, 'discount_amount' => $discount, 'order_total' => $subtotal,
                    'status' => 'success', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            DB::table('carts')->where('user_id', $user->id)->where('restaurant_id', $restaurantId)->delete();

            return response()->json([
                'order' => array_merge($order->toArray(), ['items' => $created]),
                'data' => $this->payload($user->id),
            ], 201);
        });
    }
}
