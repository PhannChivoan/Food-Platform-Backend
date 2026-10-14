<?php

namespace App\Events;

use App\Models\Order;
use App\Models\RiderProfile;

class RiderAssigned
{
    public $order;
    public $rider;

    public function __construct(Order $order, RiderProfile $rider)
    {
        $this->order = $order;
        $this->rider = $rider;
    }
}
