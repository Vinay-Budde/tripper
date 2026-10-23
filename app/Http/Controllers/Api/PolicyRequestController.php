<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IntakePolicyRequest;
use App\Models\PolicyRequest;
use App\Services\PolicyFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PolicyRequestController extends Controller
{
    public function __construct(
        protected PolicyFlowService $policyFlowService
    ) {}

    /**
     * POST /api/policy-requests
     * Create or safely return existing request.
     * 201 for newly created request, 200 for safely repeated request.
     */
    public function store(IntakePolicyRequest $request): JsonResponse
    {
        try {
            $result = $this->policyFlowService->intake($request->validated());
            $policy = $result['policy_request'];
            $isNew = $result['is_new'];

            $statusCode = $isNew ? 201 : 200;

            return response()->json([
                'success' => true,
                'message' => $isNew ? 'Policy request created and priced successfully.' : 'Existing policy request returned safely (idempotent intake).',
                'is_duplicate' => !$isNew,
                'data' => [
                    'id' => $policy->id,
                    'uuid' => $policy->uuid,
                    'client_request_id' => $policy->client_request_id,
                    'agent_id' => $policy->agent_id,
                    'status' => $policy->status->value,
                    'customer_name' => $policy->customer_name,
                    'plan_code' => $policy->plan_code,
                    'sum_insured' => $policy->sum_insured,
                    'travel_days' => $policy->travel_days,
                    'pricing_breakdown' => [
                        'base_daily_rate' => (float) $policy->base_daily_rate,
                        'age_multiplier' => (float) $policy->age_multiplier,
                        'destination_multiplier' => (float) $policy->destination_multiplier,
                        'coverage_multiplier' => (float) $policy->coverage_multiplier,
                        'calculated_premium' => (float) $policy->calculated_premium,
                    ],
                    'policy_number' => $policy->policy_number,
                    'created_at' => $policy->created_at->toIso8601String(),
                ],
            ], $statusCode);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process intake request.',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * GET /api/policy-requests/{id}
     * Retrieve status, premium, references and audit trail.
     */
    public function show(string $id): JsonResponse
    {
        $policy = PolicyRequest::with(['attempts', 'stateHistories', 'providerEvents'])
            ->where('id', $id)
            ->orWhere('uuid', $id)
            ->orWhere('client_request_id', $id)
            ->first();

        if (!$policy) {
            return response()->json([
                'success' => false,
                'message' => "Policy request with identifier '{$id}' not found.",
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $policy->id,
                'uuid' => $policy->uuid,
                'client_request_id' => $policy->client_request_id,
                'agent_id' => $policy->agent_id,
                'status' => $policy->status->value,
                'policy_number' => $policy->policy_number,
                'provider_reference' => $policy->provider_reference,
                'attempt_count' => $policy->attempt_count,
                'customer_name' => $policy->customer_name,
                'date_of_birth' => $policy->date_of_birth->format('Y-m-d'),
                'destination' => $policy->destination,
                'trip_start' => $policy->trip_start->format('Y-m-d'),
                'trip_end' => $policy->trip_end->format('Y-m-d'),
                'plan_code' => $policy->plan_code,
                'sum_insured' => $policy->sum_insured,
                'travel_days' => $policy->travel_days,
                'calculated_premium' => (float) $policy->calculated_premium,
                'pricing_snapshot' => [
                    'base_daily_rate' => (float) $policy->base_daily_rate,
                    'age_multiplier' => (float) $policy->age_multiplier,
                    'destination_multiplier' => (float) $policy->destination_multiplier,
                    'coverage_multiplier' => (float) $policy->coverage_multiplier,
                    'calculated_premium' => (float) $policy->calculated_premium,
                ],
                'last_response' => $policy->last_response,
                'last_error_message' => $policy->last_error_message,
                'audit_trail' => $policy->stateHistories->map(fn ($h) => [
                    'id' => $h->id,
                    'from_state' => $h->from_state,
                    'to_state' => $h->to_state,
                    'trigger_source' => $h->trigger_source,
                    'metadata' => $h->metadata,
                    'timestamp' => $h->created_at->toIso8601String(),
                ]),
                'attempts' => $policy->attempts->map(fn ($a) => [
                    'attempt_number' => $a->attempt_number,
                    'idempotency_key' => $a->idempotency_key,
                    'scenario' => $a->scenario_requested,
                    'status' => $a->status,
                    'provider_reference' => $a->provider_reference,
                    'error_message' => $a->error_message,
                    'attempted_at' => $a->attempted_at?->toIso8601String(),
                ]),
                'created_at' => $policy->created_at->toIso8601String(),
                'updated_at' => $policy->updated_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/policy-requests/{id}/submit
     * Start or safely resume issuance.
     */
    public function submit(Request $request, string $id): JsonResponse
    {
        $policy = PolicyRequest::where('id', $id)
            ->orWhere('uuid', $id)
            ->first();

        if (!$policy) {
            return response()->json([
                'success' => false,
                'message' => "Policy request '{$id}' not found.",
            ], 404);
        }

        $scenario = $request->input('scenario'); // Optional development scenario override

        try {
            $result = $this->policyFlowService->submit($policy, $scenario);
            $freshPolicy = $result['policy'];

            return response()->json([
                'success' => in_array($freshPolicy->status->value, ['ISSUED', 'PENDING_CONFIRMATION']),
                'outcome' => $result['status'],
                'message' => $result['message'],
                'data' => [
                    'id' => $freshPolicy->id,
                    'status' => $freshPolicy->status->value,
                    'policy_number' => $freshPolicy->policy_number,
                    'provider_reference' => $freshPolicy->provider_reference,
                    'attempt_count' => $freshPolicy->attempt_count,
                    'last_error' => $freshPolicy->last_error_message,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process issuance.',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * GET /api/policy-requests
     * List requests with status filter and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PolicyRequest::query();

        if ($status = $request->query('status')) {
            $query->where('status', strtoupper($status));
        }

        if ($agentId = $request->query('agent_id')) {
            $query->where('agent_id', $agentId);
        }

        $perPage = min((int) ($request->query('per_page', 15)), 100);
        $paginated = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $paginated->items(),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }
}
