<?php

namespace App\Services;

use App\Models\MockInsurerPolicy;
use Illuminate\Support\Str;

class MockInsurerService
{
    /**
     * Simulate provider issue endpoint.
     *
     * @param array $payload
     * @return array [ 'http_status' => int, 'data' => array ]
     */
    public function issuePolicy(array $payload): array
    {
        $partnerRequestId = $payload['client_request_id'] ?? 'REQ-' . Str::random(8);
        $scenario = strtoupper($payload['scenario'] ?? 'SUCCESS');

        // Check if provider already has an issuance for this partner_request_id
        $existing = MockInsurerPolicy::where('partner_request_id', $partnerRequestId)->first();
        if ($existing) {
            return [
                'http_status' => 200,
                'data' => [
                    'status' => $existing->status,
                    'provider_reference' => $existing->provider_reference,
                    'policy_number' => $existing->policy_number,
                    'message' => 'Policy already exists for this request',
                ],
            ];
        }

        $providerRef = 'PRV-' . strtoupper(Str::random(10));
        $policyNumber = 'POL-' . date('Y') . '-' . strtoupper(Str::random(8));

        return match ($scenario) {
            'TEMPORARY_FAILURE' => [
                'http_status' => 503,
                'data' => [
                    'status' => 'ERROR',
                    'error_code' => 'GATEWAY_TEMPORARY_TIMEOUT',
                    'message' => 'Provider upstream connection temporarily unavailable. Please retry.',
                ],
            ],

            'TIMEOUT_AFTER_ISSUE' => (function () use ($partnerRequestId, $providerRef, $policyNumber, $payload) {
                // Record the policy in provider's internal database!
                MockInsurerPolicy::create([
                    'partner_request_id' => $partnerRequestId,
                    'provider_reference' => $providerRef,
                    'policy_number' => $policyNumber,
                    'status' => 'ISSUED',
                    'premium' => $payload['premium'] ?? 0,
                    'customer_data' => $payload,
                ]);

                // But the client request times out (HTTP 504)
                return [
                    'http_status' => 504,
                    'data' => [
                        'status' => 'TIMEOUT',
                        'error_code' => 'UPSTREAM_GATEWAY_TIMEOUT',
                        'message' => 'Network timeout occurred waiting for provider acknowledgment',
                    ],
                ];
            })(),

            'DELAYED_CALLBACK' => (function () use ($partnerRequestId, $providerRef, $policyNumber, $payload) {
                // Provider creates a pending record
                MockInsurerPolicy::create([
                    'partner_request_id' => $partnerRequestId,
                    'provider_reference' => $providerRef,
                    'policy_number' => $policyNumber,
                    'status' => 'PENDING',
                    'premium' => $payload['premium'] ?? 0,
                    'customer_data' => $payload,
                ]);

                return [
                    'http_status' => 202,
                    'data' => [
                        'status' => 'PENDING',
                        'provider_reference' => $providerRef,
                        'message' => 'Issuance is queued; result will be delivered via asynchronous webhook callback.',
                    ],
                ];
            })(),

            default => (function () use ($partnerRequestId, $providerRef, $policyNumber, $payload) {
                // SUCCESS
                MockInsurerPolicy::create([
                    'partner_request_id' => $partnerRequestId,
                    'provider_reference' => $providerRef,
                    'policy_number' => $policyNumber,
                    'status' => 'ISSUED',
                    'premium' => $payload['premium'] ?? 0,
                    'customer_data' => $payload,
                ]);

                return [
                    'http_status' => 200,
                    'data' => [
                        'status' => 'ISSUED',
                        'provider_reference' => $providerRef,
                        'policy_number' => $policyNumber,
                        'issued_at' => now()->toIso8601String(),
                        'message' => 'Policy issued successfully',
                    ],
                ];
            })(),
        };
    }

    /**
     * Provider status lookup endpoint for reconciliation (resolving timeouts and pending states).
     */
    public function lookupStatus(string $partnerRequestId, ?string $providerReference = null): ?array
    {
        $query = MockInsurerPolicy::query();

        if ($providerReference) {
            $query->where('provider_reference', $providerReference);
        } else {
            $query->where('partner_request_id', $partnerRequestId);
        }

        $policy = $query->first();
        if (!$policy) {
            return null;
        }

        return [
            'found' => true,
            'status' => $policy->status,
            'provider_reference' => $policy->provider_reference,
            'policy_number' => $policy->policy_number,
            'premium' => (float) $policy->premium,
            'created_at' => $policy->created_at->toIso8601String(),
        ];
    }
}
