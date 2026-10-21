<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Scheduled delivery was removed; pickup (collect at the restaurant) replaces it as an option. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'scheduled_for')) {
            Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('scheduled_for'));
        }
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->timestamp('scheduled_for')->nullable());
    }
};
