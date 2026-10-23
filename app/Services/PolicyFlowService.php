<?php

namespace App\Services;

use App\Enums\PolicyStatus;
use App\Models\IssuanceAttempt;
use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use App\Models\ProviderEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class PolicyFlowService
{
    public function __construct(
        protected PremiumCalculationService $pricingService,
        protected MockInsurerService $mockInsurerService
    ) {}

    /**
     * Intake a policy request.
     * Prevents duplicate intake via DB unique constraint & safe retrieval.
     */
    public function intake(array $data): array
    {
        // 1. Calculate pricing snapshot
        $pricing = $this->pricingService->calculate(
            planCode: $data['plan_code'],
            sumInsured: (int) $data['sum_insured'],
            destination: $data['destination'],
            dob: $data['date_of_birth'],
            tripStart: $data['trip_start'],
            tripEnd: $data['trip_end']
        );

        // 2. Wrap in transaction with duplicate collision handling
        return DB::transaction(function () use ($data, $pricing) {
            // Check if already exists for agent_id + client_request_id
            $existing = PolicyRequest::where('agent_id', $data['agent_id'])
                ->where('client_request_id', $data['client_request_id'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                // Check for payload conflict on repeated intake with same agent_id + client_request_id
                $conflicts = [];
                if ($existing->customer_name !== $data['customer_name']) {
                    $conflicts[] = 'customer_name';
                }
                if ($existing->date_of_birth->format('Y-m-d') !== $data['date_of_birth']) {
                    $conflicts[] = 'date_of_birth';
                }
                if ($existing->destination !== strtoupper($data['destination'])) {
                    $conflicts[] = 'destination';
                }
                if ($existing->trip_start->format('Y-m-d') !== $data['trip_start']) {
                    $conflicts[] = 'trip_start';
                }
                if ($existing->trip_end->format('Y-m-d') !== $data['trip_end']) {
                    $conflicts[] = 'trip_end';
                }
                if ($existing->plan_code !== strtoupper($data['plan_code'])) {
                    $conflicts[] = 'plan_code';
                }
                if ($existing->sum_insured !== (int) $data['sum_insured']) {
                    $conflicts[] = 'sum_insured';
                }

                if (!empty($conflicts)) {
                    throw new InvalidArgumentException(
                        'Idempotency key collision: A policy request with this agent_id and client_request_id already exists with different payload attributes (' . implode(', ', $conflicts) . ').'
                    );
                }

                return [
                    'is_new' => false,
                    'policy_request' => $existing,
                ];
            }

            try {
                $policyRequest = PolicyRequest::create([
                    'client_request_id' => $data['client_request_id'],
                    'agent_id' => $data['agent_id'],
                    'customer_name' => $data['customer_name'],
                    'date_of_birth' => $data['date_of_birth'],
                    'destination' => strtoupper($data['destination']),
                    'trip_start' => $data['trip_start'],
                    'trip_end' => $data['trip_end'],
                    'plan_code' => strtoupper($data['plan_code']),
                    'sum_insured' => (int) $data['sum_insured'],
                    'travel_days' => $pricing['travel_days'],
                    'base_daily_rate' => $pricing['base_daily_rate'],
                    'age_multiplier' => $pricing['age_multiplier'],
                    'destination_multiplier' => $pricing['destination_multiplier'],
                    'coverage_multiplier' => $pricing['coverage_multiplier'],
                    'calculated_premium' => $pricing['calculated_premium'],
                    'status' => PolicyStatus::VALIDATED, // Validated upon calculation
                    'attempt_count' => 0,
                ]);

                $this->recordHistory($policyRequest, PolicyStatus::RECEIVED, PolicyStatus::VALIDATED, 'INTAKE', [
                    'premium' => $pricing['calculated_premium'],
                    'travel_days' => $pricing['travel_days'],
                ]);

                return [
                    'is_new' => true,
                    'policy_request' => $policyRequest,
                ];
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                // Concurrency race: Another concurrent worker just created it
                $raceExisting = PolicyRequest::where('agent_id', $data['agent_id'])
                    ->where('client_request_id', $data['client_request_id'])
                    ->firstOrFail();

                return [
                    'is_new' => false,
                    'policy_request' => $raceExisting,
                ];
            }
        });
    }

    /**
     * Submit or safely resume issuance for a policy request.
     * Prevents duplicate provider calls, resolves timeouts before re-calling, and enforces state machine transitions.
     */
    public function submit(PolicyRequest $policyRequest, ?string $scenario = null): array
    {
        return DB::transaction(function () use ($policyRequest, $scenario) {
            // Lock record for update to avoid race conditions
            $policy = PolicyRequest::where('id', $policyRequest->id)->lockForUpdate()->firstOrFail();

            // 1. If already ISSUED, return immediately (idempotent; do not re-call provider)
            if ($policy->status === PolicyStatus::ISSUED) {
                return [
                    'status' => 'ALREADY_ISSUED',
                    'policy' => $policy,
                    'message' => 'Policy has already been issued successfully.',
                ];
            }

            // 2. If currently in NEEDS_RECONCILIATION or PENDING_CONFIRMATION, query provider first!
            // Case 2: Timeout after success handling - do NOT blindly issue again!
            if (in_array($policy->status, [PolicyStatus::NEEDS_RECONCILIATION, PolicyStatus::PENDING_CONFIRMATION])) {
                $reconciliationResult = $this->reconcileWithProvider($policy);
                if ($reconciliationResult && $policy->status === PolicyStatus::ISSUED) {
                    return [
                        'status' => 'RECONCILED_AND_ISSUED',
                        'policy' => $policy,
                        'message' => 'Policy confirmed issued via provider status lookup before reissue.',
                    ];
                }
            }

            // 3. Check state transition to SUBMITTED
            if (!$policy->status->canTransitionTo(PolicyStatus::SUBMITTED)) {
                throw new RuntimeException("Invalid state transition: Cannot transition from {$policy->status->value} to SUBMITTED.");
            }

            $fromState = $policy->status;
            $policy->status = PolicyStatus::SUBMITTED;
            $policy->attempt_count += 1;
            $attemptNumber = $policy->attempt_count;
            $policy->save();

            $this->recordHistory($policy, $fromState, PolicyStatus::SUBMITTED, 'ISSUANCE_CALL', [
                'attempt_number' => $attemptNumber,
                'scenario' => $scenario,
            ]);

            // Create issuance attempt record
            $idempotencyKey = 'ATTEMPT-' . $policy->id . '-' . $attemptNumber . '-' . Str::random(6);
            $attempt = IssuanceAttempt::create([
                'policy_request_id' => $policy->id,
                'attempt_number' => $attemptNumber,
                'idempotency_key' => $idempotencyKey,
                'scenario_requested' => $scenario,
                'status' => 'PENDING',
                'request_payload' => [
                    'client_request_id' => $policy->client_request_id,
                    'agent_id' => $policy->agent_id,
                    'customer_name' => $policy->customer_name,
                    'premium' => (float) $policy->calculated_premium,
                    'plan_code' => $policy->plan_code,
                    'sum_insured' => $policy->sum_insured,
                    'scenario' => $scenario,
                ],
            ]);

            // Call mock insurer
            $response = $this->mockInsurerService->issuePolicy([
                'client_request_id' => $policy->client_request_id,
                'agent_id' => $policy->agent_id,
                'customer_name' => $policy->customer_name,
                'premium' => (float) $policy->calculated_premium,
                'plan_code' => $policy->plan_code,
                'sum_insured' => $policy->sum_insured,
                'scenario' => $scenario,
            ]);

            $httpStatus = $response['http_status'];
            $data = $response['data'];

            $attempt->response_payload = $data;

            if ($httpStatus === 200 && ($data['status'] ?? '') === 'ISSUED') {
                // SUCCESS
                $attempt->status = 'SUCCESS';
                $attempt->provider_reference = $data['provider_reference'];
                $attempt->save();

                $oldState = $policy->status;
                $policy->status = PolicyStatus::ISSUED;
                $policy->policy_number = $data['policy_number'];
                $policy->provider_reference = $data['provider_reference'];
                $policy->last_response = $data;
                $policy->last_error_message = null;
                $policy->save();

                $this->recordHistory($policy, $oldState, PolicyStatus::ISSUED, 'ISSUANCE_CALL', [
                    'policy_number' => $data['policy_number'],
                    'provider_reference' => $data['provider_reference'],
                ]);

                return [
                    'status' => 'ISSUED',
                    'policy' => $policy,
                    'message' => 'Policy issued successfully.',
                ];
            }

            if ($httpStatus === 202 || ($data['status'] ?? '') === 'PENDING') {
                // DELAYED_CALLBACK
                $attempt->status = 'PENDING';
                $attempt->provider_reference = $data['provider_reference'] ?? null;
                $attempt->save();

                $oldState = $policy->status;
                $policy->status = PolicyStatus::PENDING_CONFIRMATION;
                $policy->provider_reference = $data['provider_reference'] ?? null;
                $policy->last_response = $data;
                $policy->save();

                $this->recordHistory($policy, $oldState, PolicyStatus::PENDING_CONFIRMATION, 'ISSUANCE_CALL', [
                    'provider_reference' => $data['provider_reference'] ?? null,
                ]);

                return [
                    'status' => 'PENDING_CONFIRMATION',
                    'policy' => $policy,
                    'message' => 'Policy pending confirmation from provider callback.',
                ];
            }

            if ($httpStatus === 504 || ($data['status'] ?? '') === 'TIMEOUT') {
                // TIMEOUT_AFTER_ISSUE (Case 2)
                $attempt->status = 'TIMED_OUT';
                $attempt->error_message = $data['message'] ?? 'Gateway Timeout';
                $attempt->save();

                $oldState = $policy->status;
                $policy->status = PolicyStatus::NEEDS_RECONCILIATION;
                $policy->last_response = $data;
                $policy->last_error_message = $data['message'] ?? 'Gateway Timeout';
                $policy->save();

                $this->recordHistory($policy, $oldState, PolicyStatus::NEEDS_RECONCILIATION, 'ISSUANCE_CALL', [
                    'reason' => 'Timeout encountered during provider issuance call',
                ]);

                return [
                    'status' => 'NEEDS_RECONCILIATION',
                    'policy' => $policy,
                    'message' => 'Provider call timed out. Marked for reconciliation.',
                ];
            }

            // TEMPORARY_FAILURE (Case 4) or generic failure
            $attempt->status = 'FAILED';
            $attempt->error_message = $data['message'] ?? 'Issuance failed';
            $attempt->save();

            $oldState = $policy->status;
            $policy->status = PolicyStatus::FAILED;
            $policy->last_response = $data;
            $policy->last_error_message = $data['message'] ?? 'Issuance failed';
            $policy->save();

            $this->recordHistory($policy, $oldState, PolicyStatus::FAILED, 'ISSUANCE_CALL', [
                'error' => $data['message'] ?? 'Unknown error',
            ]);

            return [
                'status' => 'FAILED',
                'policy' => $policy,
                'message' => $data['message'] ?? 'Issuance failed.',
            ];
        });
    }

    /**
     * Process simulated provider callback event.
     * Case 3: Duplicate callback handled idempotently using unique event_id and status guards.
     */
    public function handleCallback(array $eventData): array
    {
        return DB::transaction(function () use ($eventData) {
            $eventId = $eventData['event_id'] ?? null;
            if (!$eventId) {
                throw new InvalidArgumentException("Missing required event_id in callback payload.");
            }

            // Check if duplicate event_id received
            $existingEvent = ProviderEvent::where('event_id', $eventId)->first();
            if ($existingEvent) {
                return [
                    'is_duplicate' => true,
                    'message' => 'Event already processed. Duplicate callback ignored.',
                    'event' => $existingEvent,
                ];
            }

            $providerRef = $eventData['provider_reference'] ?? null;
            $eventType = $eventData['event_type'] ?? 'POLICY_ISSUED';
            $policyNumber = $eventData['policy_number'] ?? null;

            // Locate policy request by provider_reference or client_request_id
            $policyQuery = PolicyRequest::query();
            if ($providerRef) {
                $policyQuery->where('provider_reference', $providerRef);
            } elseif (!empty($eventData['client_request_id'])) {
                $policyQuery->where('client_request_id', $eventData['client_request_id']);
            }

            $policy = $policyQuery->lockForUpdate()->first();

            // Store event record
            $recordedEvent = ProviderEvent::create([
                'event_id' => $eventId,
                'event_type' => $eventType,
                'provider_reference' => $providerRef ?? ($policy?->provider_reference ?? 'UNKNOWN'),
                'policy_number' => $policyNumber,
                'policy_request_id' => $policy?->id,
                'payload' => $eventData,
                'status' => 'PROCESSED',
            ]);

            if (!$policy) {
                return [
                    'is_duplicate' => false,
                    'message' => 'Callback recorded but matching policy request not found.',
                    'event' => $recordedEvent,
                ];
            }

            // Enforce state machine rules:
            // If already ISSUED, cannot overwrite or regress
            if ($policy->status === PolicyStatus::ISSUED) {
                return [
                    'is_duplicate' => false,
                    'message' => 'Policy is already in ISSUED state. Confirmed issued status preserved.',
                    'policy' => $policy,
                    'event' => $recordedEvent,
                ];
            }

            if ($eventType === 'POLICY_ISSUED') {
                $oldState = $policy->status;
                if ($policy->status->canTransitionTo(PolicyStatus::ISSUED)) {
                    $policy->status = PolicyStatus::ISSUED;
                    $policy->policy_number = $policyNumber ?? ('POL-' . date('Y') . '-' . strtoupper(Str::random(8)));
                    if ($providerRef) {
                        $policy->provider_reference = $providerRef;
                    }
                    $policy->last_response = $eventData;
                    $policy->save();

                    $this->recordHistory($policy, $oldState, PolicyStatus::ISSUED, 'CALLBACK', [
                        'event_id' => $eventId,
                        'policy_number' => $policy->policy_number,
                    ]);
                }
            } elseif ($eventType === 'POLICY_REJECTED') {
                $oldState = $policy->status;
                if ($policy->status->canTransitionTo(PolicyStatus::FAILED)) {
                    $policy->status = PolicyStatus::FAILED;
                    $policy->last_error_message = $eventData['reason'] ?? 'Rejected by provider via callback';
                    $policy->last_response = $eventData;
                    $policy->save();

                    $this->recordHistory($policy, $oldState, PolicyStatus::FAILED, 'CALLBACK', [
                        'event_id' => $eventId,
                        'reason' => $policy->last_error_message,
                    ]);
                }
            }

            return [
                'is_duplicate' => false,
                'message' => 'Callback processed successfully.',
                'policy' => $policy,
                'event' => $recordedEvent,
            ];
        });
    }

    /**
     * Reconcile a policy request against provider records.
     */
    public function reconcileWithProvider(PolicyRequest $policy): bool
    {
        $lookup = $this->mockInsurerService->lookupStatus(
            partnerRequestId: $policy->client_request_id,
            providerReference: $policy->provider_reference
        );

        if (!$lookup) {
            return false;
        }

        if ($lookup['status'] === 'ISSUED') {
            $oldState = $policy->status;
            $policy->status = PolicyStatus::ISSUED;
            $policy->policy_number = $lookup['policy_number'];
            $policy->provider_reference = $lookup['provider_reference'];
            $policy->last_response = $lookup;
            $policy->last_error_message = null;
            $policy->save();

            $this->recordHistory($policy, $oldState, PolicyStatus::ISSUED, 'RECONCILIATION', [
                'provider_reference' => $lookup['provider_reference'],
                'policy_number' => $lookup['policy_number'],
            ]);

            return true;
        }

        return false;
    }

    /**
     * Record an explicit state change history entry.
     */
    protected function recordHistory(
        PolicyRequest $policy,
        PolicyStatus|string|null $fromState,
        PolicyStatus|string $toState,
        string $trigger,
        array $metadata = []
    ): void {
        PolicyStateHistory::create([
            'policy_request_id' => $policy->id,
            'from_state' => $fromState instanceof PolicyStatus ? $fromState->value : $fromState,
            'to_state' => $toState instanceof PolicyStatus ? $toState->value : $toState,
            'trigger_source' => $trigger,
            'metadata' => $metadata,
        ]);
    }
}
