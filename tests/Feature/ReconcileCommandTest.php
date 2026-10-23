<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use App\Services\MockInsurerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcileCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciliation_command_detects_and_resolves_stale_policies(): void
    {
        // 1. Create a policy stuck in NEEDS_RECONCILIATION
        $intake = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'AGT-CMD-01',
            'agent_id' => 'AGENT-CMD',
            'customer_name' => 'Recon Test',
            'date_of_birth' => '1990-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-10-01',
            'trip_end' => '2026-10-02',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ])->assertStatus(201);

        $policyId = $intake->json('data.id');

        // Submit with TIMEOUT_AFTER_ISSUE
        $this->postJson("/api/policy-requests/{$policyId}/submit", [
            'scenario' => 'TIMEOUT_AFTER_ISSUE',
        ]);

        $policy = PolicyRequest::find($policyId);
        $this->assertEquals('NEEDS_RECONCILIATION', $policy->status->value);

        // Run artisan command
        $this->artisan('policyflow:reconcile')
            ->expectsOutputToContain('Found 1 policies awaiting reconciliation.')
            ->expectsOutputToContain('Successfully reconciled')
            ->assertExitCode(0);

        $policy->refresh();
        $this->assertEquals('ISSUED', $policy->status->value);
        $this->assertNotNull($policy->policy_number);
    }
}
