<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->foreignId('menu_item_id')->constrained()->onDelete('cascade');
            $table->string('item_name'); // snapshot
            $table->decimal('unit_price', 10, 2);
            $table->integer('quantity');
            $table->json('selected_modifiers')->nullable(); // [{"group":"Toppings","modifier":"Extra Cheese","price":1.50}]
            $table->decimal('subtotal', 10, 2);
            $table->timestamps();

            $table->index('order_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('order_items');
    }
};