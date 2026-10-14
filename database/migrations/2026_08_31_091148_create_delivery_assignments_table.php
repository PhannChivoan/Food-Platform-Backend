<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('delivery_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->foreignId('rider_profile_id')->constrained()->onDelete('cascade');
            $table->enum('status', ['assigned', 'picked_up', 'delivered', 'failed'])->default('assigned');
            $table->timestamp('assigned_at');
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->decimal('earnings', 10, 2)->default(0);
            $table->json('route_points')->nullable(); // polyline of route
            $table->timestamps();

            $table->index(['order_id', 'rider_profile_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('delivery_assignments');
    }
};