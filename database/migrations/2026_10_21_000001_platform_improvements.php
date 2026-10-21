<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduled delivery, refunds, stock counts, favourites and account protection
 * (lockout + two-factor sign-in).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('scheduled_for')->nullable()->after('customer_notes');
            $table->timestamp('refunded_at')->nullable();
            $table->decimal('refund_amount', 10, 2)->nullable();
            $table->string('refund_reason', 255)->nullable();
        });

        Schema::table('menu_items', function (Blueprint $table) {
            // null = not tracked; a number counts down with every order and sells the dish out at 0
            $table->unsignedInteger('stock_quantity')->nullable()->after('is_available');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->boolean('two_factor_enabled')->default(false);
            $table->unsignedSmallInteger('failed_logins')->default(0);
            $table->timestamp('locked_until')->nullable();
        });

        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'restaurant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['two_factor_secret', 'two_factor_enabled', 'failed_logins', 'locked_until']));
        Schema::table('menu_items', fn (Blueprint $t) => $t->dropColumn('stock_quantity'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['scheduled_for', 'refunded_at', 'refund_amount', 'refund_reason']));
    }
};
