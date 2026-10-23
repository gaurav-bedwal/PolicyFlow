# PolicyFlow — API Reference & cURL Commands

All endpoints are hosted locally on `http://127.0.0.1:8000`.

---

## 1. Create a Policy Request (Intake)

Submits a new travel policy request, validates applicant eligibility, and calculates an immutable decimal pricing snapshot.

```bash
curl -X POST http://127.0.0.1:8000/api/policy-requests \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "AGT-2026-9001",
    "agent_id": "AGENT-17",
    "customer_name": "Rohan Gupta",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }'
```

**Expected Response (`201 Created`):**
```json
{
  "message": "Policy request accepted and validated.",
  "data": {
    "id": "e4b6c31a-8e2b-4277-90c2-3bf2c82ff301",
    "client_request_id": "AGT-2026-9001",
    "agent_id": "AGENT-17",
    "customer_name": "Rohan Gupta",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "travel_days": 5,
    "customer_age_at_trip_start": 28,
    "plan_code": "PLUS",
    "sum_insured": "300000.00",
    "pricing_snapshot": {
      "base_daily_rate": "20.00",
      "age_multiplier": "1.00",
      "destination_multiplier": "1.80",
      "coverage_multiplier": "1.25",
      "premium_amount": "225.00",
      "currency": "INR"
    },
    "premium_amount": "225.00",
    "currency": "INR",
    "status": "VALIDATED",
    "provider_reference": null,
    "policy_number": null,
    "attempt_count": 0
  }
}
```

---

## 2. Replay Identical Request (Intake Idempotency)

Re-submitting the exact same `agent_id` + `client_request_id` with identical payload data safely returns the existing record without creating duplicate database rows.

```bash
curl -X POST http://127.0.0.1:8000/api/policy-requests \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "AGT-2026-9001",
    "agent_id": "AGENT-17",
    "customer_name": "Rohan Gupta",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }'
```

**Expected Response (`200 OK`):**
```json
{
  "message": "Idempotent replay: identical policy request already accepted.",
  "data": { ... }
}
```

---

## 3. Submit Conflicting Payload (Conflict Detection)

Reusing the same `agent_id` + `client_request_id` with *different* parameters (e.g. different destination or sum insured) is safely blocked to protect audit integrity.

```bash
curl -X POST http://127.0.0.1:8000/api/policy-requests \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "AGT-2026-9001",
    "agent_id": "AGENT-17",
    "customer_name": "Rohan Gupta",
    "date_of_birth": "1998-04-10",
    "destination": "DOMESTIC",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "BASIC",
    "sum_insured": 100000
  }'
```

**Expected Response (`409 Conflict`):**
```json
{
  "message": "Idempotency conflict: A policy request with this agent_id and client_request_id already exists with different payload attributes.",
  "error_code": "IDEMPOTENCY_PAYLOAD_MISMATCH",
  "existing_request_id": "e4b6c31a-8e2b-4277-90c2-3bf2c82ff301"
}
```

---

## 4. Retrieve Single Policy Request & Audit Trail

```bash
curl -X GET http://127.0.0.1:8000/api/policy-requests/e4b6c31a-8e2b-4277-90c2-3bf2c82ff301 \
  -H "Accept: application/json"
```

**Expected Response (`200 OK`):**
Returns the policy state, pricing snapshot, and full append-only transition log (`state_history`).

---

## 5. List Policy Requests (Paginated)

```bash
curl -X GET "http://127.0.0.1:8000/api/policy-requests?per_page=10&page=1" \
  -H "Accept: application/json"
```

---

## 6. Filter Policy Requests by Status

```bash
curl -X GET "http://127.0.0.1:8000/api/policy-requests?status=ISSUED" \
  -H "Accept: application/json"
```

---

## 7. Submit Policy Issuance (Standard Success)

Submits the validated policy to the upstream mock insurer. Uses stable idempotency key `IDEM-POL-{uuid}`.

```bash
curl -X POST http://127.0.0.1:8000/api/policy-requests/e4b6c31a-8e2b-4277-90c2-3bf2c82ff301/submit \
  -H "Accept: application/json"
```

