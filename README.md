# PolicyFlow: Fault-Tolerant Insurance Issuance & Reconciliation Engine

> **TrippersShield / Campus Engineering Challenge**  
> Fictional Travel Insurance Distributor Engine built with **PHP 8.2+ and Laravel 12**.

---

## 1. Overview & Architecture

**PolicyFlow** is a fault-tolerant insurance issuance and reconciliation engine engineered to safely intake travel policy purchase requests from agents, price them deterministically, submit them to an unreliable external mock insurer, and manage policy lifecycles through explicit state transitions and audit logging.

### Architectural Diagram

```
                 +-----------------------+
                 | Agent / Client Intake |
                 +-----------+-----------+
                             | POST /api/policy-requests
                             v
                 +-----------------------+
                 |  Intake Validation    |
                 |  & Premium Snapshot   |
                 +-----------+-----------+
                             |
                  [Unique: agent_id + client_request_id]
                             |
                             v
                   +------------------+
                   |  PolicyRequest   | <====== State Machine & Audit Trail
                   | (VALIDATED state)|
                   +---------+--------+
                             |
                             | POST /api/policy-requests/{id}/submit
                             v
                   +------------------+
                   |  PolicyFlow      |
                   |  Service Engine  |
                   +----+--------+----+
                        |        ^
       issuePolicy()    |        | lookupStatus() / reconciliation
       (HTTP 200/504)   v        |
                  +--------------------+
                  |    Mock Insurer    |
                  |  (Simulated Host)  |
                  +---------+----------+
                            |
                            | Webhook Callback
                            v
               POST /api/mock-insurer/callback
               (Deduplicated via unique event_id)
```

---

## 2. Core Features & Business Logic

### 2.1 Deterministic Pricing Engine (`PremiumCalculationService`)
- **Daily Base Rates**: `BASIC` = ₹12, `PLUS` = ₹20, `PREMIUM` = ₹30.
- **Age Multipliers** (at `trip_start`):
  - 0–17: `1.10`
  - 18–60: `1.00`
  - 61–70: `1.50`
  - 71+: `2.00`
- **Destination Multipliers**: `DOMESTIC` = `1.00`, `INTERNATIONAL` = `1.80`.
- **Coverage Multipliers**: ₹100,000 = `1.00`, ₹300,000 = `1.25`, ₹500,000 = `1.50`.
- **Formula**: `premium = base_daily_rate * travel_days * age_multiplier * destination_multiplier * coverage_multiplier`.
- **Decimal Safety**: All calculations use `bcmul` fixed-precision arithmetic, rounded to 2 decimal places. Immutable pricing snapshots are saved with each request.

### 2.2 Lifecycle State Machine
Supported statuses (`App\Enums\PolicyStatus`):
- `RECEIVED`: Raw intake.
- `VALIDATED`: Fields verified & premium snapshot calculated.
- `SUBMITTED`: Under active transmission to provider.
- `PENDING_CONFIRMATION`: Asynchronous or queued issuance pending provider callback.
- `ISSUED`: Final terminal state; guarantees a unique `policy_number`. Cannot be regressed or overwritten.
- `FAILED`: Insurer returned a retriable error or validation rejection. Retries permitted.
- `NEEDS_RECONCILIATION`: Call timed out (504/timeout). Insurer state must be checked before attempting reissue.

---

## 3. Reliability & Idempotency Strategies

PolicyFlow implements **all 4 reliability scenarios** specified in the challenge:

1. **Duplicate Intake (Case 1)**:
   - Database constraint: `UNIQUE (agent_id, client_request_id)`.
   - On repeat intake: Returns the existing policy request with HTTP 200 and `is_duplicate: true`. First-time requests return HTTP 201 Created.

2. **Timeout After Success (Case 2)**:
   - If the client times out while the insurer successfully created the policy internally (`TIMEOUT_AFTER_ISSUE`), PolicyFlow transitions the record to `NEEDS_RECONCILIATION`.
   - On the next submit/reissue attempt, the engine **never blindly creates a new policy**; instead, it executes `lookupStatus()` against the insurer. If confirmed issued, it adopts the existing `policy_number` and transitions cleanly to `ISSUED`.

3. **Duplicate Callback (Case 3)**:
   - Callbacks require an `event_id` and are guarded by a unique database constraint (`provider_events.event_id`).
   - If a webhook is redelivered 2–3 times, subsequent deliveries are acknowledged as duplicates (`is_duplicate: true`) without re-applying state changes or altering confirmed policies.

4. **Retry After Temporary Failure (Case 4)**:
   - Temporary failures (`TEMPORARY_FAILURE` / HTTP 503) transition the policy to `FAILED`.
   - Re-submitting the request resumes issuance safely under transaction locks, tracking attempt numbers while strictly preserving the initial accepted premium snapshot.

---

## 4. API Endpoints

| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/api/policy-requests` | Intake policy request (HTTP 201 new, HTTP 200 repeat) |
| `GET` | `/api/policy-requests/{id}` | Read policy status, pricing breakdown, and full audit trail |
| `POST` | `/api/policy-requests/{id}/submit` | Start or safely resume issuance (supports `scenario` parameter) |
| `GET` | `/api/policy-requests` | List policy requests with status filter & pagination |
| `POST` | `/api/mock-insurer/callback` | Webhook callback endpoint for asynchronous provider events |
| `GET` | `/api/mock-insurer/status` | Provider status lookup endpoint used for reconciliation |

---

## 5. Local Setup & Execution Guide

### Requirements
- PHP 8.2 or higher
- Composer 2+
- SQLite or MySQL

### Installation Steps

```bash
# 1. Clone repository & enter directory
cd c:\tripper

# 2. Copy environment file (configured by default for zero-config SQLite)
cp .env.example .env

# 3. Install composer dependencies (if not already installed)
composer install

# 4. Generate app key & create database
php artisan key:generate
touch database/database.sqlite
php artisan migrate --force

# 5. Run tests
php artisan test
```

### Running the Application

```bash
php artisan serve
```
Application will be live at `http://127.0.0.1:8000`.

### Background Reconciliation Command
To detect stale `PENDING_CONFIRMATION` or `NEEDS_RECONCILIATION` policies and synchronize with the mock insurer:
```bash
php artisan policyflow:reconcile
```

---

## 6. Automated Testing Matrix

Run the test suite:
```bash
php artisan test
```

Test coverage includes:
- `Tests\Unit\PremiumCalculationTest`:
  - Official specification test case (₹225.00 snapshot).
  - All age tiers (0–17, 18–60, 61–70, 71+).
  - Multiplier combinations and validation rejections.
- `Tests\Feature\PolicyFlowApiTest`:
  - Intake contract & 422 validations.
  - Idempotent intake (HTTP 201 vs 200).
  - Case 1: Duplicate intake protection.
  - Case 2: Timeout resolution without blind reissue.
  - Case 3: Duplicate callback deduplication.
  - Case 4: Temporary failure retries with immutable premium.
  - Audit trail retrieval & pagination.
- `Tests\Feature\ReconcileCommandTest`:
  - Artisan command reconciliation execution.

---

## 7. Assumptions & Known Limitations
1. **Mock Insurer Service**: Implemented as an in-process service with simulated network delays and status tables to allow standalone execution without external network dependencies.
2. **Database Engine**: Defaults to SQLite for seamless reviewer evaluation without running external database services. Database migrations are fully compatible with MySQL 8+ and PostgreSQL.
