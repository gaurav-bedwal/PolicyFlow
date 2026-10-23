# AI Usage Disclosure — PolicyFlow Engine

In compliance with the campus software engineering assessment guidelines, this document discloses how AI tools were utilized during the conception, development, and testing of **PolicyFlow**.

---

## 1. Tools Used

- **Google Antigravity Agent (Gemini 2.5/Flash Developer Preview)**: Used as an interactive pair programming assistant within the IDE for code scaffolding, architectural guidance, test case authoring, and documentation generation.
- **PHP 8.5 & Composer 2.9 CLI Tools**: Local runtime execution, dependency resolution, and test runners.

---

## 2. Concrete Ways AI Assisted

1. **Deterministic Financial Pricing Logic**:
   AI assisted in designing the `PremiumCalculator` service using PHP's `bcmath` extension, ensuring that financial calculations (multipliers, inclusive calendar travel days, and age boundary resolution) avoid floating-point binary drift and adhere strictly to two-decimal financial precision.

2. **Idempotency & Concurrency Strategy**:
   AI helped model the database constraints (`agent_id` + `client_request_id` compound uniqueness on intake, and `provider_event_id` uniqueness on callbacks) and structured the row-locking mechanism (`lockForUpdate()`) to isolate provider HTTP communications from long-running database transactions.

3. **Comprehensive Test Suite Generation**:
   AI generated test fixtures covering edge cases: same-day trips, leap-year boundary dates, age thresholds (0, 17, 18, 60, 61, 70, 71), duplicate callback replays, and provider timeout recovery (`TIMEOUT_AFTER_ISSUE`).

---

## 3. Incomplete or Incorrect Suggestions Encountered and Resolutions

1. **PHPUnit Deprecation Handling in PHP 8.5**:
   - *Issue Encountered*: During initial test suite execution, PHPUnit 11 flagged deprecation warnings for `PDO::MYSQL_ATTR_SSL_CA` within Laravel framework's database configuration under PHP 8.5. An initial suggestion was to add `ignoreSuppressedErrors="true"` to `<source>` in `phpunit.xml`.
   - *Resolution*: This caused an XML schema validation failure because `ignoreSuppressedErrors` is not a valid attribute on `<source>` in the PHPUnit 11 XSD. The issue was resolved by using valid schema configuration (`ignoreIndirectDeprecations="true"` and `failOnDeprecation="false"`), updating `config/database.php` to conditionally use `Pdo\Mysql::ATTR_SSL_CA` when defined, and configuring `error_reporting(E_ALL & ~E_DEPRECATED)` in `bootstrap/app.php`.

2. **Test Header State Leakage Across Sequential Test Assertions**:
   - *Issue Encountered*: In `test_safe_retry_after_temporary_failure`, the first request set `X-Mock-Scenario: TEMPORARY_FAILURE` via `$this->withHeaders()`. The subsequent retry request unexpectedly failed with HTTP 503 instead of 200 because Laravel's test client retains headers across requests within the same test method.
   - *Resolution*: Identified that headers remained in the test runner's state and added `$this->flushHeaders()` prior to dispatching the second submission request.

3. **In-Memory Eloquent Model Status Staling During Transactions**:
   - *Issue Encountered*: When submitting an issuance, the policy was locked and transitioned inside a database closure (`$lockedPolicy->transitionTo(PolicyStatus::SUBMITTED)`). When an upstream timeout exception was caught immediately afterward, attempting `$policy->transitionTo(PolicyStatus::NEEDS_RECONCILIATION)` failed validation because the outer `$policy` variable still held the stale in-memory status `VALIDATED`.
   - *Resolution*: Reassigned `$policy = $policy->fresh()` immediately following transaction commit so in-memory state matches the database before proceeding to provider dispatch.

---

## 4. How the Implementation was Tested and Verified

All business logic, database migrations, and REST endpoints were independently verified on a local macOS environment:

1. **MySQL 8+ Database Execution**:
   Tested directly against a local running MySQL database instance (`policyflow_test` on `127.0.0.1:3306`), confirming actual MySQL constraints, transactions, and row-level locking.
2. **Automated Unit & Feature Tests**:
   Executed the full test suite via `php artisan test`. All 34 tests and 165 assertions passed successfully.
3. **Manual cURL & UI Verification**:
   Started the local development server via `php artisan serve` and verified endpoints using real HTTP requests:
   - Verified HTTP 201 on initial intake and HTTP 200 on identical replay.
   - Verified HTTP 409 when altering payload on an existing client request ID.
   - Verified automatic recovery during `TIMEOUT_AFTER_ISSUE` scenario.
   - Verified duplicate callback deduplication.
