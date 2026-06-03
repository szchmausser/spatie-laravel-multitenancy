# Spec: TenantController Feature Tests

**Change ID**: `filesystem-isolation`
**Delta**: `specs/tenant-controller-tests.md`

## Purpose

Cover the landlord `TenantController` with feature tests for the seven RESTful actions (index, create, store, show, edit, update, destroy) plus the two authorization gates (unauthenticated redirect, non-admin 403). Each test MUST be self-sufficient — create its own `Landlord` admin via `Landlord::factory()->createQuietly()` and any tenant rows via `Tenant::factory()->createQuietly()`, so the `creating` callback never fires during HTTP testing.

## Requirements

### R1: Authenticated admin reaches the tenant index

A `Landlord` admin MUST be able to `GET /admin/tenants` and receive a `200` response rendering the Inertia page component `landlord/tenants/index`.

### R2: Authenticated admin reaches the create form

A `Landlord` admin MUST be able to `GET /admin/tenants/create` and receive a `200` response.

### R3: Authenticated admin can store a new tenant

A `Landlord` admin MUST be able to `POST /admin/tenants` with valid data, persist a new row, and receive a redirect to the index. The store test MUST bypass real provisioning (e.g. `Tenant::withoutEvents()`).

### R4: Authenticated admin can view tenant details

A `Landlord` admin MUST be able to `GET /admin/tenants/{tenant}` and receive a `200` response.

### R5: Authenticated admin can reach the edit form

A `Landlord` admin MUST be able to `GET /admin/tenants/{tenant}/edit` and receive a `200` response.

### R6: Authenticated admin can update a tenant

A `Landlord` admin MUST be able to `PUT /admin/tenants/{tenant}` with updated data, mutate the row, and receive a redirect to the index.

### R7: Authenticated admin can destroy a tenant

A `Landlord` admin MUST be able to `DELETE /admin/tenants/{tenant}` and have the row removed; the response MUST redirect to the index.

### R8: Unauthenticated users are redirected

A guest MUST be redirected to the login page when hitting any `/admin/tenants*` route.

### R9: Non-admin users receive 403

A regular `User` (not a `Landlord`) MUST receive a `403 Forbidden` when accessing any `/admin/tenants*` route.

## Scenarios

### Scenario: Admin can list tenants

- GIVEN a `Landlord` admin is authenticated
- WHEN the test issues `GET /admin/tenants`
- THEN the response is `200`
- AND the Inertia page component rendered is `landlord/tenants/index`

### Scenario: Admin can open the create form

- GIVEN a `Landlord` admin is authenticated
- WHEN the test issues `GET /admin/tenants/create`
- THEN the response is `200`

### Scenario: Admin can persist a new tenant

- GIVEN a `Landlord` admin is authenticated
- WHEN the test issues `POST /admin/tenants` with valid name/domain/database
- THEN a Tenant row exists in `tenants` with those values
- AND the response redirects to `/admin/tenants`

### Scenario: Store rejects invalid payloads with validation errors

- GIVEN a `Landlord` admin is authenticated
- WHEN the test issues `POST /admin/tenants` with an empty body
- THEN no row is inserted
- AND the response carries validation errors for the required fields
- AND the response does NOT redirect

### Scenario: Admin can view an existing tenant

- GIVEN a `Landlord` admin is authenticated and a tenant exists
- WHEN the test issues `GET /admin/tenants/{tenant}`
- THEN the response is `200`

### Scenario: Admin can open the edit form for a tenant

- GIVEN a `Landlord` admin is authenticated and a tenant exists
- WHEN the test issues `GET /admin/tenants/{tenant}/edit`
- THEN the response is `200`

### Scenario: Admin can update a tenant

- GIVEN a `Landlord` admin is authenticated and a tenant exists
- WHEN the test issues `PUT /admin/tenants/{tenant}` with a new `name`
- THEN the `tenants` row holds the new name
- AND the response redirects to the index

### Scenario: Admin can delete a tenant

- GIVEN a `Landlord` admin is authenticated and a tenant exists
- WHEN the test issues `DELETE /admin/tenants/{tenant}`
- THEN no row with that id exists in `tenants`
- AND the response redirects to the index

### Scenario: Guest is redirected to login

- GIVEN no user is authenticated
- WHEN the test issues `GET /admin/tenants`
- THEN the response is a redirect to the login page

### Scenario: Non-admin user receives 403

- GIVEN a regular `User` (not a `Landlord`) is authenticated
- WHEN the test issues `GET /admin/tenants`
- THEN the response is `403 Forbidden`
- AND the response is not a redirect
