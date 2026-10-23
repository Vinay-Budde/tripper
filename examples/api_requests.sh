#!/usr/bin/env bash

# PolicyFlow Sample API Requests and Use Cases
BASE_URL="http://127.0.0.1:8000"

echo "=== 1. Policy Request Intake (Create new request) ==="
curl -s -X POST "$BASE_URL/api/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "customer_name": "Sample Customer",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }' | jq .

echo -e "\n=== 2. Duplicate Intake (Safely returns existing record with HTTP 200) ==="
curl -s -X POST "$BASE_URL/api/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "customer_name": "Sample Customer",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }' | jq .

echo -e "\n=== 3. Submit Policy Request for Issuance (SUCCESS scenario) ==="
curl -s -X POST "$BASE_URL/api/policy-requests/1/submit" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"scenario": "SUCCESS"}' | jq .

echo -e "\n=== 4. Retrieve Policy Status & Audit Trail ==="
curl -s -X GET "$BASE_URL/api/policy-requests/1" \
  -H "Accept: application/json" | jq .

echo -e "\n=== 5. Timeout After Success Simulation (Needs Reconciliation) ==="
curl -s -X POST "$BASE_URL/api/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "AGT-2026-TIMEOUT-EXAMPLE",
    "agent_id": "AGENT-99",
    "customer_name": "Timeout User",
    "date_of_birth": "1992-05-15",
    "destination": "DOMESTIC",
    "trip_start": "2026-12-01",
    "trip_end": "2026-12-05",
    "plan_code": "BASIC",
    "sum_insured": 100000
  }' | jq .

curl -s -X POST "$BASE_URL/api/policy-requests/2/submit" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"scenario": "TIMEOUT_AFTER_ISSUE"}' | jq .

echo -e "\n=== 6. Reconcile Policy (Safe status lookup before reissue) ==="
curl -s -X POST "$BASE_URL/api/policy-requests/2/submit" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" | jq .

echo -e "\n=== 7. Webhook / Callback Simulation (Idempotent delivery) ==="
curl -s -X POST "$BASE_URL/api/mock-insurer/callback" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "event_id": "EVT-EXAMPLE-001",
    "event_type": "POLICY_ISSUED",
    "client_request_id": "AGT-2026-0001",
    "policy_number": "POL-2026-MOCK99"
  }' | jq .

echo -e "\n=== 8. List Policy Requests with Filter & Pagination ==="
curl -s -X GET "$BASE_URL/api/policy-requests?status=ISSUED&per_page=10" \
  -H "Accept: application/json" | jq .
