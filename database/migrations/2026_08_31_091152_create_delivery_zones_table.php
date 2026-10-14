<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->string('name');
           $table->geometry('boundary'); // closed polygon
            $table->decimal('base_fee', 10, 2)->default(0);
            $table->decimal('fee_per_km', 10, 2)->default(0);
            $table->decimal('free_delivery_threshold', 10, 2)->nullable();
            $table->json('surge_pricing_rules')->nullable(); // [{"day":"monday","start":"18:00","end":"22:00","multiplier":1.5}]
            $table->timestamps();

            $table->spatialIndex('boundary');
        });
    }

    public function down()
    {
        Schema::dropIfExists('delivery_zones');
    }
};