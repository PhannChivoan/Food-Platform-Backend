<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('label')->nullable(); // Home, Office
            $table->string('address');
            $table->string('city');
            $table->string('postal_code')->nullable();
            $table->geometry('location');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

           $table->spatialIndex('location');
        });
    }

    public function down()
    {
        Schema::dropIfExists('customer_addresses');
    }
};