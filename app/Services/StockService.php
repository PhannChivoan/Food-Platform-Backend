<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Optional per-dish stock (menu_items.stock_quantity, null = not tracked).
 * Reserving counts the dish down and sells it out at 0; cancelling gives it back.
 */
class StockService
{
    /** @param iterable<array{menu_item_id:int,quantity:int,name?:string}> $lines  call inside a transaction */
    public static function reserve(iterable $lines): void
    {
        foreach ($lines as $line) {
            $dish = DB::table('menu_items')->where('id', $line['menu_item_id'])->lockForUpdate()->first(['id', 'name', 'stock_quantity']);
            if (!$dish || $dish->stock_quantity === null) {
                continue;
            }

            abort_if(
                $dish->stock_quantity < $line['quantity'],
                422,
                $dish->stock_quantity > 0
                    ? "Only {$dish->stock_quantity} left of {$dish->name}. Please lower the quantity."
                    : "{$dish->name} just sold out."
            );

            $left = $dish->stock_quantity - $line['quantity'];
            DB::table('menu_items')->where('id', $dish->id)->update(
                ['stock_quantity' => $left] + ($left === 0 ? ['is_available' => false] : [])
            );
        }
    }

    /** Gives the dishes of a cancelled / rejected order back, once. */
    public static function restore(int $orderId): void
    {
        $order = DB::table('orders')->where('id', $orderId)->first(['id', 'meta']);
        $meta = json_decode($order->meta ?? '[]', true) ?: [];
        if (!$order || !empty($meta['stock_restored'])) {
            return;
        }

        foreach (DB::table('order_items')->where('order_id', $orderId)->get(['menu_item_id', 'quantity']) as $line) {
            $dish = DB::table('menu_items')->where('id', $line->menu_item_id)->first(['id', 'stock_quantity']);
            if ($dish && $dish->stock_quantity !== null) {
                DB::table('menu_items')->where('id', $dish->id)->update([
                    'stock_quantity' => $dish->stock_quantity + $line->quantity,
                    'is_available' => true,
                ]);
            }
        }

        $meta['stock_restored'] = true;
        DB::table('orders')->where('id', $orderId)->update(['meta' => json_encode($meta)]);
    }
}
