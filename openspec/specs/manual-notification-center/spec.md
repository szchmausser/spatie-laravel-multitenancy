# manual-notification-center — Specification

## Purpose

Landlord admin UI for composing, previewing, sending, and reviewing manual notifications to tenant users. Provides a GUI alternative to the `notification:send` CLI command with audit trail.

## Requirements

### Requirement: Compose form with tenant selection and role filtering

The system SHALL provide a compose form at `/admin/notifications` with: visual tenant list (name + domain) with checkboxes, "Send to all tenants" toggle, role filter (owner / tenant-admin / both, default: both), optional title field, required message field.

#### Scenario: Landlord loads compose page with tenant list

- GIVEN an authenticated admin on the landlord domain
- WHEN they navigate to `/admin/notifications`
- THEN they see a list of all tenants with checkboxes, role filter set to "both", and empty title/message fields

#### Scenario: "Send to all" toggle selects every tenant

- GIVEN the compose page is loaded
- WHEN the landlord clicks "Send to all tenants"
- THEN all tenant checkboxes are checked

### Requirement: Dry-run preview shows recipient counts per tenant

The system SHALL provide a preview action (POST) that, for each selected tenant: switches to tenant DB via `$tenant->makeCurrent()`, queries users by selected roles, counts them, restores landlord connection. Returns aggregated results.

#### Scenario: Preview shows per-tenant recipient counts

- GIVEN a compose form with tenants A (5 users) and B (3 users) selected, roles = "both"
- WHEN the landlord clicks "Preview"
- THEN the response shows "8 users will receive this notification in 2 tenants" with a table listing each tenant's name, domain, and user count

#### Scenario: Preview with zero users in a tenant

- GIVEN a compose form with a tenant that has no matching users selected
- WHEN the landlord clicks "Preview"
- THEN that tenant shows 0 recipients and the total reflects the correct sum

### Requirement: Send dispatches notifications and logs the event

The system SHALL send `ManualNotification` to matching users per tenant using `Notification::send()`. After all tenants are processed, the system SHALL insert a row in `manual_notification_logs` recording: title, message, roles, tenant_ids (JSON), recipient_count, sent_by (admin user ID).

#### Scenario: Successful send logs to manual_notification_logs

- GIVEN a valid compose submission with 2 tenants selected
- WHEN the landlord confirms send
- THEN `ManualNotification` is dispatched to all matching users
- AND a row exists in `manual_notification_logs` with correct metadata

#### Scenario: Send continues when one tenant DB is unreachable

- GIVEN 3 tenants selected and tenant B's DB is unreachable
- WHEN the landlord confirms send
- THEN tenants A and C receive notifications
- AND tenant B is skipped without aborting the batch
- AND the log records the actual recipient count (A + C only)

### Requirement: Connection handling restores landlord after tenant operations

Every tenant-side operation (preview count, send) MUST restore the landlord connection before the next iteration or response. The system SHALL purge the tenant connection and reset `database.default` to `landlord` after each `makeCurrent()` + operation block.

#### Scenario: Preview does not corrupt landlord queries

- GIVEN a preview request targeting 3 tenants
- WHEN the preview completes
- THEN subsequent landlord DB queries (e.g., history table) return correct landlord data

### Requirement: History table shows sent manual notifications

The system SHALL provide a paginated history table at `/admin/notifications` (toggle view) with columns: date, title/message (truncated), target tenants (names), recipient count, sent by. Pagination at 20 entries. Only manual notifications (not automatic system ones) appear.

#### Scenario: History shows paginated manual notification logs

- GIVEN 25 manual notifications have been sent
- WHEN the landlord views history
- THEN 20 entries are shown on page 1 with pagination controls

#### Scenario: Automatic notifications do not appear in history

- GIVEN system-expiry notifications exist in the notifications table
- WHEN the landlord views history
- THEN only entries from `manual_notification_logs` are displayed

### Requirement: Validation prevents sending empty notifications

The system SHALL require the message field to be non-empty. The system SHALL require at least one tenant to be selected. Title is optional.

#### Scenario: Submitting empty message returns validation error

- GIVEN the compose form with an empty message field
- WHEN the landlord clicks "Send"
- THEN a validation error is shown and no notification is sent
