<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->string('status')->default('pending'); // pending | trialing | active | canceled | expired
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('period_starts_at')->nullable(); // start of the current billing period
            $table->timestamp('ends_at')->nullable(); // null = no end (lifetime / free)
            $table->timestamp('canceled_at')->nullable();
            $table->string('gateway')->nullable(); // future: stripe, paypal, razorpay…
            $table->string('gateway_id')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
