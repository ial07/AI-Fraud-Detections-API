<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fraud_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->decimal('risk_score', 5, 2);
            $table->string('risk_level', 20);
            $table->json('explanations');
            $table->json('risk_factors')->nullable();
            $table->string('alert_status', 20)->default('NEW');
            $table->string('resolution', 20)->nullable();
            $table->timestamps();

            $table->index('alert_status');
            $table->index('risk_level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fraud_alerts');
    }
};
