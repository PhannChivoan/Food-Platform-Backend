<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * A rider who was assigned but has not picked the order up after N minutes loses it:
 * it goes to the least busy rider who is online and available, or back to the dispatch queue.
 */
class ReassignStaleRiders extends Command
{
    protected $signature = 'riders:reassign-stale {--minutes=10}';
    protected $description = 'Hand orders to another rider when the assigned one has not picked them up in time';

    public function handle(): int
    {
        $stale = DB::table('delivery_assignments')
            ->where('status', 'assigned')
            ->whereNull('picked_up_at')
            ->where('assigned_at', '<', now()->subMinutes((int) $this->option('minutes')))
            ->get();

        $moved = $released = 0;

        foreach ($stale as $a) {
            $busy = DB::table('delivery_assignments')
                ->whereIn('status', ['assigned', 'picked_up'])
                ->select('rider_profile_id', DB::raw('count(*) as jobs'))
                ->groupBy('rider_profile_id');

            $next = DB::table('rider_profiles')
                ->leftJoinSub($busy, 'busy', 'busy.rider_profile_id', '=', 'rider_profiles.id')
                ->where('rider_profiles.is_online', true)
                ->where('rider_profiles.is_available', true)
                ->whereNull('rider_profiles.deleted_at')
                ->where('rider_profiles.id', '!=', $a->rider_profile_id)
                ->orderByRaw('coalesce(busy.jobs, 0)')
                ->first(['rider_profiles.id']);

            if ($next) {
                DB::table('delivery_assignments')->where('id', $a->id)
                    ->update(['rider_profile_id' => $next->id, 'assigned_at' => now(), 'updated_at' => now()]);
                $moved++;
            } else {
                // nobody free: back to the dispatch queue so a dispatcher can act
                DB::table('delivery_assignments')->where('id', $a->id)->delete();
                DB::table('orders')->where('id', $a->order_id)->where('status', 'dispatched')
                    ->update(['status' => 'ready', 'dispatched_at' => null, 'updated_at' => now()]);
                $released++;
            }

            DB::table('activity_logs')->insert([
                'loggable_type' => \App\Models\Order::class, 'loggable_id' => $a->order_id, 'user_id' => null,
                'event' => $next ? 'rider_reassigned' : 'rider_released',
                'metadata' => json_encode(['from_rider_profile' => $a->rider_profile_id, 'to_rider_profile' => $next->id ?? null]),
                'created_at' => now(),
            ]);
        }

        $this->info("Reassigned {$moved}, released {$released}.");

        return self::SUCCESS;
    }
}
