# Delta: Tenant User CRUD

**Change ID**: `1.5G.0-tenant-roles`
**Delta**: `specs/tenant-user-crud/spec.md`
**Type**: MODIFIED

## Purpose

Add role display to user pages and role auto-assignment on user creation. This delta modifies the existing `tenant-user-crud` spec to integrate with the new `tenant-role-management` capability.

## MODIFIED Requirements

### Requirement: Create User

The system SHALL allow any authenticated tenant user to create a new user within the tenant. When the tenant has zero users, the new user SHALL be assigned the `owner` role automatically. Subsequent users SHALL NOT receive a default role.

(Previously: No role assignment — all users created with no role)

#### Scenario: Successful user creation with owner auto-assignment

- GIVEN an authenticated user in a tenant with zero users
- WHEN the user submits the create form with name, email, and password
- THEN a new user is created in the current tenant
- AND the new user is assigned the `owner` role
- AND the user is redirected to the new user's detail page

#### Scenario: Successful user creation without default role

- GIVEN an authenticated user in a tenant with at least one existing user
- WHEN the user submits the create form with name, email, and password
- THEN a new user is created in the current tenant
- AND the new user has no roles
- AND the user is redirected to the new user's detail page

#### Scenario: Duplicate email rejected

- GIVEN an authenticated user in a tenant
- WHEN the user submits the create form with an email already in use
- THEN validation errors are returned and no user is created

#### Scenario: Missing required fields rejected

- GIVEN an authenticated user in a tenant
- WHEN the user submits the create form with missing name, email, or password
- THEN validation errors are returned and no user is created

### Requirement: Show User Detail

The system SHALL display detail information for a specific user within the tenant, including the user's assigned roles.

(Previously: Displayed name and email only)

#### Scenario: Authenticated user views user detail with roles

- GIVEN an authenticated user in a tenant
- WHEN the user navigates to a specific user's detail page
- THEN the user's name and email are displayed
- AND the user's assigned roles are displayed as badges or a list

#### Scenario: User with no roles shows empty role state

- GIVEN an authenticated user in a tenant
- WHEN the user navigates to a user with no assigned roles
- THEN the role section shows an empty state (e.g., "No roles assigned")

#### Scenario: Non-existent user returns 404

- GIVEN an authenticated user in a tenant
- WHEN the user navigates to a user ID that does not exist in the current tenant
- THEN a 404 response is returned

## ADDED Requirements

### Requirement: User index displays role column

The system SHALL display a role column in the user index table showing each user's assigned roles.

#### Scenario: Role column visible on user index

- GIVEN an authenticated user in a tenant
- WHEN the user views the users index page
- THEN each user row displays their assigned roles
- AND users with no roles show an empty state in the role column

#### Scenario: Role column is filterable or searchable

- GIVEN an authenticated user on the users index page
- WHEN the user searches or filters by role
- THEN only users with matching roles are displayed

## Removed Requirements

### Requirement: First tenant user is automatically assigned the tenant-admin role

(Reason: Replaced by the owner auto-assignment logic in `tenant-role-management` and the modified Create User requirement above. The first user now gets `owner`, not `tenant-admin`.)
(Migration: Tests referencing `tenant-admin` auto-assignment for the first user must be updated to expect `owner`.)
