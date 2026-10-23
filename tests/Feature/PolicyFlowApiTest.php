<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyFlowApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_policy_request_produces_expected_premium_and_status_201(): void
    {
        $payload = [
            'client_request_id' => 'AGT-2026-0001',
            'agent_id' => 'AGENT-17',
            'customer_name' => 'Sample Customer',
            'date_of_birth' => '1998-04-10',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ];

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'is_duplicate' => false,
                'data' => [
                    'client_request_id' => 'AGT-2026-0001',
                    'agent_id' => 'AGENT-17',
                    'status' => 'VALIDATED',
                    'travel_days' => 5,
                    'pricing_breakdown' => [
                        'calculated_premium' => 225.00,
                    ],
                ],
            ]);

        $this->assertDatabaseHas('policy_requests', [
            'client_request_id' => 'AGT-2026-0001',
            'agent_id' => 'AGENT-17',
            'status' => 'VALIDATED',
            'calculated_premium' => 225.00,
        ]);

        $this->assertDatabaseHas('policy_state_histories', [
            'from_state' => 'RECEIVED',
            'to_state' => 'VALIDATED',
            'trigger_source' => 'INTAKE',
        ]);
    }

    public function test_invalid_travel_dates_and_unsupported_plan_are_rejected_with_422(): void
    {
        // 1. Invalid travel dates: trip_end < trip_start
        $invalidDatesPayload = [
            'client_request_id' => 'AGT-DATE-ERR',
            'agent_id' => 'AGENT-1',
            'customer_name' => 'Test',
            'date_of_birth' => '1990-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-05-10',
            'trip_end' => '2026-05-05',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ];

        $res1 = $this->postJson('/api/policy-requests', $invalidDatesPayload);
        $res1->assertStatus(422)
            ->assertJsonValidationErrors(['trip_end']);

        // 2. Unsupported plan code
        $invalidPlanPayload = [
            'client_request_id' => 'AGT-PLAN-ERR',
            'agent_id' => 'AGENT-1',
            'customer_name' => 'Test',
            'date_of_birth' => '1990-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-05-01',
            'trip_end' => '2026-05-05',
            'plan_code' => 'SUPER_PLATINUM_GOLD',
            'sum_insured' => 100000,
        ];

        $res2 = $this->postJson('/api/policy-requests', $invalidPlanPayload);
        $res2->assertStatus(422)
            ->assertJsonValidationErrors(['plan_code']);

        // 3. Unsupported sum insured
        $invalidSumPayload = [
            'client_request_id' => 'AGT-SUM-ERR',
            'agent_id' => 'AGENT-1',
            'customer_name' => 'Test',
            'date_of_birth' => '1990-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-05-01',
            'trip_end' => '2026-05-05',
            'plan_code' => 'BASIC',
            'sum_insured' => 999999,
        ];

        $res3 = $this->postJson('/api/policy-requests', $invalidSumPayload);
        $res3->assertStatus(422)
            ->assertJsonValidationErrors(['sum_insured']);
    }

    public function test_repeated_agent_id_and_client_request_id_returns_existing_with_200(): void
    {
        // Reliability Case 1: Duplicate intake
        $payload = [
            'client_request_id' => 'AGT-DUP-001',
            'agent_id' => 'AGENT-99',
            'customer_name' => 'First Submission',
            'date_of_birth' => '1992-06-15',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-07-01',
            'trip_end' => '2026-07-03',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ];

        // First submission -> 201 Created
        $res1 = $this->postJson('/api/policy-requests', $payload);
        $res1->assertStatus(201);
        $initialId = $res1->json('data.id');

        // Repeated submission with same agent_id + client_request_id -> 200 OK
        $res2 = $this->postJson('/api/policy-requests', $payload);
        $res2->assertStatus(200)
            ->assertJson([
                'success' => true,
                'is_duplicate' => true,
                'data' => [
                    'id' => $initialId,
                    'client_request_id' => 'AGT-DUP-001',
                ],
            ]);

        // Ensure exactly 1 database row exists
        $this->assertEquals(1, PolicyRequest::where('agent_id', 'AGENT-99')->where('client_request_id', 'AGT-DUP-001')->count());
    }

    public function test_successful_provider_response_produces_exactly_one_policy_number_and_issued_status(): void
    {
        $payload = [
            'client_request_id' => 'AGT-SUCCESS-01',
            'agent_id' => 'AGENT-10',
            'customer_name' => 'Alice Wonder',
            'date_of_birth' => '1990-05-10',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-08-01',
            'trip_end' => '2026-08-05',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ];

        $intake = $this->postJson('/api/policy-requests', $payload)->assertStatus(201);
        $policyId = $intake->json('data.id');

        // Submit issuance with scenario = SUCCESS
        $submit = $this->postJson("/api/policy-requests/{$policyId}/submit", ['scenario' => 'SUCCESS']);
        $submit->assertStatus(200)
            ->assertJson([
                'success' => true,
                'outcome' => 'ISSUED',
                'data' => [
                    'status' => 'ISSUED',
                ],
            ]);

        $policy = PolicyRequest::find($policyId);
        $this->assertEquals('ISSUED', $policy->status->value);
        $this->assertNotNull($policy->policy_number);
        $this->assertNotNull($policy->provider_reference);
        $this->assertEquals(1, $policy->attempt_count);

        // Repeated submit should be idempotent and not re-issue or create duplicate policy number
        $firstPolicyNumber = $policy->policy_number;
        $repeatSubmit = $this->postJson("/api/policy-requests/{$policyId}/submit", ['scenario' => 'SUCCESS']);
        $repeatSubmit->assertStatus(200)
            ->assertJson([
                'outcome' => 'ALREADY_ISSUED',
            ]);

        $policy->refresh();
        $this->assertEquals($firstPolicyNumber, $policy->policy_number);
        $this->assertEquals(1, $policy->attempt_count); // Provider call not repeated
    }

    public function test_reliability_case_timeout_after_success_reconciles_safely_without_blind_reissue(): void
    {
        // Reliability Case 2: Timeout after success
        // Client request times out, but provider created policy. Engine should reconcile before reissuing.
        $payload = [
            'client_request_id' => 'AGT-TIMEOUT-01',
            'agent_id' => 'AGENT-10',
            'customer_name' => 'Bob Marley',
            'date_of_birth' => '1985-02-14',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-09-01',
            'trip_end' => '2026-09-04',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ];

        $intake = $this->postJson('/api/policy-requests', $payload)->assertStatus(201);
        $policyId = $intake->json('data.id');

        // 1. Submit with TIMEOUT_AFTER_ISSUE
        $submitRes = $this->postJson("/api/policy-requests/{$policyId}/submit", ['scenario' => 'TIMEOUT_AFTER_ISSUE']);
        $submitRes->assertStatus(200)
            ->assertJson([
                'outcome' => 'NEEDS_RECONCILIATION',
                'data' => [
                    'status' => 'NEEDS_RECONCILIATION',
                ],
            ]);

        $policy = PolicyRequest::find($policyId);
        $this->assertEquals('NEEDS_RECONCILIATION', $policy->status->value);
        $this->assertEquals(1, $policy->attempt_count);

        // 2. Next submission/retry detects NEEDS_RECONCILIATION and queries provider status lookup first
        $retryRes = $this->postJson("/api/policy-requests/{$policyId}/submit");
        $retryRes->assertStatus(200)
            ->assertJson([
                'outcome' => 'RECONCILED_AND_ISSUED',
                'data' => [
                    'status' => 'ISSUED',
                ],
            ]);

        $policy->refresh();
        $this->assertEquals('ISSUED', $policy->status->value);
        $this->assertNotNull($policy->policy_number);
        // Did not blindly make another issuance attempt
        $this->assertEquals(1, $policy->attempt_count);

        // Verify audit trail contains RECONCILIATION trigger
        $this->assertDatabaseHas('policy_state_histories', [
            'policy_request_id' => $policyId,
            'to_state' => 'ISSUED',
            'trigger_source' => 'RECONCILIATION',
        ]);
    }

    public function test_reliability_case_duplicate_callback_is_idempotent(): void
    {
        // Reliability Case 3: Duplicate callback
        // Provider issues delayed callback or duplicate webhooks for the same event
        $payload = [
            'client_request_id' => 'AGT-CALLBACK-01',
            'agent_id' => 'AGENT-12',
            'customer_name' => 'Charlie Chaplin',
            'date_of_birth' => '1990-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-10-01',
            'trip_end' => '2026-10-02',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ];

        $intake = $this->postJson('/api/policy-requests', $payload)->assertStatus(201);
        $policyId = $intake->json('data.id');

        // Submit with DELAYED_CALLBACK -> PENDING_CONFIRMATION
        $submitRes = $this->postJson("/api/policy-requests/{$policyId}/submit", ['scenario' => 'DELAYED_CALLBACK']);
        $submitRes->assertStatus(200)
            ->assertJson([
                'outcome' => 'PENDING_CONFIRMATION',
            ]);

        $policy = PolicyRequest::find($policyId);
        $this->assertEquals('PENDING_CONFIRMATION', $policy->status->value);
        $providerRef = $policy->provider_reference;
        $this->assertNotNull($providerRef);

        $callbackPayload = [
            'event_id' => 'EVT-WEBHOOK-999',
            'event_type' => 'POLICY_ISSUED',
            'provider_reference' => $providerRef,
            'policy_number' => 'POL-PROVIDER-99999',
        ];

        // 1st Callback delivery -> 200 OK (is_duplicate: false)
        $cb1 = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $cb1->assertStatus(200)
            ->assertJson([
                'success' => true,
                'is_duplicate' => false,
            ]);

        $policy->refresh();
        $this->assertEquals('ISSUED', $policy->status->value);
        $this->assertEquals('POL-PROVIDER-99999', $policy->policy_number);

        // 2nd Duplicate callback delivery -> 200 OK (is_duplicate: true)
        $cb2 = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $cb2->assertStatus(200)
            ->assertJson([
                'success' => true,
                'is_duplicate' => true,
            ]);

        // State remains intact; only 1 provider_events row with event_id EVT-WEBHOOK-999
        $this->assertEquals('POL-PROVIDER-99999', $policy->fresh()->policy_number);
    }

    public function test_reliability_case_retry_after_temporary_failure_preserves_premium_and_succeeds(): void
    {
        // Reliability Case 4: Retry after temporary failure
        $payload = [
            'client_request_id' => 'AGT-RETRY-01',
            'agent_id' => 'AGENT-15',
            'customer_name' => 'Diana Prince',
            'date_of_birth' => '1992-03-10',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-01',
            'trip_end' => '2026-11-05', // 5 days
            'plan_code' => 'PREMIUM', // rate 30
            'sum_insured' => 500000, // 1.50
        ];
        // 30 * 5 * 1.00 * 1.80 * 1.50 = 405.00

        $intake = $this->postJson('/api/policy-requests', $payload)->assertStatus(201);
        $policyId = $intake->json('data.id');
        $initialPremium = $intake->json('data.pricing_breakdown.calculated_premium');
        $this->assertEquals(405.00, $initialPremium);

        // 1st attempt: TEMPORARY_FAILURE
        $res1 = $this->postJson("/api/policy-requests/{$policyId}/submit", ['scenario' => 'TEMPORARY_FAILURE']);
        $res1->assertStatus(200)
            ->assertJson([
                'outcome' => 'FAILED',
                'data' => [
                    'status' => 'FAILED',
                    'attempt_count' => 1,
                ],
            ]);

        $policy = PolicyRequest::find($policyId);
        $this->assertEquals('FAILED', $policy->status->value);
        $this->assertEquals(405.00, (float) $policy->calculated_premium);

        // 2nd attempt: Retry with SUCCESS
        $res2 = $this->postJson("/api/policy-requests/{$policyId}/submit", ['scenario' => 'SUCCESS']);
        $res2->assertStatus(200)
            ->assertJson([
                'outcome' => 'ISSUED',
                'data' => [
                    'status' => 'ISSUED',
                    'attempt_count' => 2,
                ],
            ]);

        $policy->refresh();
        $this->assertEquals('ISSUED', $policy->status->value);
        // Original premium must never be lost or modified
        $this->assertEquals(405.00, (float) $policy->calculated_premium);
        $this->assertNotNull($policy->policy_number);
        $this->assertEquals(2, $policy->attempt_count);
    }

    public function test_read_endpoint_returns_status_and_audit_trail(): void
    {
        $payload = [
            'client_request_id' => 'AGT-AUDIT-01',
            'agent_id' => 'AGENT-88',
            'customer_name' => 'Edward Elric',
            'date_of_birth' => '1995-10-03',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-12-01',
            'trip_end' => '2026-12-03',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ];

        $intake = $this->postJson('/api/policy-requests', $payload)->assertStatus(201);
        $policyId = $intake->json('data.id');

        $this->postJson("/api/policy-requests/{$policyId}/submit", ['scenario' => 'SUCCESS'])->assertStatus(200);

        // Read endpoint
        $readRes = $this->getJson("/api/policy-requests/{$policyId}");
        $readRes->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'uuid',
                    'client_request_id',
                    'agent_id',
                    'status',
                    'policy_number',
                    'provider_reference',
                    'calculated_premium',
                    'pricing_snapshot',
                    'audit_trail',
                    'attempts',
                ],
            ]);

        $auditTrail = $readRes->json('data.audit_trail');
        $this->assertGreaterThanOrEqual(2, count($auditTrail));
    }

    public function test_list_requests_supports_filtering_and_pagination(): void
    {
        // Seed 3 requests
        foreach (range(1, 3) as $i) {
            $this->postJson('/api/policy-requests', [
                'client_request_id' => "AGT-LIST-{$i}",
                'agent_id' => 'AGENT-PAGE',
                'customer_name' => "User {$i}",
                'date_of_birth' => '1990-01-01',
                'destination' => 'DOMESTIC',
                'trip_start' => '2026-06-01',
                'trip_end' => '2026-06-02',
                'plan_code' => 'BASIC',
                'sum_insured' => 100000,
            ])->assertStatus(201);
        }

        $listRes = $this->getJson('/api/policy-requests?agent_id=AGENT-PAGE&per_page=2');
        $listRes->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
                'pagination' => [
                    'current_page',
                    'per_page',
                    'total',
                ],
            ]);

        $this->assertCount(2, $listRes->json('data'));
        $this->assertEquals(3, $listRes->json('pagination.total'));
    }
}
