<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('customer_addresses', function (Blueprint $table) {
            $table->string('details')->nullable()->after('address'); // floor, house / room number, gate code...
        });
    }

    public function down()
    {
        Schema::table('customer_addresses', function (Blueprint $table) {
            $table->dropColumn('details');
        });
    }
};