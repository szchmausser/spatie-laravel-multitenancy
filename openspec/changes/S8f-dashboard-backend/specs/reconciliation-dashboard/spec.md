# Reconciliation Dashboard Specification

## Purpose

Landlord admin endpoints exposing reconciliation pipeline health KPIs (8 metrics), a combined timeline, and a shadow-mode toggle. Consumes PaymentMatch, Payment, PaymentNotification, SystemAlert, and SystemConfig models on the landlord connection.

## Requirements

### Requirement: Index returns all 8 KPIs

The system MUST return 8 KPI metrics plus a timeline as Inertia props on `GET /admin/reconciliation` via `ReconciliationDashboardController@index`. Each KPI MUST be computed in a dedicated private method — no service layer.

#### Scenario: Match rate with status breakdown

- GIVEN PaymentMatch records with various `match_status` values
- WHEN `index()` resolves
- THEN `match_rate` MUST contain `percentage` (float), `total` (int), `matched` (int), `by_status` with `{matched, unmatched, pending, duplicate}` counts
- AND `percentage` MUST be `0` when `total` is `0`

#### Scenario: Auto-verified today count

- GIVEN Payments with `verified_at` = today and `verified_by` IS NULL
- WHEN `index()` resolves
- THEN `autoverified_today` MUST be the integer count

#### Scenario: Active alerts scoped to authenticated admin

- GIVEN the admin has unread SystemAlert notifications
- WHEN `index()` resolves
- THEN `active_alerts` MUST count notifications where `type='App\Notifications\SystemAlert'`, `read_at IS NULL`, `notifiable_id=auth()->id()`, `notifiable_type='App\Models\User'`

#### Scenario: Failed notifications count

- GIVEN PaymentNotification records in `failed` state
- WHEN `index()` resolves
- THEN `failed_notifications` MUST equal `PaymentNotification::failed()->count()`

#### Scenario: Shadow mode status from config

- GIVEN `SystemConfig` has key `reconciliation.shadow_mode_enabled`
- WHEN `index()` resolves
- THEN `shadow_mode_enabled` MUST return the stored boolean, defaulting to `false`

#### Scenario: Orphaned payments past 30-minute threshold

- GIVEN Payments with `status=pending`, `created_at < now()-30min`, and no related PaymentMatch
- WHEN `index()` resolves
- THEN `orphaned_payments` MUST be a collection with relevant frontend data (id, amount, created_at, reference)

#### Scenario: Orphaned unmatched notifications past threshold

- GIVEN PaymentMatches with `match_status=unmatched`, `created_at < now()-30min`
- WHEN `index()` resolves
- THEN `orphaned_notifications` MUST be a collection with relevant frontend data (id, amount, created_at)

#### Scenario: Timeline merges 3 sources, sorted desc, limited to 20

- GIVEN recent matches, verifications, and alerts exist
- WHEN `index()` resolves
- THEN `timeline` MUST merge 3 separate queries (no UNION SQL), each mapped to `{type, description, timestamp, url}`
- AND results MUST be sorted by `timestamp` descending, limited to 20 items

### Requirement: toggleShadowMode persists boolean

The system MUST accept `PATCH /admin/reconciliation/shadow-mode`, validate `{enabled: bool}`, persist via `SystemConfig::set()`, and redirect back with flash.

#### Scenario: Persists value and redirects

- GIVEN a valid payload `{"enabled": true}`
- WHEN `toggleShadowMode` executes
- THEN `SystemConfig::set('reconciliation.shadow_mode_enabled', true)` MUST be called
- AND the response MUST redirect back with a success flash

#### Scenario: Rejects non-boolean value with 422

- GIVEN a payload where `enabled` is not boolean (e.g., `"yes"`, `123`, `null`)
- WHEN validation runs
- THEN the server MUST return 422 with a validation error

### Requirement: Dashboard card in admin panel

The system MUST add a "Dashboard de Conciliación" card in `admin-panel.tsx` linking to `/admin/reconciliation`.

#### Scenario: Card renders with correct route

- GIVEN the admin panel renders
- WHEN the component mounts
- THEN it MUST display the card with title "Dashboard de Conciliación" linking to route `reconciliation.index`

### Requirement: Feature test coverage

Tests MUST cover both endpoints with structured assertions.

#### Scenario: Index returns all KPI keys with expected types

- GIVEN seeded data for all KPI sources and an authenticated admin
- WHEN `GET /admin/reconciliation` is called
- THEN the response MUST contain all 8 KPI keys plus `timeline`

#### Scenario: Toggle persists value in database

- GIVEN a boolean payload
- WHEN the PATCH request succeeds
- THEN `SystemConfig::get('reconciliation.shadow_mode_enabled')` MUST reflect the new value

#### Scenario: Toggle rejects non-boolean

- GIVEN a string `enabled` value
- WHEN the PATCH request is sent
- THEN the response MUST be 422

#### Scenario: Alerts are scoped per admin

- GIVEN two admins with different unread alert counts
- WHEN each calls the index
- THEN each MUST see only their own `active_alerts` count
