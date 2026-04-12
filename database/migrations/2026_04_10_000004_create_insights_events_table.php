<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insights_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 50)->index();
            $table->json('event_data')->nullable();
            $table->decimal('risk_score', 5, 2)->nullable();
            $table->string('risk_level', 20)->nullable();
            $table->string('source', 30)->default('API');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insights_events');
    }
};
