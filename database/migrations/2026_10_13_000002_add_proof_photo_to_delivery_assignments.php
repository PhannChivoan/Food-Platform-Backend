<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('delivery_assignments', function (Blueprint $table) {
            $table->string('proof_photo')->nullable()->after('route_points'); // photo taken by the rider at hand-over
        });
    }

    public function down()
    {
        Schema::table('delivery_assignments', function (Blueprint $table) {
            $table->dropColumn('proof_photo');
        });
    }
};