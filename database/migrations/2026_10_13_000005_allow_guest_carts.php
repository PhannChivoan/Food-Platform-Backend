<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Guests (not signed in) also keep their cart in the database, keyed by an anonymous token.
// At login the guest cart is merged into the customer's own cart.
return new class extends Migration {
    public function up()
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->string('guest_token', 64)->nullable()->after('user_id');
            $table->unique(['guest_token', 'restaurant_id']);
        });
    }

    public function down()
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropUnique(['guest_token', 'restaurant_id']);
            $table->dropColumn('guest_token');
        });
    }
};