<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Models\LoyaltyTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PromotionController extends Controller
{
    public function index(Request $request)
    {
        $query = Promotion::with('restaurant');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('restaurant_id')) {
            $query->where('restaurant_id', $request->restaurant_id);
        }

        $query->orderBy('created_at', 'desc');

        $promotions = $query->paginate($request->get('limit', 15));

        $all = Promotion::all();
        $stats = [
            'total' => $all->count(),
            'active' => $all->filter(fn($p) => $p->is_active && $p->starts_at <= now() && $p->expires_at >= now())->count(),
            'expired' => $all->filter(fn($p) => $p->expires_at < now())->count(),
            'inactive' => $all->filter(fn($p) => !$p->is_active)->count(),
        ];

        return response()->json([
            'data' => $promotions->items(),
            'total' => $promotions->total(),
            'page' => $promotions->currentPage(),
            'limit' => $promotions->perPage(),
            'stats' => $stats,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'restaurant_id' => 'nullable|exists:restaurants,id',
            'name' => 'required|string',
            'code' => 'required|string|unique:promotions',
            'type' => 'required|in:percentage,fixed',
            'value' => 'required|numeric|min:0',
            'max_discount' => 'nullable|numeric|min:0',
            'usage_limit' => 'nullable|integer|min:1',
            'usage_limit_per_user' => 'nullable|integer|min:1',
            'stackable' => 'sometimes|boolean',
            'starts_at' => 'required|date',
            'expires_at' => 'required|date|after:starts_at',
            'description' => 'nullable|string',
            'applicable_items' => 'nullable|array',
        ]);
        $promotion = Promotion::create($validated);
        return response()->json($promotion, 201);
    }

    public function show(Promotion $promotion)
    {
        return response()->json($promotion);
    }

    public function update(Request $request, Promotion $promotion)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string',
            'code' => 'sometimes|string|unique:promotions,code,'.$promotion->id,
            'type' => 'sometimes|in:percentage,fixed',
            'value' => 'sometimes|numeric|min:0',
            'max_discount' => 'nullable|numeric|min:0',
            'usage_limit' => 'nullable|integer|min:1',
            'usage_limit_per_user' => 'nullable|integer|min:1',
            'stackable' => 'sometimes|boolean',
            'starts_at' => 'sometimes|date',
            'expires_at' => 'sometimes|date|after:starts_at',
            'description' => 'nullable|string',
            'applicable_items' => 'nullable|array',
            'is_active' => 'sometimes|boolean',
        ]);
        $promotion->update($validated);
        return response()->json($promotion);
    }

    /** Switch many promotions on / off at once. */
    public function bulkUpdate(Request $request)
    {
        $v = $request->validate([
            'ids' => 'required|array|min:1|max:500', 'ids.*' => 'integer',
            'action' => 'required|in:activate,deactivate',
        ]);
        $count = Promotion::whereIn('id', $v['ids'])->update(['is_active' => $v['action'] === 'activate']);

        return response()->json(['message' => "Updated {$count} promotion(s).", 'count' => $count]);
    }

    public function bulkDelete(Request $request)
    {
        $v = $request->validate(['ids' => 'required|array|min:1|max:500', 'ids.*' => 'integer']);
        $count = Promotion::whereIn('id', $v['ids'])->delete();

        return response()->json(['message' => "Deleted {$count} promotion(s).", 'count' => $count]);
    }

    public function destroy(Promotion $promotion)
    {
        $promotion->delete();
        return response()->json(['message' => 'Promotion deleted']);
    }

    public function validateCode(Request $request)
    {
        $request->validate(['code' => 'required|string', 'order_total' => 'required|numeric']);
        $promotion = Promotion::where('code', $request->code)
                              ->where('is_active', true)
                              ->where('starts_at', '<=', now())
                              ->where('expires_at', '>=', now())
                              ->first();
        if (!$promotion) {
            return response()->json(['valid' => false, 'message' => 'Invalid or expired code']);
        }
        // Check usage limits, per-user limits, etc.
        return response()->json(['valid' => true, 'promotion' => $promotion]);
    }

    public function redeem(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string',
            'order_id' => 'nullable|exists:orders,id',
            'order_total' => 'required|numeric|min:0',
        ]);

        $promotion = Promotion::where('code', $validated['code'])
                              ->where('is_active', true)
                              ->where('starts_at', '<=', now())
                              ->where('expires_at', '>=', now())
                              ->first();

        if (!$promotion) {
            $redemption = PromotionRedemption::create([
                'promotion_id' => null,
                'user_id' => auth()->id(),
                'order_id' => $validated['order_id'] ?? null,
                'code_used' => $validated['code'],
                'discount_amount' => 0,
                'order_total' => $validated['order_total'],
                'status' => 'failed',
                'failure_reason' => 'Invalid or expired promo code',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired promo code',
                'redemption_id' => $redemption->id,
            ], 422);
        }

        // Check usage limit
        if ($promotion->usage_limit) {
            $usedCount = $promotion->redemptions()->successful()->count();
            if ($usedCount >= $promotion->usage_limit) {
                $redemption = PromotionRedemption::create([
                    'promotion_id' => $promotion->id,
                    'user_id' => auth()->id(),
                    'order_id' => $validated['order_id'] ?? null,
                    'code_used' => $validated['code'],
                    'discount_amount' => 0,
                    'order_total' => $validated['order_total'],
                    'status' => 'failed',
                    'failure_reason' => 'Usage limit reached',
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'This promotion has reached its usage limit',
                    'redemption_id' => $redemption->id,
                ], 422);
            }
        }

        // Check per-user limit
        if ($promotion->usage_limit_per_user) {
            $userUsedCount = $promotion->redemptions()
                ->where('user_id', auth()->id())
                ->successful()
                ->count();

            if ($userUsedCount >= $promotion->usage_limit_per_user) {
                $redemption = PromotionRedemption::create([
                    'promotion_id' => $promotion->id,
                    'user_id' => auth()->id(),
                    'order_id' => $validated['order_id'] ?? null,
                    'code_used' => $validated['code'],
                    'discount_amount' => 0,
                    'order_total' => $validated['order_total'],
                    'status' => 'failed',
                    'failure_reason' => 'Per-user limit reached',
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'You have reached the usage limit for this promotion',
                    'redemption_id' => $redemption->id,
                ], 422);
            }
        }

        // Calculate discount
        $discount = 0;
        if ($promotion->type === 'percentage') {
            $discount = ($validated['order_total'] * $promotion->value) / 100;
            if ($promotion->max_discount && $discount > $promotion->max_discount) {
                $discount = $promotion->max_discount;
            }
        } else {
            $discount = min($promotion->value, $validated['order_total']);
        }

        $redemption = PromotionRedemption::create([
            'promotion_id' => $promotion->id,
            'user_id' => auth()->id(),
            'order_id' => $validated['order_id'] ?? null,
            'code_used' => $validated['code'],
            'discount_amount' => $discount,
            'order_total' => $validated['order_total'],
            'status' => 'success',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Promotion applied successfully',
            'discount' => $discount,
            'promotion' => $promotion,
            'redemption_id' => $redemption->id,
        ]);
    }

    // Loyalty rules endpoints
    public function getLoyaltyRules()
    {
        $rulesFile = storage_path('app/loyalty_rules.json');
        $defaults = ['points_per_dollar' => 10, 'redeem_ratio' => 100, 'min_redeem_points' => 100, 'max_redeem_percent' => 50];

        if (file_exists($rulesFile)) {
            $saved = json_decode(file_get_contents($rulesFile), true);
            return response()->json(array_merge($defaults, $saved));
        }

        return response()->json($defaults);
    }

    public function updateLoyaltyRules(Request $request)
    {
        $validated = $request->validate([
            'points_per_dollar' => 'required|numeric|min:1',
            'redeem_ratio' => 'required|numeric|min:1',
            'min_redeem_points' => 'nullable|numeric|min:1',
            'max_redeem_percent' => 'nullable|numeric|min:1|max:100',
        ]);

        $rulesFile = storage_path('app/loyalty_rules.json');
        file_put_contents($rulesFile, json_encode($validated, JSON_PRETTY_PRINT));

        return response()->json($validated);
    }

    public function loyaltyTransactions(Request $request)
    {
        $query = LoyaltyTransaction::with(['user:id,name,email', 'order:id,order_number,total', 'promotion:id,name,code']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            })->orWhere('description', 'like', "%{$search}%");
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        $query->orderBy('created_at', 'desc');

        $transactions = $query->paginate($request->get('limit', 20));

        $all = LoyaltyTransaction::all();
        $stats = [
            'total_transactions' => $all->count(),
            'total_earned' => $all->where('type', 'earned')->sum('points'),
            'total_burnt' => $all->where('type', 'burnt')->sum('points'),
            'unique_members' => $all->pluck('user_id')->unique()->count(),
        ];

        return response()->json([
            'data' => $transactions->items(),
            'total' => $transactions->total(),
            'page' => $transactions->currentPage(),
            'limit' => $transactions->perPage(),
            'stats' => $stats,
        ]);
    }

    public function generateCodes(Request $request)
    {
        // Generate bulk coupon codes
        return response()->json(['codes' => ['ABC123', 'DEF456']]);
    }

    public function redemptionAnalytics(Request $request)
    {
        $query = PromotionRedemption::with(['promotion:id,name,code,type,value', 'user:id,name,email']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('code_used', 'like', "%{$search}%")
                  ->orWhere('failure_reason', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                  })
                  ->orWhereHas('promotion', function ($pq) use ($search) {
                      $pq->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('promotion_id')) {
            $query->where('promotion_id', $request->promotion_id);
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        $query->orderBy('created_at', 'desc');

        $redemptions = $query->paginate($request->get('limit', 20));

        $all = PromotionRedemption::all();
        $successful = $all->where('status', 'success');
        $stats = [
            'total' => $all->count(),
            'successful' => $successful->count(),
            'failed' => $all->where('status', 'failed')->count(),
            'total_discount' => $successful->sum('discount_amount'),
            'unique_users' => $successful->pluck('user_id')->unique()->count(),
        ];

        return response()->json([
            'data' => $redemptions->items(),
            'total' => $redemptions->total(),
            'page' => $redemptions->currentPage(),
            'limit' => $redemptions->perPage(),
            'stats' => $stats,
        ]);
    }
}