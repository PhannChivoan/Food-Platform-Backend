<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('address');
            $table->string('city');
            $table->string('postal_code')->nullable();
           $table->geometry('location'); // SRID 4326
            $table->string('phone')->nullable();
            $table->json('operating_hours')->nullable(); // {"monday": "09:00-22:00", ...}
            $table->json('holiday_dates')->nullable();   // ["2024-12-25"]
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->spatialIndex('location');
            $table->index('restaurant_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('branches');
    }
};