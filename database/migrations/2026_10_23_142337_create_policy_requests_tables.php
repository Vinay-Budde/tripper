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
        Schema::create('policy_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('client_request_id');
            $table->string('agent_id');
            $table->string('customer_name');
            $table->date('date_of_birth');
            $table->string('destination'); // DOMESTIC, INTERNATIONAL
            $table->date('trip_start');
            $table->date('trip_end');
            $table->string('plan_code'); // BASIC, PLUS, PREMIUM
            $table->unsignedInteger('sum_insured'); // 100000, 300000, 500000
            
            // Pricing snapshot
            $table->unsignedInteger('travel_days');
            $table->decimal('base_daily_rate', 10, 2);
            $table->decimal('age_multiplier', 5, 2);
            $table->decimal('destination_multiplier', 5, 2);
            $table->decimal('coverage_multiplier', 5, 2);
            $table->decimal('calculated_premium', 10, 2);
            
            // Workflow state
            $table->string('status')->default('RECEIVED'); // RECEIVED, VALIDATED, SUBMITTED, PENDING_CONFIRMATION, ISSUED, FAILED, NEEDS_RECONCILIATION
            $table->string('policy_number')->nullable()->unique();
            $table->string('provider_reference')->nullable()->index();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->json('last_response')->nullable();
            $table->text('last_error_message')->nullable();
            
            $table->timestamps();

            // Idempotency: Repeated submissions with the same agent_id + client_request_id must not create another logical request.
            $table->unique(['agent_id', 'client_request_id'], 'uq_agent_client_request');
            $table->index('status');
        });

        Schema::create('issuance_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')->constrained('policy_requests')->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('idempotency_key')->unique();
            $table->string('scenario_requested')->nullable();
            $table->string('status'); // PENDING, SUCCESS, FAILED, TIMED_OUT
            $table->string('provider_reference')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('attempted_at')->useCurrent();
            $table->timestamps();

            $table->index(['policy_request_id', 'attempt_number']);
        });

        Schema::create('provider_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique(); // Provider event ID for idempotent processing
            $table->string('event_type'); // POLICY_ISSUED, POLICY_REJECTED, etc.
            $table->string('provider_reference')->index();
            $table->string('policy_number')->nullable();
            $table->foreignId('policy_request_id')->nullable()->constrained('policy_requests')->nullOnDelete();
            $table->json('payload');
            $table->string('status')->default('PROCESSED'); // PROCESSED, DUPLICATE_IGNORED, FAILED
            $table->timestamps();
        });

        Schema::create('policy_state_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')->constrained('policy_requests')->cascadeOnDelete();
            $table->string('from_state')->nullable();
            $table->string('to_state');
            $table->string('trigger_source'); // INTAKE, ISSUANCE_CALL, CALLBACK, RECONCILIATION
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('policy_request_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('policy_state_histories');
        Schema::dropIfExists('provider_events');
        Schema::dropIfExists('issuance_attempts');
        Schema::dropIfExists('policy_requests');
    }
};
