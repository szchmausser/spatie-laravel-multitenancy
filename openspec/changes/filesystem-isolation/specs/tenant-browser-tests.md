# Spec: Tenant CRUD Browser Tests

**Change ID**: `filesystem-isolation`
**Delta**: `specs/tenant-browser-tests.md`

## Purpose

End-to-end coverage of the landlord tenant CRUD flow through a real browser (Playwright via `pestphp/pest-browser`) so the Inertia + React + Laravel stack is exercised as a user would experience it. Browser tests MUST obey the `browser-testing` skill: no direct HTTP calls, state created via factories BEFORE `browse()`, `actingAs()` for auth preconditions, `data-testid` selectors, `->waitFor()` instead of `sleep()`, and meaningful UI assertions (never `assertPathIs` as the sole check).

## Prerequisites

- `pestphp/pest-browser` installed (`composer require pestphp/pest-browser --dev` + `npx playwright install`)
- Test database exists (see `specs/test-database-setup.md`)
- Test server reachable (`php artisan serve --env=testing` or Pest Browser's built-in server)

## Requirements

### R1: Tenant list page loads with rows

When a Landlord admin visits `/admin/tenants`, the browser MUST see every tenant row from the database, the page MUST have no JavaScript errors, and the page MUST settle on the expected Inertia route.

### R2: Admin can create a tenant via the form

When a Landlord admin fills the create form (name, domain, database) and submits, the new tenant name MUST appear in the list after redirect and the page MUST have no JavaScript errors.

### R3: Empty form submission shows validation errors

When a Landlord admin submits the create form empty, validation error messages MUST be visible and the browser MUST stay on the create page (no redirect).

### R4: Admin can view tenant details

When a Landlord admin opens a tenant detail page, the tenant's name and domain MUST be visible.

### R5: Admin can edit a tenant

When a Landlord admin updates a tenant's name and submits, the updated name MUST appear in the list.

### R6: Admin can delete a tenant

When a Landlord admin triggers the delete action, the tenant's name MUST disappear from the list.

### R7: Unauthenticated guest is redirected to login

When a guest visits `/admin/tenants`, the browser MUST end up on the login page.

## Scenarios

### Scenario: Admin sees the populated tenant list

- GIVEN a `Landlord` admin is authenticated
- AND three tenants exist in the database (created via factory BEFORE `browse()`)
- WHEN the browser visits the tenant index page
- THEN all three tenant names are visible
- AND no JavaScript errors occur

### Scenario: Admin creates a tenant end-to-end

- GIVEN a `Landlord` admin is authenticated
- AND no tenant with name `'Acme Corp'` exists
- WHEN the browser visits the create form
- AND fills name with `'Acme Corp'`, domain with `'acme.test'`, database with `'acme_db'`
- AND submits the form
- THEN the browser waits for navigation back to the index
- AND the name `'Acme Corp'` is visible in the list
- AND no JavaScript errors occur

### Scenario: Empty form surfaces validation errors

- GIVEN a `Landlord` admin is authenticated
- WHEN the browser visits the create form
- AND submits without filling any field
- THEN validation error messages appear near the required fields
- AND the URL is still the create form (no redirect)
- AND no JavaScript errors occur

### Scenario: Admin opens a tenant detail page

- GIVEN a `Landlord` admin is authenticated
- AND a tenant named `'Beta Inc'` exists (created BEFORE `browse()`)
- WHEN the browser navigates to that tenant's detail page
- THEN the name `'Beta Inc'` and its domain are visible
- AND no JavaScript errors occur

### Scenario: Admin edits a tenant and sees the update

- GIVEN a `Landlord` admin is authenticated
- AND a tenant named `'Gamma LLC'` exists
- WHEN the browser navigates to that tenant's edit page
- AND changes the name field to `'Gamma International'`
- AND submits the form
- THEN the browser waits for navigation back to the index
- AND the name `'Gamma International'` is visible
- AND the old name `'Gamma LLC'` is no longer in the list

### Scenario: Admin deletes a tenant and it disappears

- GIVEN a `Landlord` admin is authenticated
- AND a tenant named `'Delta Co'` exists
- WHEN the browser visits the tenant index
- AND triggers the delete action for `'Delta Co'`
- AND confirms the deletion if a dialog appears
- THEN the name `'Delta Co'` is no longer in the list
- AND no JavaScript errors occur

### Scenario: Guest is redirected to login

- GIVEN no user is authenticated
- WHEN the browser visits `/admin/tenants`
- THEN the browser ends up on the login page
- AND no JavaScript errors occur
