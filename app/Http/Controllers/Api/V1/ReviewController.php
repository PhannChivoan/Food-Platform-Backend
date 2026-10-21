<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Ratings and reviews written by customers after a delivered order. */
class ReviewController extends Controller
{
    /** Public: the latest reviews of a restaurant with its average. */
    public function index(int $restaurantId)
    {
        $base = DB::table('comments')
            ->join('orders', function ($j) {
                $j->on('orders.id', '=', 'comments.commentable_id')->where('comments.commentable_type', Order::class);
            })
            ->where('orders.restaurant_id', $restaurantId)
            ->where('comments.is_approved', true)
            ->whereNotNull('comments.rating');

        $summary = (clone $base)->selectRaw('count(*) as total, avg(comments.rating) as average')->first();

        $rows = (clone $base)
            ->join('users', 'users.id', '=', 'comments.user_id')
            ->orderByDesc('comments.created_at')->limit(20)
            ->get(['comments.id', 'comments.rating', 'comments.content', 'comments.created_at', 'users.name']);

        return response()->json([
            'average' => $summary->total ? round((float) $summary->average, 1) : null,
            'total' => (int) $summary->total,
            'data' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'rating' => (int) $r->rating,
                'content' => $r->content,
                'name' => $this->shortName($r->name),
                'at' => \Carbon\Carbon::parse($r->created_at)->toIso8601String(),
            ]),
        ]);
    }

    /** Customer: rate one of their delivered orders (once). */
    public function store(Request $request, int $id)
    {
        $v = $request->validate([
            'rating' => 'required|integer|between:1,5',
            'content' => 'nullable|string|max:1000',
        ]);

        $order = DB::table('orders')->where('id', $id)->where('customer_id', $request->user()->id)->first(['id', 'status']);
        abort_unless($order, 404, 'Order not found');
        abort_unless($order->status === 'delivered', 422, 'You can review an order after it is delivered.');
        abort_if(
            Comment::where('commentable_type', Order::class)->where('commentable_id', $id)->where('user_id', $request->user()->id)->exists(),
            422,
            'You already reviewed this order.'
        );

        $comment = Comment::create([
            'commentable_type' => Order::class,
            'commentable_id' => $id,
            'user_id' => $request->user()->id,
            'rating' => $v['rating'],
            'content' => $v['content'] ?? null,
            'is_approved' => true,
        ]);

        return response()->json(['message' => 'Thanks for your review!', 'id' => $comment->id], 201);
    }

    private function shortName(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];

        return ($parts[0] ?? 'Customer') . (isset($parts[1]) ? ' ' . mb_substr($parts[1], 0, 1) . '.' : '');
    }
}
