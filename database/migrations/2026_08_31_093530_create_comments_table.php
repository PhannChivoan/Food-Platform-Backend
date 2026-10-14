<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
   public function up()
{
    Schema::create('comments', function (Blueprint $table) {
        $table->id();
        $table->morphs('commentable');          // ✅ Already creates the index
        $table->foreignId('user_id')->constrained()->onDelete('cascade');
        $table->text('content');
        $table->tinyInteger('rating')->nullable();
        $table->boolean('is_approved')->default(false);
        $table->timestamps();

        // Keep only the user_id index if you want
        $table->index('user_id');
    });
}

    public function down()
    {
        Schema::dropIfExists('comments');
    }
};