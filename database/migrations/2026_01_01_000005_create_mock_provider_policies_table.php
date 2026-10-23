<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mock_provider_policies', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key')->unique();
            $table->string('provider_reference')->unique();
            $table->string('policy_number')->unique();
            $table->string('status'); // ISSUED, PENDING, FAILED
            $table->json('payload')->nullable();
            $table->string('scenario')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mock_provider_policies');
    }
};
