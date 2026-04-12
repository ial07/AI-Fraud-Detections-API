<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id', 50)->unique();
            $table->string('user_id', 50)->index();
            $table->string('receiver_id', 50)->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 10)->default('USD');
            $table->string('transaction_type', 30)->default('TRANSFER');
            $table->string('location', 100)->nullable();
            $table->string('device', 100)->nullable();
            $table->string('device_type', 20)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->decimal('risk_score', 5, 2)->nullable();
            $table->string('risk_level', 20)->nullable();
            $table->boolean('is_flagged')->default(false);
            $table->boolean('is_simulated')->default(false);
            $table->string('status', 20)->default('PENDING');
            $table->string('recommended_action', 30)->nullable();
            $table->text('ai_explanation')->nullable();
            $table->string('explanation_source', 20)->nullable();
            $table->timestamps();

            $table->index(['risk_level', 'is_flagged']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
