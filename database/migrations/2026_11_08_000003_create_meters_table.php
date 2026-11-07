<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meters', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // e.g. api_calls
            $table->string('name');
            $table->string('unit')->default('unit');
            $table->string('reset')->default('monthly'); // monthly | cycle | never
            $table->timestamps();
        });

        Schema::create('plan_meters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->foreignId('meter_id')->constrained('meters')->cascadeOnDelete();
            $table->unsignedBigInteger('included')->nullable(); // null = unlimited
            $table->unsignedBigInteger('unit_price')->default(0); // cents per unit above "included"
            $table->string('on_limit')->default('block'); // block | overage
            $table->timestamps();
            $table->unique(['plan_id', 'meter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_meters');
        Schema::dropIfExists('meters');
    }
};
