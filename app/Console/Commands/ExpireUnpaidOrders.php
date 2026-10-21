<?php

namespace App\Console\Commands;

use App\Services\StockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Online-payment orders (ABA KHQR / card) that nobody paid are cancelled after a few minutes,
 * so a customer who simply closed the tab never leaves an order, a reserved dish or a used promo code behind.
 */
class ExpireUnpaidOrders extends Command
{
    protected $signature = 'orders:expire-unpaid {--minutes=15 : age after which an unpaid online order is cancelled}';
    protected $description = 'Cancel online-payment orders that were never paid';

    public function handle(): int
    {
        $cutoff = now()->subMinutes((int) $this->option('minutes'));

        $orders = DB::table('orders')
            ->whereIn('payment_method', ['aba_khqr', 'card'])
            ->where('is_paid', false)
            ->whereIn('status', ['pending', 'accepted'])
            ->where('created_at', '<', $cutoff)
            ->get(['id', 'meta']);

        foreach ($orders as $o) {
            DB::transaction(function () use ($o) {
                $meta = json_decode($o->meta ?? '[]', true) ?: [];
                $meta['payment_status'] = 'cancelled';
                $meta['cancel_reason'] = 'Payment not completed in time';

                DB::table('orders')->where('id', $o->id)->update([
                    'status' => 'cancelled', 'cancelled_at' => now(),
                    'meta' => json_encode($meta), 'updated_at' => now(),
                ]);
                // free the promo code the order had used
                DB::table('promotion_redemptions')->where('order_id', $o->id)->delete();
                StockService::restore($o->id);
            });
        }

        $this->info("Cancelled {$orders->count()} unpaid order(s).");

        return self::SUCCESS;
    }
}
