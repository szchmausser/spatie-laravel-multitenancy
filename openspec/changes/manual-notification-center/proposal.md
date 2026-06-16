# Proposal: Manual Notification Center

## Intent

The landlord has no GUI to send manual notifications to tenant users — only a CLI command (`notification:send`). This forces terminal usage for a common admin task: broadcasting announcements, maintenance notices, or policy updates to specific tenants. A dedicated admin page makes this accessible, auditable, and discoverable.

## Scope

### In Scope
- Compose page at `/admin/notifications` with tenant selection, role filtering, title/message fields
- "Send to all tenants" quick-select toggle
- Dry-run preview showing recipient count per tenant before confirm
- History table of sent manual notifications (date, title snippet, tenants, recipient count)
- New `manual_notification_logs` table to track sends (landlord DB)

### Out of Scope
- Automatic notification display (expiry, payment verified — those use existing system)
- Notification detail view or read/unread tracking for landlord
- Editing or deleting sent notifications
- Real-time notification delivery (keeps existing `Notification::send` sync pattern)
- Tenant-side notification changes

## Capabilities

### New Capabilities
- `manual-notification-center`: Landlord admin UI for composing, previewing, sending, and reviewing manual notifications to tenant users

### Modified Capabilities
None — this is additive only. Existing `ManualNotification` class and `notification:send` command remain unchanged.

## Approach

1. **Migration**: Create `manual_notification_logs` table in landlord DB (id, title, message, roles, tenant_ids json, recipient_count, sent_by, timestamps)
2. **Controller**: `Landlord\NotificationController` with `index` (history), `create` (compose form), `preview` (dry-run via POST), `store` (actual send)
3. **Routes**: Standard resource routes under existing landlord middleware group
4. **Frontend**: Single Inertia page at `resources/js/pages/landlord/notifications/compose.tsx` — React component with multi-step flow (select tenants → configure roles/message → preview → confirm)
5. **Reuse**: `SendManualNotification::getUsersByRoles()` extracted to a shared service class or called directly; same `Tenant::makeCurrent()` pattern per tenant
6. **History**: Simple table view at same page route, toggle between compose/history views

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `database/migrations/landlord/` | New | `manual_notification_logs` migration |
| `app/Http/Controllers/Landlord/NotificationController.php` | New | Compose, preview, send, history |
| `routes/landlord.php` | Modified | Add notification routes |
| `resources/js/pages/landlord/notifications/` | New | Compose + history page |
| `app/Console/Commands/SendManualNotification.php` | None | Stays as-is for CLI |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Large tenant list → slow preview | Low | Tenant count is manageable (<100); query is simple |
| Tenant DB unreachable during send | Med | Wrap per-tenant send in try/catch, log failures, continue |
| Roles table missing in tenant DB | Low | Existing `getUsersByRoles` already catches `\Throwable` |

## Rollback Plan

1. Drop `manual_notification_logs` table
2. Remove routes from `routes/landlord.php`
3. Delete controller + frontend files
4. No data dependencies — purely additive feature

## Dependencies

- Existing `ManualNotification` class (no changes)
- Existing Spatie Permission roles (`owner`, `tenant-admin`) in tenant DBs
- Existing `EnsureUserIsAdmin` middleware (already in place)

## Success Criteria

- [ ] Landlord can compose a notification, select tenants, filter by roles, preview recipients, and send
- [ ] Sent notifications appear in history table with correct metadata
- [ ] Tenant users receive notifications via database + mail channels
- [ ] CLI `notification:send` continues working independently
