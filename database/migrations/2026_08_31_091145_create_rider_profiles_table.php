<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('rider_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('vehicle_type'); // bike, car, scooter
            $table->string('license_plate')->nullable();
            $table->geometry('current_location')->nullable();
            $table->boolean('is_online')->default(false);
            $table->boolean('is_available')->default(true);
            $table->decimal('rating', 3, 2)->default(0);
            $table->integer('total_trips')->default(0);
            $table->decimal('total_earnings', 10, 2)->default(0);
            $table->json('documents')->nullable(); // uploaded IDs
            $table->softDeletes();
            $table->timestamps();

            // $table->spatialIndex('current_location');   
        });
    }

    public function down()
    {
        Schema::dropIfExists('rider_profiles');
    }
};