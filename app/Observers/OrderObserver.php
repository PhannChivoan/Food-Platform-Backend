<?php

namespace App\Observers;

use App\Models\Order;
use App\Notifications\OrderStatusChanged;
use App\Services\StockService;

class OrderObserver
{
    public function updated(Order $order): void
    {
        if (!$order->wasChanged('status')) {
            return;
        }

        $status = $order->status;

        // a cancelled / rejected order gives its dishes back to the stock
        if (in_array($status, ['cancelled', 'rejected'], true)) {
            StockService::restore($order->id);
        }

        if (OrderStatusChanged::handles($status) && $order->customer) {
            try {
                $order->customer->notify(new OrderStatusChanged(
                    $order->order_number,
                    $status,
                    $order->restaurant?->name,
                ));
            } catch (\Throwable $e) {
                report($e); // a mail problem must never block the kitchen
            }
        }
    }
}
