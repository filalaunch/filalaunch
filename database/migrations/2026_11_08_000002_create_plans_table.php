<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('type'); // one_time | subscription | usage
            $table->unsignedBigInteger('price')->default(0); // smallest currency unit (cents)
            $table->string('currency', 3);
            $table->string('interval')->nullable(); // day | week | month | year
            $table->unsignedSmallInteger('interval_count')->default(1);
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->unsignedInteger('access_days')->nullable(); // one-time: null = lifetime
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('plan_permission', function (Blueprint $table) {
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->primary(['plan_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_permission');
        Schema::dropIfExists('plans');
    }
};
