<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('customer_addresses', function (Blueprint $table) {
            // "removed" by the customer but still referenced by past orders
            $table->boolean('is_hidden')->default(false)->after('is_default');
        });
    }

    public function down()
    {
        Schema::table('customer_addresses', function (Blueprint $table) {
            $table->dropColumn('is_hidden');
        });
    }
};