**Expected Response (`200 OK`):**
```json
{
  "message": "Policy successfully issued.",
  "policy_number": "POL-98AB721CD5",
  "data": {
    "status": "ISSUED",
    "policy_number": "POL-98AB721CD5",
    "provider_reference": "PROV-87F0123AB"
  }
}
```

---

## 8. Simulate Temporary Provider Failure (Retriable)

```bash
curl -X POST http://127.0.0.1:8000/api/policy-requests/{id}/submit \
  -H "Accept: application/json" \
  -H "X-Mock-Scenario: TEMPORARY_FAILURE"
```

**Expected Response (`503 Service Unavailable`):**
```json
{
  "message": "Temporary insurer failure. Request can be safely retried.",
  "error_category": "TEMPORARY_FAILURE",
  "data": {
    "status": "FAILED"
  }
}
```

---

## 9. Simulate Timeout After Provider Success (Reliability Case 2)

Simulates the critical network anomaly where the mock insurer creates the policy internally, but network connectivity drops before the 200 response returns to the client. The engine automatically enters reconciliation and recovers the policy!

```bash
curl -X POST http://127.0.0.1:8000/api/policy-requests/{id}/submit \
  -H "Accept: application/json" \
  -H "X-Mock-Scenario: TIMEOUT_AFTER_ISSUE"
```

**Expected Response (`200 OK`):**
```json
{
  "message": "Timeout occurred during submission, but policy was successfully recovered via reconciliation.",
  "policy_number": "POL-XYZ990011",
  "is_recovered": true,
  "data": {
    "status": "ISSUED"
  }
}
```

---

## 10. Query Provider Status Lookup

Directly queries the mock insurer's authoritative database by provider reference or idempotency key.

```bash
curl -X GET http://127.0.0.1:8000/api/mock-insurer/policies/PROV-SEED-8801 \
  -H "Accept: application/json"
```

---

## 11. Deliver Provider Asynchronous Callback

Simulates receipt of an underwriting confirmation webhook from the insurer.

```bash
curl -X POST http://127.0.0.1:8000/api/mock-insurer/callback \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "provider_event_id": "evt-demo-callback-1001",
    "provider_reference": "PROV-SEED-9902",
    "event_type": "POLICY_ISSUED",
    "policy_number": "POL-ASYNC-9902"
  }'
```

**Expected Response (`200 OK`):**
```json
{
  "status": "PROCESSED",
  "message": "Provider callback processed successfully.",
  "policy_status": "ISSUED"
}
```

---

## 12. Replay Duplicate Callback (Reliability Case 3)

Re-sending the same callback event ID triggers database deduplication.

```bash
curl -X POST http://127.0.0.1:8000/api/mock-insurer/callback \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "provider_event_id": "evt-demo-callback-1001",
    "provider_reference": "PROV-SEED-9902",
    "event_type": "POLICY_ISSUED",
    "policy_number": "POL-ASYNC-9902"
  }'
```

**Expected Response (`200 OK`):**
```json
{
  "status": "DUPLICATE_EVENT_IGNORED",
  "message": "Event has already been received and processed.",
  "provider_event_id": "evt-demo-callback-1001"
}
```

---

## 13. Demonstrate Field Validation Errors

```bash
curl -X POST http://127.0.0.1:8000/api/policy-requests \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "AGT-INVALID",
    "agent_id": "AGENT-01",
    "customer_name": "Test",
    "date_of_birth": "2026-12-01",
    "destination": "INVALID_DEST",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-05",
    "plan_code": "INVALID_PLAN",
    "sum_insured": 999999
  }'
```

**Expected Response (`422 Unprocessable Entity`):**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "date_of_birth": ["date_of_birth must be earlier than trip_start."],
    "trip_end": ["trip_end must be on or after trip_start."],
    "destination": ["destination must be either DOMESTIC or INTERNATIONAL."],
    "plan_code": ["plan_code must be one of: BASIC, PLUS, PREMIUM."],
    "sum_insured": ["sum_insured must be exactly one of: 100000, 300000, or 500000."]
  }
}
```
