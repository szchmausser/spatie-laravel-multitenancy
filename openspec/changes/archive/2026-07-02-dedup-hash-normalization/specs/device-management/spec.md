# Delta for device-management

## ADDED Requirements

### Requirement: Server-Side Dedup Hash Verification

When a device sends a payment notification via `POST /api/device/notifications`, the system MUST recompute the expected `dedup_hash` using `PaymentNotification::computeDedupHash($bankCode, $rawBody)` and compare it against the hash sent by the device. If they do not match, the system MUST log a warning with bank code and snippet of raw body and dispatch a `SystemAlert` notification. The system MUST NOT reject the notification on mismatch — the notification is still stored and processed normally.

#### Scenario: Hash mismatch logs warning and creates SystemAlert

- GIVEN a device sends `dedup_hash = "abc123"` but the server computes `"def456"`
- WHEN the notification is stored
- THEN a warning is logged with the bank code and raw body snippet
- AND a `SystemAlert` notification is created with severity `warning`
- AND the notification is stored successfully (status 200 `created`)

#### Scenario: Hash match proceeds without alert

- GIVEN a device sends `dedup_hash` matching server-computed value
- WHEN the notification is stored
- THEN no warning is logged
- AND no SystemAlert is created
- AND the notification is stored successfully

### Requirement: `SimulatePaymentNotification` uses BankCode enum

The `SimulatePaymentNotification` command MUST reference `BankCode::cases()` instead of a hardcoded `VALID_BANKS` array. The command SHALL randomly select a bank from the enum cases for simulation.
