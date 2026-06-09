# Tenant User CRUD Specification

## Purpose

Tenant-scoped user management allowing any authenticated tenant user to list, create, read, update, and delete users within their tenant. All operations are scoped to the authenticated user's tenant. This is Phase 1 — role/permission management and authorization gates are excluded.

## Requirements

### Requirement: User List (Index)

The system MUST display a paginated, searchable list of users scoped to the current tenant.

#### Scenario: Authenticated user views user list

- GIVEN an authenticated user in a tenant
- WHEN the user navigates to the users index page
- THEN a paginated table of all users in the tenant is displayed
- AND the table shows name and email columns for each user

#### Scenario: Search filters users

- GIVEN an authenticated user on the users index page
- WHEN the user enters a search term matching user name or email
- THEN only users whose name or email contains the search term are displayed

#### Scenario: Pagination

- GIVEN a tenant with more users than fit one page
- WHEN an authenticated user views the users index
- THEN users are paginated with navigation controls

### Requirement: Create User

The system MUST allow any authenticated tenant user to create a new user within the tenant.

#### Scenario: Successful user creation

- GIVEN an authenticated user in a tenant
- WHEN the user submits the create form with name, email, and password
- THEN a new user is created in the current tenant
- AND the user receives no role by default
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

The system MUST display detail information for a specific user within the tenant.

#### Scenario: Authenticated user views user detail

- GIVEN an authenticated user in a tenant
- WHEN the user navigates to a specific user's detail page
- THEN the user's name and email are displayed

#### Scenario: Non-existent user returns 404

- GIVEN an authenticated user in a tenant
- WHEN the user navigates to a user ID that does not exist in the current tenant
- THEN a 404 response is returned

### Requirement: Edit User

The system MUST allow any authenticated tenant user to update a user's name and email. Password updates are optional.

#### Scenario: Successful user edit

- GIVEN an authenticated user in a tenant
- WHEN the user submits the edit form with a new name or email
- THEN the user record is updated with the new values
- AND the user is redirected to the user's detail page

#### Scenario: Password update when provided

- GIVEN an authenticated user in a tenant
- WHEN the user submits the edit form with a new password value
- THEN the user's password is updated to the new value

#### Scenario: Blank password leaves password unchanged

- GIVEN an authenticated user in a tenant
- WHEN the user submits the edit form with an empty password field
- THEN the user's password remains unchanged
- AND the user is redirected to the user's detail page

#### Scenario: Duplicate email rejected on edit

- GIVEN an authenticated user in a tenant
- WHEN the user submits the edit form with an email already used by another user
- THEN validation errors are returned and no changes are persisted

#### Scenario: Non-existent user returns 404

- GIVEN an authenticated user in a tenant
- WHEN the user submits an edit for a user ID that does not exist
- THEN a 404 response is returned

### Requirement: Delete User

The system MUST allow any authenticated tenant user to delete a user within the tenant.

#### Scenario: Successful user deletion

- GIVEN an authenticated user in a tenant
- WHEN the user confirms deletion of another user
- THEN the user record is removed from the tenant

#### Scenario: Self-deletion prevented

- GIVEN an authenticated user in a tenant
- WHEN the user attempts to delete themselves
- THEN the operation is rejected with an error message
- AND no user record is removed

#### Scenario: Non-existent user returns 404

- GIVEN an authenticated user in a tenant
- WHEN the user attempts to delete a user ID that does not exist
- THEN a 404 response is returned

### Requirement: Unauthenticated Access

- GIVEN no authenticated user
- WHEN a request is made to any users endpoint
- THEN the user is redirected to the login page

### Requirement: Tenant Isolation

The system MUST scope all user operations to the authenticated user's tenant. Cross-tenant access MUST NOT be possible.

#### Scenario: Cannot access users from another tenant

- GIVEN an authenticated user in tenant A
- WHEN the user requests a user ID that belongs to tenant B
- THEN a 404 response is returned

#### Scenario: User list only shows current tenant users

- GIVEN an authenticated user in tenant A
- WHEN the user views the users index
- THEN only users belonging to tenant A are displayed

### Requirement: Validation Rules

The system MUST enforce the following validation rules for user input.

#### Scenario: Name required and min length

- GIVEN a user creation or edit request
- WHEN the name field is empty or less than 2 characters
- THEN a validation error for name is returned

#### Scenario: Email required and valid format

- GIVEN a user creation or edit request
- WHEN the email field is empty or not a valid email
- THEN a validation error for email is returned

#### Scenario: Email uniqueness within tenant

- GIVEN a user creation or edit request
- WHEN the email is already used by another user in the same tenant
- THEN a validation error for email uniqueness is returned

#### Scenario: Password required on create

- GIVEN a user creation request
- WHEN the password field is empty
- THEN a validation error for password is returned

#### Scenario: Password min length on create

- GIVEN a user creation request
- WHEN the password is shorter than 8 characters
- THEN a validation error for password length is returned

## Out of Scope

This specification does NOT cover:

- Role or permission management UI (Phase 2)
- Role assignment/unassignment for users (Phase 2)
- Authorization gates (tenant-admin check) (Phase 2)
- Landlord-level user management (Phase 2)
- User invitation flow (future slice)
- Bulk user operations (future slice)
- Password reset or forgot-password flows
- User profile self-service (handled by Fortify)

## Known Limitations (Phase 1)

- **No authorization gates**: Any authenticated tenant user can edit/delete any other user. Phase 2 will add role-based access control.
- **Password editing unrestricted**: Any user can change any other user's password. Phase 2 will restrict password changes to own profile only (or admin).
- **No self-deletion guard from UI**: The backend prevents self-deletion, but the UI does not hide the delete button for the current user.
