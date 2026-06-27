# SystemConfig UI Specification

## Purpose

Provide visual management of dynamic SystemConfig records for landlord admins — grouped listing, type-aware editing, and cache-invalidated persistence. Eliminates reliance on tinker/seeders.

## Requirements

### Requirement: List SystemConfigs

The system MUST expose `GET /admin/system-configs` returning all configs grouped by `group`. Each item SHALL display: group, key (read-only), value, type, description. The endpoint MUST be protected by the `auth`, `verified`, and `EnsureUserIsAdmin` middleware.

#### Scenario: Admin views grouped configs

- GIVEN an authenticated admin user
- WHEN they navigate to `/admin/system-configs`
- THEN they see configs grouped by `group` (payment, reconciliation, device)
- AND each row displays key, value, type badge, and description

#### Scenario: Non-admin is redirected

- GIVEN an authenticated non-admin user
- WHEN they access `/admin/system-configs`
- THEN they receive a 403 or redirect to a non-admin page

### Requirement: Edit SystemConfig

The system MUST expose `PUT /admin/system-configs/{systemConfig}` accepting a `value` field. The controller MUST call `SystemConfig::set()` to persist and invalidate cache. On validation failure (invalid regex, missing named groups), the response MUST be 422 with a descriptive error message.

#### Scenario: Admin edits a text config

- GIVEN an admin viewing the config list
- WHEN they click edit on a text-type config, update the value, and submit
- THEN the modal closes, the value updates immediately via cache invalidation, and reloading shows the persisted value

#### Scenario: Admin enters invalid regex

- GIVEN an admin editing a config whose key starts with `regex_`
- WHEN they enter an invalid regex pattern and submit
- THEN the response is 422 with a descriptive error message shown in the modal

#### Scenario: Admin enters non-numeric in integer field

- GIVEN an admin editing an integer-type config
- WHEN they enter a non-numeric value
- THEN the response is 422 with a validation error

### Requirement: Type-Aware Input Rendering

The frontend MUST render different input controls based on the config's `type` field: `boolean` → Checkbox, `integer` → number input, `string` where key starts with `regex_` → textarea, `string` → text input, `json` → textarea (future-proof, not seeded yet).

#### Scenario: Boolean toggle persists correctly

- GIVEN a boolean config like `shadow_mode_enabled`
- WHEN the admin toggles it from `true` to `false` and submits
- THEN the value persists as `false` in the database, cache is invalidated, and the UI reflects the change

#### Scenario: Editing a regex config shows textarea

- GIVEN a config with key `regex_bdv`
- WHEN the admin opens the edit modal
- THEN the modal renders a textarea instead of a single-line input

### Requirement: Admin Panel Entry

The admin dashboard (`admin-panel.tsx`) MUST include a card entry with icon, title, description, and href to access the SystemConfig page.

#### Scenario: Admin accesses via dashboard card

- GIVEN the landlord admin dashboard
- WHEN the page renders
- THEN a "System Configuration" card is visible with an href to `/admin/system-configs`
- AND clicking it navigates to the SystemConfig page
