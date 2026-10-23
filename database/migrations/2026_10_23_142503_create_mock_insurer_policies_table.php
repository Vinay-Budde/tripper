<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mock_insurer_policies', function (Blueprint $table) {
            $table->id();
            $table->string('partner_request_id')->unique(); // e.g. client_request_id or internal policy_request_id
            $table->string('provider_reference')->unique();
            $table->string('policy_number')->unique();
            $table->string('status'); // ISSUED, PENDING, REJECTED
            $table->decimal('premium', 10, 2);
            $table->json('customer_data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mock_insurer_policies');
    }
};
