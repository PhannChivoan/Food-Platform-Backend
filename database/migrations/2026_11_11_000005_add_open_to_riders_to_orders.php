<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The kitchen can open an order to riders: drivers then see it in "Available orders" and can accept it themselves.
return new class extends Migration {
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('open_to_riders')->default(false)->after('status');
            $table->timestamp('open_to_riders_at')->nullable()->after('open_to_riders');
            $table->index(['open_to_riders', 'status'], 'orders_open_to_riders_status_index');
        });
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_open_to_riders_status_index');
            $table->dropColumn(['open_to_riders', 'open_to_riders_at']);
        });
    }
};
