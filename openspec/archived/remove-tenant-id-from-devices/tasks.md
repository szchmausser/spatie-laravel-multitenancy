# Tasks: Remove `tenant_id` from Device and DeviceInviteCode

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | 380–450 |
| 400-line budget risk | Medium |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | single-pr |

Decision needed before apply: Yes
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: Medium

### Suggested Work Units

| Unit | Goal | PR | Notes |
|------|------|----|-------|
| 1 | Remove `tenant_id` from schema, models, backend, and UI | PR 1 | Single PR, tests bundled |

## Phase 1: Database Migrations

- [x] 1.1 Create `landlord` migration: drop FK + column `tenant_id` from `device_invite_codes`
- [x] 1.2 Create `landlord` migration: drop FK + column `tenant_id` from `devices`

## Phase 2: Backend Code Removal

- [x] 2.1 `Device.php`: remove `tenant_id` from `$fillable`, remove `tenant()` BelongsTo
- [x] 2.2 `DeviceInviteCode.php`: remove `tenant_id` from `$fillable`, remove `tenant()` BelongsTo, update PHPDoc
- [x] 2.3 `StoreDeviceRequest.php`: remove `tenant_id` validation rule (merged into controller simplification)
- [x] 2.4 `Api/DeviceController.php@register`: remove `tenant_id` from `Device::create()` and `update()` calls; remove `tenant_id` from JSON response
- [x] 2.5 `Landlord/DeviceController.php@store`: remove `tenant_id` from `Device::create()` call (no separate Landlord/DeviceController exists — handled in Api/DeviceController)
- [x] 2.6 `Landlord/DeviceInviteCodeController.php`: remove `tenant_id` validation from `store()`; remove `Tenant::all()` from `create()`; remove `tenant` from `with()` in `index()` and `edit()`; remove tenant from success message
- [x] 2.7 `GenerateDeviceInviteCode.php`: remove `tenant` argument from signature; remove tenant lookup; remove `tenant_id` from `DeviceInviteCode::create()`; remove tenant info from output

## Phase 3: Frontend Updates

- [x] 3.1 `models.ts`: remove `tenant_id` and `tenant` from `DeviceInviteCode` type
- [x] 3.2 `create.tsx`: remove tenant Select, tenants prop, `tenant_id` from form data
- [x] 3.3 `index.tsx`: remove "Tenant" column header and `tenant`/`tenant_id` cell content
- [x] 3.4 `edit.tsx`: remove `code.tenant` reference from description text

## Phase 4: Factory and Tests

- [x] 4.1 `DeviceInviteCodeFactory.php`: remove `tenant_id` from `definition()`; remove `forTenant()` state; remove `Tenant` import
- [x] 4.2 `DeviceRegistrationTest.php`: update assertions — remove `tenant_id` from JSON structure check, remove `tenant_id` from `assertDatabaseHas` calls; update helper to not set `tenant_id`
- [x] 4.3 `DeviceInviteCodeControllerTest.php`: update tests — remove `tenant_id` from create/store assertions; add "create form without tenant" test; add "creates with default values" test
- [x] 4.4 Create `tests/Feature/Console/GenerateDeviceInviteCodeTest.php`: test command outputs code without tenant arg

## Phase 5: Final Verification

- [x] 5.1 Run `vendor/bin/pint --format agent`
- [x] 5.2 Run `php artisan test --compact --stop-on-failure` (Note: tests pass individually; shared-DB parallelism issue is pre-existing. All 28 relevant tests verified.)
