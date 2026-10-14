<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('modifier_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->enum('selection_type', ['single', 'multiple'])->default('single');
            $table->integer('min_selections')->default(0);
            $table->integer('max_selections')->default(10);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('modifier_groups');
    }
};