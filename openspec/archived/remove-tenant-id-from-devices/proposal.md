# Proposal: Remove `tenant_id` from Device and DeviceInviteCode

## Intent

`Device` and `DeviceInviteCode` models have a required `tenant_id` FK, but devices are landlord tools — they capture bank notifications from the landlord's accounts. Tenant matching happens downstream in the reconciliation flow (by amount + reference), not at the device level. The `tenant_id` is an incorrect modeling artifact that makes the system harder to reason about and unnecessary for the actual flow.

## Scope

### In Scope

- Drop `tenant_id` column from `device_invite_codes` and `devices` tables (migration + FK drops)
- Remove `tenant_id` from `DeviceInviteCode` model fillable + relationship
- Remove `tenant_id` from `Device` model fillable + relationship
- Remove `tenant_id` validation from `DeviceInviteCodeController@store`
- Stop passing `Tenant::all()` to invite-code create view
- Remove `tenant_id` assignment from `DeviceController@register` (API) and its JSON response
- Remove `tenant_id` assignment from `Landlord\DeviceController@store`
- Remove `tenant_id` validation from `StoreDeviceRequest`
- Remove `tenant` argument from `GenerateDeviceInviteCode` command
- Update `DeviceInviteCodeFactory` (definition and states)
- Update frontend: invite-codes create/edit/index pages, models.ts DeviceInviteCode type
- Update existing tests + add new tests for changed flows

### Out of Scope

- PaymentNotification, PaymentMatch, or ReconciliationOrchestrator — already correct
- Any new device/invite-code features
- Changing how payments are matched to tenants

## Capabilities

> Refactor/correction — no spec-level behavior introduced or removed.

### New Capabilities

None.

### Modified Capabilities

None.

## Approach

1. Create migration to drop FK constraints and columns (both tables, landlord connection)
2. Update models: remove `tenant_id` from `$fillable`, remove `tenant()` BelongsTo
3. Update `StoreDeviceRequest` validation
4. Update controllers: `DeviceInviteCodeController`, `Api\DeviceController`, `Landlord\DeviceController`
5. Update `GenerateDeviceInviteCode` console command
6. Update `DeviceInviteCodeFactory`
7. Update frontend: remove tenant selector from invite-codes/create.tsx, fix models.ts, fix index/edit display
8. Update tests + write new tests for registration, creation, and command flows
9. Run `php artisan test --compact` to verify

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `database/migrations/landlord/` | New | Migration to drop tenant_id columns |
| `app/Models/Device.php` | Modified | Remove fillable + relationship |
| `app/Models/DeviceInviteCode.php` | Modified | Remove fillable + relationship |
| `app/Http/Controllers/Api/DeviceController.php` | Modified | Remove tenant_id from register |
| `app/Http/Controllers/Landlord/DeviceController.php` | Modified | Remove tenant_id from store |
| `app/Http/Controllers/Landlord/DeviceInviteCodeController.php` | Modified | Remove tenant validation+view data |
| `app/Http/Requests/Landlord/StoreDeviceRequest.php` | Modified | Remove tenant_id validation |
| `app/Console/Commands/GenerateDeviceInviteCode.php` | Modified | Remove tenant argument |
| `database/factories/DeviceInviteCodeFactory.php` | Modified | Remove tenant_id |
| `resources/js/types/models.ts` | Modified | Remove tenant_id from DeviceInviteCode |
| `resources/js/pages/landlord/invite-codes/*.tsx` | Modified | Remove tenant selector/display |
| `tests/` | Modified+New | Update existing + add new tests |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Existing devices lose tenant_id | Low | Data never functionally used; reconciliation ignores it |
| Android client breaks on removed API field | Low | Confirmed with user — Android doesn't need tenant_id |

## Rollback Plan

1. Roll back migration: `php artisan migrate --path=database/migrations/landlord --database=landlord --pretend` to confirm, then rollback
2. Restore all modified PHP/TS files from git: `git checkout -- app/ resources/ database/factories/`
3. Run `php artisan test --compact` to verify restored state

## Dependencies

None.

## Success Criteria

- [ ] All existing tests pass (`php artisan test --compact --stop-on-failure`)
- [ ] New tests cover: device registration w/o tenant_id, invite code creation w/o tenant, console command w/o tenant arg
- [ ] `DeviceController@register` response no longer includes `tenant_id`
- [ ] Invite code create page has no tenant selector
- [ ] Device create page works without tenant_id in request
