<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('zone_branch', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_zone_id')->constrained()->onDelete('cascade');
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['delivery_zone_id', 'branch_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('zone_branch');
    }
};
