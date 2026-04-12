<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('user_id', 50)->unique();
            $table->decimal('avg_transaction_amt', 15, 2)->default(0);
            $table->decimal('max_transaction_amt', 15, 2)->default(0);
            $table->integer('total_transactions')->default(0);
            $table->string('usual_location', 100)->nullable();
            $table->json('known_devices')->nullable();
            $table->timestamp('last_active')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
