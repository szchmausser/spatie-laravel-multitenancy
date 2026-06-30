# Device Management Specification

## Purpose

Devices capture bank payment notifications from the landlord's bank accounts. The system issues invite codes for device registration. Tenant matching is NOT handled at the device level — it occurs downstream in reconciliation by amount + reference.

## Requirements

### Requirement: Register Device via Invite Code

A device MUST register using a valid invite code. The system MUST return an access token. The request MUST NOT require `tenant_id`; the response MUST NOT include `tenant_id`.

#### Scenario: Successful registration without tenant

- GIVEN a valid device invite code
- WHEN a POST request to `/api/devices/register` contains `invite_code` but no `tenant_id`
- THEN the response is 200 with `{ "token": "..." }`
- AND the response does NOT include `tenant_id`

#### Scenario: Registration with expired invite code

- GIVEN an expired or consumed invite code
- WHEN a registration request is sent with that code
- THEN the system returns 422 or 401
- AND no token is issued

### Requirement: Create Device Invite Code (Landlord UI)

An authenticated landlord user MUST create invite codes without associating them to a tenant. The create form MUST NOT display a tenant selector.

#### Scenario: Create invite code without tenant

- GIVEN an authenticated landlord user on the invite code creation page
- WHEN the user submits the form without selecting a tenant
- THEN the system creates the invite code
- AND the tenant selector is absent from the page

### Requirement: Model Schema Constraints

The `devices` and `device_invite_codes` tables MUST NOT have a `tenant_id` column or foreign key. The corresponding Eloquent models MUST NOT define a `tenant()` relationship or include `tenant_id` in `$fillable`.

#### Scenario: Tenant column removed from devices table

- GIVEN the `devices` table schema
- THEN it MUST NOT contain a `tenant_id` column or FK

#### Scenario: Tenant column removed from device_invite_codes table

- GIVEN the `device_invite_codes` table schema
- THEN it MUST NOT contain a `tenant_id` column or FK

### Requirement: Factory does not reference tenant

The `DeviceInviteCodeFactory` MUST NOT reference `tenant_id` in its definition or states.

#### Scenario: Factory creates code without tenant

- GIVEN the `DeviceInviteCodeFactory`
- WHEN a `device_invite_code` is created via the factory
- THEN the created record has no `tenant_id`

## REMOVED Requirements

### Requirement: Device scoped to tenant

(Previously: device registration required `tenant_id`; invite codes were scoped to a tenant.)

- **Reason**: modeling error — `tenant_id` was never functionally used; tenant matching happens downstream in reconciliation by amount + reference
- **Migration**: no migration needed for existing data; the column is dropped directly per user confirmation
