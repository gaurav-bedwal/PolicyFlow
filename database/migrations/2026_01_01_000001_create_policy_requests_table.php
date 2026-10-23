<?php

use App\Enums\PolicyStatus;
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
        Schema::create('policy_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('client_request_id');
            $table->string('agent_id');
            $table->string('customer_name');
            $table->date('date_of_birth');
            $table->string('destination');
            $table->date('trip_start');
            $table->date('trip_end');
            $table->string('plan_code');
            $table->decimal('sum_insured', 12, 2);
            $table->integer('travel_days');
            $table->integer('customer_age_at_trip_start');

            // Immutable pricing snapshot inputs & output
            $table->decimal('age_multiplier', 5, 2);
            $table->decimal('destination_multiplier', 5, 2);
            $table->decimal('coverage_multiplier', 5, 2);
            $table->decimal('base_daily_rate', 10, 2);
            $table->decimal('premium_amount', 10, 2);
            $table->string('currency', 3)->default('INR');

            // Lifecycle & Provider details
            $table->string('status')->default(PolicyStatus::RECEIVED->value)->index();
            $table->string('provider_reference')->nullable()->index();
            $table->string('policy_number')->nullable()->unique();
            $table->json('last_provider_response')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            // Unique constraint for client idempotency: agent_id + client_request_id
            $table->unique(['agent_id', 'client_request_id'], 'uq_policy_agent_client_req');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('policy_requests');
    }
};
