<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_access_rules', function (Blueprint $table) {
            $table->id();
            $table->string('prefix')->unique(); // permission prefix, e.g. posts
            $table->string('mode')->default('permission'); // permission | plan | either
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_access_rules');
    }
};
