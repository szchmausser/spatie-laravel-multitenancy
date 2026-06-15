# Proposal: Payment Sender-Receiver Separation

> **Source of truth**: `docs/phase-2a-payment-architecture.md`
> This is an executive summary. For full architecture, see the architecture document.

## Intent

The payment detail tables have inconsistent responsibilities. `pago_movil_details` stores both receiver snapshot and sender fields but is missing `sender_id`. `bank_transfer_details` stores only receiver snapshot — zero sender fields. There is also no FK from `payments` to `payment_method_configs`, so we cannot trace which specific receiving account was used. This change aligns both detail tables to the same snapshot+sender pattern, adds the config FK to payments, and completes the validation story.

## Scope

### In Scope
- **Migration**: Add `payment_method_config_id` (nullable FK) to `payments` table
- **Migration**: Add `sender_id` to `pago_movil_details`
- **Migration**: Add 6 sender fields to `bank_transfer_details` (`sender_bank`, `sender_name`, `sender_id`, `tenant_rif`, `payment_date`, `concept`)
- **Payment model**: Add `payment_method_config_id` to fillable + `paymentMethodConfig()` relationship
- **PagoMovilDetail model**: Add `sender_id` to fillable
- **BankTransferDetail model**: Add all 6 sender fields to fillable
- **PagoMovilGateway**: Save `payment_method_config_id` on Payment create
- **BankTransferGateway**: Save `payment_method_config_id` on Payment create + persist all sender fields to BankTransferDetail
- **Tenant\PaymentController**: Validate sender fields for `bank_transfer` (currently only validates for `pago_movil`)
- **Pest tests**: Cover new migration, model fields, gateway persistence, and validation

### Out of Scope
- UI changes (React frontend for bank_transfer sender fields)
- PaymentMethodConfig admin CRUD (already implemented)
- Landlord admin verification panel changes
- Any other payment methods (PayPal, Stripe, etc.)

## Capabilities

### New Capabilities
- `payment-method-config-link`: FK from payments to payment_method_configs — traceability of which receiving account was used per payment

### Modified Capabilities
- `payment-methods`: BankTransferDetail must store sender report fields (bank, name, ID, tenant_rif, date, concept) — currently only stores receiver snapshot

## Approach

Single migration file adds all 3 column changes in one operation. Models updated for fillable + relationships. Both gateways updated to pass `payment_method_config_id` when creating Payment. BankTransferGateway additionally passes all 6 sender fields to BankTransferDetail. Controller validates sender fields conditionally based on `payment_method` (pago_movil vs bank_transfer). Tests follow TDD: red-green-refactor per migration, model, gateway, and controller validation.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `database/migrations/` | New | Migration adding 3 columns across 2 tables |
| `app/Models/Payment.php` | Modified | Add `payment_method_config_id` to fillable + `paymentMethodConfig()` BelongsTo |
| `app/Models/PagoMovilDetail.php` | Modified | Add `sender_id` to fillable |
| `app/Models/BankTransferDetail.php` | Modified | Add 6 sender fields to fillable |
| `app/Services/Payment/PagoMovilGateway.php` | Modified | Persist `payment_method_config_id` on Payment create |
| `app/Services/Payment/BankTransferGateway.php` | Modified | Persist `payment_method_config_id` on Payment + all sender fields on Detail |
| `app/Http/Controllers/Tenant/PaymentController.php` | Modified | Validate sender fields for both pago_movil AND bank_transfer |
| `tests/` | Modified | New Pest tests for migration, models, gateways, controller validation |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Existing pago_movil rows have NULL `sender_id` after migration | High | Column is nullable — no data loss. Backfill not needed for MVP |
| Existing bank_transfer rows have NULL sender fields | High | All new columns are nullable — backward compatible |
| BankTransferGateway breaks if sender fields not provided | Medium | Controller validates required sender fields before gateway call |
| payment_method_config_id FK constraint fails on existing data | Low | Column is nullable — no FK violation on existing rows |

## Rollback Plan

1. Revert the migration: `php artisan migrate:rollback`
2. Revert model changes (fillable arrays, relationship methods)
3. Revert gateway changes (remove sender field persistence)
4. Revert controller validation changes
5. Revert test changes

The migration is purely additive (nullable columns), so rollback is safe with no data loss.

## Dependencies

- `payment_method_configs` table must exist (already implemented)
- `payments` table must exist (already implemented)
- `pago_movil_details` table must exist (already implemented)
- `bank_transfer_details` table must exist (already implemented)

## Success Criteria

- [ ] Migration runs cleanly: `php artisan migrate`
- [ ] Payment model saves `payment_method_config_id` and returns related PaymentMethodConfig
- [ ] PagoMovilDetail saves `sender_id` field
- [ ] BankTransferDetail saves all 6 sender fields
- [ ] PagoMovilGateway persists `payment_method_config_id` on Payment create
- [ ] BankTransferGateway persists `payment_method_config_id` + all sender fields
- [ ] Tenant\PaymentController validates sender fields for both payment methods
- [ ] All Pest tests pass: `php artisan test --compact`
