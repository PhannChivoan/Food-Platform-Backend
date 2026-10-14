<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->onDelete('cascade');
            $table->foreignId('category_id')->constrained('menu_categories')->onDelete('cascade');
            $table->string('name');
            $table->string('slug')->nullable();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->decimal('discounted_price', 10, 2)->nullable();
            $table->string('image')->nullable();
            $table->boolean('is_available')->default(true)->index();
            $table->boolean('is_featured')->default(false);
            $table->json('options')->nullable(); // {"spice_level": ["mild", "hot"]}
            $table->integer('prep_time_minutes')->default(15);
            $table->timestamps();

            $table->index(['restaurant_id', 'is_available']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('menu_items');
    }
};