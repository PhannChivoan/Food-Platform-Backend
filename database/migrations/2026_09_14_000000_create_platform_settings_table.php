<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string')->comment('string, boolean, integer, json');
            $table->string('group')->default('general')->comment('general, ordering, notifications, payments, maintenance');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('platform_settings');
    }
};
