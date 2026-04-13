<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('ai_risk_score', 5, 2)->nullable()->after('risk_score');
            $table->decimal('ai_confidence', 5, 2)->nullable()->after('ai_risk_score');
            $table->string('fraud_type', 30)->nullable()->after('ai_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['ai_risk_score', 'ai_confidence', 'fraud_type']);
        });
    }
};
