# AI Usage Disclosure

## 1. AI Tools Used
- **Antigravity (Gemini 3.8 Flash / Claude 3.5 Sonnet agentic environment)** for scaffolding, code generation, database schema formulation, and automated test authoring.
- **PHP CLI & Composer platform tools** for local execution and validation.

## 2. Concrete Ways AI Helped
1. **Precision Formula & Boundary Testing**: Formulated the `PremiumCalculationService` utilizing decimal-safe operations (`bcmul`) to eliminate IEEE-754 floating point imprecision across multiple tiers (age tiers, destination multipliers, and coverage tiers) as outlined in the specification.
2. **State Machine & Concurrency Strategy**: Designed strict transitions within `App\Enums\PolicyStatus` ensuring irreversible terminal states (`ISSUED` cannot regress or be overwritten) and enforced pessimistic row locking (`lockForUpdate()`) combined with composite unique database indexes (`agent_id` + `client_request_id`) to prevent duplicate issuance and race conditions.
3. **Comprehensive Reliability Scenarios**: Rapidly generated unit and feature tests covering all 4 failure scenarios (Duplicate intake, Timeout after success, Duplicate callback, and Retry after temporary failure).

## 3. Incorrect or Incomplete Suggestions Encountered & Resolved
- **Floating-point Age Calculation**: An initial Carbon calculation (`$birthDate->diffInYears($startDate)`) produced a float (`28.58...`) rather than an integer age (`28`) for the 1998-04-10 birth date against the 2026-11-10 trip start date. This caused an initial unit test assertion mismatch. The code was immediately updated to explicitly cast `(int) $birthDate->diffInYears($startDate)`, aligning strictly with the prompt's age bracket rules.
- **SQLite Database Creation**: The initial migration step prompted interactively for SQLite database creation on the CLI. A clean initialization script was created to instantiate `database.sqlite` automatically before migrating, ensuring non-interactive repeatability.

## 4. Verification and Testing
All AI-generated code was validated locally using:
- Automated test suites (`php artisan test`) with 100% pass rate (18 tests, 119 assertions).
- Migration inspection (`php artisan migrate:status`).
- Direct Artisan console execution (`php artisan policyflow:reconcile`).
