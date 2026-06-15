# Tasks: Payment Sender-Receiver Separation

> **Source of truth**: `docs/phase-2a-payment-architecture.md`
> **Design**: `openspec/changes/payment-sender-receiver-separation/design.md`
> **Specs**: `openspec/changes/payment-sender-receiver-separation/specs/`
> **Config**: `openspec/config.yaml` — strict TDD enabled

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | 400–480 |
| 400-line budget risk | Moderate |
| Chained PRs recommended | Optional |
| Suggested split | PR 1 (migrations+models) → PR 2 (gateways+controller) → PR 3 (tests+cleanup) |
| Delivery strategy | sequential |
| Chain strategy | pending |

## STRICT TDD MODE

Per `openspec/config.yaml::testing.strict_tdd` and `openspec/config.yaml::apply.tdd`:
- Every code change **MUST** begin with writing its test first (RED)
- Then implement production code (GREEN)
- Run `php artisan test --compact --filter=<test>` to confirm green
- Then refactor if needed
- Run `vendor/bin/pint --format agent` before finalizing each phase

---

## Phase 1: Database — Migrations

- [x] 1.1 Write migration test (RED) + create migration `2026_06_14_000001_add_sender_receiver_separation_columns.php` — three changes in one file: `payment_method_config_id` FK (nullable, nullOnDelete) on `payments`, `sender_id` (nullable, varchar(20)) on `pago_movil_details`, and 6 sender fields (all nullable for backward compat) on `bank_transfer_details`. Include full down method. Run test → verify RED before migration, GREEN after migration up. (M)
- [x] 1.2 Run migration up on landlord connection — verify all 8 columns exist across 3 tables: `payments.payment_method_config_id` (FK, nullable), `pago_movil_details.sender_id` (varchar(20), nullable), `bank_transfer_details.sender_bank/sender_name/sender_id/tenant_rif/payment_date/concept`. Run `php artisan migrate:rollback` — verify columns removed, FK constraint dropped. (S)
- [x] 1.3 Verify migration is purely additive — confirm no data loss on existing rows. Query existing `pago_movil_details` rows have NULL `sender_id`, existing `bank_transfer_details` rows have NULL sender fields, existing `payments` rows have NULL `payment_method_config_id`. (S)

## Phase 2: Model Changes

- [x] 2.1 Update `app/Models/Payment.php` — add `payment_method_config_id` to `$fillable` array, add `paymentMethodConfig()` BelongsTo relationship to `PaymentMethodConfig::class`. Write unit test (RED→GREEN): mass assignment of `payment_method_config_id`, eager loading `paymentMethodConfig` returns related config or null. (S)
- [x] 2.2 Update `app/Models/PagoMovilDetail.php` — add `sender_id` to `$fillable` array (after `sender_phone`). Write unit test (RED→GREEN): `PagoMovilDetail::create()` accepts and persists `sender_id`. (S)
- [x] 2.3 Update `app/Models/BankTransferDetail.php` — add all 6 sender fields to `$fillable` array (after `holder_id`): `sender_bank`, `sender_name`, `sender_id`, `tenant_rif`, `payment_date`, `concept`. Add `casts()` method returning `['payment_date' => 'date']`. Write unit test (RED→GREEN): `BankTransferDetail::create()` accepts and persists all 6 fields, `payment_date` is cast to Carbon instance. (S)

## Phase 3: Gateway Changes

- [x] 3.1 Update `app/Services/Payment/PagoMovilGateway.php` — pass `payment_method_config_id` to `Payment::create()`, pass `sender_id` to `PagoMovilDetail::create()`. Update PHPDoc `@param` array shape to include `sender_id`. Write test (RED→GREEN): `recordPayment()` with `payment_method_config_id` creates Payment with config FK; `recordPayment()` with `sender_id` creates PagoMovilDetail with sender_id persisted. (M)
- [x] 3.2 Update `app/Services/Payment/BankTransferGateway.php` — pass `payment_method_config_id` to `Payment::create()`, pass all 6 sender fields to `BankTransferDetail::create()`. Update PHPDoc `@param` array shape to include all sender fields. Write test (RED→GREEN): `recordPayment()` with config ID + sender fields creates Payment with config FK AND BankTransferDetail with all 6 sender fields persisted. Test that `tenant_rif` is nullable, `concept` is nullable. (M)

## Phase 4: Controller Changes

- [x] 4.1 Update `app/Http/Controllers/Tenant/PaymentController.php@store()` — add `sender_id` validation (required, string, max:20 when `payment_method === 'pago_movil'`, otherwise nullable). Add `sender_name` validation (required, string, max:100 when `payment_method === 'bank_transfer'`, otherwise nullable). Add `tenant_rif` validation (nullable, string, max:20 for both). Update `$gatewayData` builder: for `pago_movil` include `sender_id`, for `bank_transfer` include `sender_bank`, `sender_name`, `sender_id`, `tenant_rif`, `payment_date`, `concept`. Write feature test (RED→GREEN): bank_transfer without `sender_bank` → 422, without `sender_name` → 422, without `sender_id` → 422, without `payment_date` → 422, with all fields → 200 + detail created. Pago_movil without `sender_id` → 422 (new). (M)

## Phase 5: Tests

- [x] 5.1 Migration test — write `tests/Feature/Migrations/SenderReceiverSeparationMigrationTest.php`. Verify: after migration up, all 8 columns exist; `payment_method_config_id` FK has `nullOnDelete` behavior (delete config → payment FK becomes NULL); after migration down, all columns removed. (S)
- [x] 5.2 Model relationship tests — update existing tests or write new ones: Payment `paymentMethodConfig` relationship returns correct model (or null) with eager loading; PagoMovilDetail `sender_id` persists correctly; BankTransferDetail all 6 sender fields persist, `payment_date` cast to Carbon. (S)
- [x] 5.3 Update existing gateway tests (`PagoMovilGatewayTest.php`, `BankTransferGatewayTest.php`) — add assertions for `payment_method_config_id` on created Payment and sender field assertions on created Detail. For BankTransferGateway, verify all 6 sender fields are persisted. (M)
- [x] 5.4 Controller validation tests — update `tests/Feature/Tenant/PaymentControllerTest.php`: add test for bank_transfer requiring `sender_bank`/`sender_name`/`sender_id`/`payment_date`, test for bank_transfer with all fields creates detail with sender fields, test for pago_movil requiring `sender_id` (new field). (M)

## Phase 6: Cleanup + Final Verification

- [x] 6.1 Run `vendor/bin/pint --format agent` — fix any PHP style issues introduced. Run full test suite `php artisan test --compact` — all tests pass, no regressions. (S)
- [x] 6.2 Integration verification — create order, submit pago_movil payment with `payment_method_config_id` and `sender_id`, submit bank_transfer payment with all 6 sender fields. Verify all columns persisted in database. Verify rollback removes all columns cleanly. (S)
