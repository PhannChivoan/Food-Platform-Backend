<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV exports (open in Excel / Sheets). Rows respect the same restaurant scope as the screens,
 * so an owner only exports their own restaurant.
 */
class ExportController extends Controller
{
    public function orders(Request $request): StreamedResponse
    {
        $v = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'status' => 'nullable|string',
            'branch_id' => 'nullable|integer',
        ]);

        $query = Order::query()->with(['restaurant:id,name', 'customer:id,name'])
            ->when($v['date_from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($v['date_to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->when($v['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($v['branch_id'] ?? null, fn ($q, $b) => $q->where('branch_id', $b))
            ->orderByDesc('created_at');

        return $this->csv('orders-' . now()->format('Ymd-His') . '.csv', [
            'Order', 'Date', 'Restaurant', 'Customer', 'Status', 'Payment', 'Paid', 'Subtotal', 'Discount', 'Total', 'Refunded',
        ], function ($out) use ($query) {
            $query->chunk(500, function ($orders) use ($out) {
                foreach ($orders as $o) {
                    fputcsv($out, [
                        $o->order_number, $o->created_at?->format('Y-m-d H:i'), $o->restaurant?->name, $o->customer?->name,
                        $o->status, $o->payment_method, $o->is_paid ? 'yes' : 'no',
                        $o->subtotal, $o->discount, $o->total, $o->refund_amount,
                    ]);
                }
            });
        });
    }

    /** One row per day: orders, sales, commission kept by the platform and what restaurants are owed. */
    public function revenue(Request $request): StreamedResponse
    {
        $v = $request->validate(['date_from' => 'nullable|date', 'date_to' => 'nullable|date']);

        $rows = Order::query()
            ->where('status', 'delivered')
            ->whereNull('refunded_at')
            ->when($v['date_from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($v['date_to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->get(['created_at', 'subtotal', 'discount', 'total', 'meta'])
            ->groupBy(fn ($o) => $o->created_at->format('Y-m-d'))
            ->sortKeys();

        return $this->csv('revenue-' . now()->format('Ymd-His') . '.csv', [
            'Date', 'Orders', 'Food sales', 'Discounts', 'Customer total', 'Platform commission', 'Restaurant payout',
        ], function ($out) use ($rows) {
            foreach ($rows as $day => $orders) {
                fputcsv($out, [
                    $day, $orders->count(),
                    round($orders->sum('subtotal'), 2), round($orders->sum('discount'), 2), round($orders->sum('total'), 2),
                    round($orders->sum(fn ($o) => $o->meta['platform_fee'] ?? 0), 2),
                    round($orders->sum(fn ($o) => $o->meta['restaurant_payout'] ?? 0), 2),
                ]);
            }
        });
    }

    private function csv(string $name, array $header, callable $body): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $body) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8 (Khmer names) correctly
            fputcsv($out, $header);
            $body($out);
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
