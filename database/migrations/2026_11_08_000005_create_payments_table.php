<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete(); // team billing; null = billed to the user
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->string('kind')->default('new'); // new | renewal
            $table->unsignedBigInteger('amount'); // cents
            $table->string('currency', 3);
            $table->string('method')->default('bank_transfer');
            $table->string('status')->default('pending'); // pending | approved | rejected
            $table->string('transaction_id')->nullable(); // reference given by the customer's bank
            $table->string('proof_path')->nullable(); // private file (storage/app/private)
            $table->text('note')->nullable(); // customer note
            $table->text('review_note')->nullable(); // admin note (e.g. rejection reason)
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
