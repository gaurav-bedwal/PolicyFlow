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
        Schema::create('issuance_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')->constrained('policy_requests')->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('idempotency_key')->index();
            $table->string('outcome'); // SUCCESS, PENDING, TIMEOUT, FAILED, NEEDS_RECONCILIATION
            $table->json('request_metadata')->nullable();
            $table->json('response_metadata')->nullable();
            $table->string('error_category')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Unique constraint on policy request and attempt number
            $table->unique(['policy_request_id', 'attempt_number'], 'uq_attempt_policy_num');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('issuance_attempts');
    }
};
