<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MockInsurerService;
use App\Services\PolicyFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class MockInsurerController extends Controller
{
    public function __construct(
        protected MockInsurerService $mockInsurerService,
        protected PolicyFlowService $policyFlowService
    ) {}

    /**
     * POST /api/mock-insurer/callback
     * Receive a simulated provider event.
     */
    public function callback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event_id' => 'required|string',
            'event_type' => 'required|string',
            'provider_reference' => 'nullable|string',
            'policy_number' => 'nullable|string',
            'client_request_id' => 'nullable|string',
            'status' => 'nullable|string',
        ]);

        try {
            $result = $this->policyFlowService->handleCallback($request->all());

            return response()->json([
                'success' => true,
                'is_duplicate' => $result['is_duplicate'],
                'message' => $result['message'],
                'data' => [
                    'event_id' => $result['event']->event_id,
                    'status' => $result['event']->status,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process callback.',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Development mock direct endpoint: POST /api/mock-insurer/issue
     */
    public function issue(Request $request): JsonResponse
    {
        $res = $this->mockInsurerService->issuePolicy($request->all());
        return response()->json($res['data'], $res['http_status']);
    }

    /**
     * Development mock lookup endpoint: GET /api/mock-insurer/status
     */
    public function status(Request $request): JsonResponse
    {
        $partnerRequestId = $request->query('client_request_id', '');
        $providerRef = $request->query('provider_reference');

        $result = $this->mockInsurerService->lookupStatus($partnerRequestId, $providerRef);
        if (!$result) {
            return response()->json([
                'found' => false,
                'message' => 'Policy record not found in provider database.',
            ], 404);
        }

        return response()->json($result);
    }
}
