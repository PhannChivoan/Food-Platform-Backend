<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('loggable'); // polymorphic
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('event'); // order_status_changed, rider_assigned, etc.
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->json('metadata')->nullable(); // store extra context
            $table->timestamp('created_at')->useCurrent(); // millisecond precision handled by DB

    
            $table->index(['user_id', 'created_at']);
            $table->index('event');
        });
    }

    public function down()
    {
        Schema::dropIfExists('activity_logs');
    }
};