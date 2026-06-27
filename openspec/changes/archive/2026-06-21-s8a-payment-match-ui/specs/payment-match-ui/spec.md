# Delta for payment-match-ui

## Context

This change is a pure UI extension with **no spec-level behavior changes**. No requirements are added, modified, removed, or renamed in any existing spec. All data already exists in the database (S1–S7 backend). This spec documents only the new UI rendering behavior for the existing order detail view.

## ADDED Requirements

### Requirement: UI Rendering of Payment Verification & Matching Data

The order detail view (`payment-details-card.tsx`) MUST render the following fields when present in the Payment object:

- **verified_by**: SHALL display the verifier's name, or "Automático" when `verified_by` is null (auto-verification).
- **verified_at**: SHALL display the verification timestamp.
- **cancellation_type**: SHALL display as a color-coded badge per cancellation reason, with secondary reason text appended.
- **payment_match**: SHALL display `match_status`, `matched_at`, and parsed data (`parsed_reference`, `parsed_amount_cents`) when present. SHALL NOT render when null.

#### Scenario: Verified payment shows verifier info

- GIVEN a payment with `verified_by` set to a User
- WHEN the order detail page renders
- THEN the payment card SHALL display the verifier's name and `verified_at` timestamp

#### Scenario: Auto-verified payment shows "Automático"

- GIVEN a payment with `verified_by` null and `verified_at` set
- WHEN the order detail page renders
- THEN the payment card SHALL display "Automático" instead of a verifier name

#### Scenario: Cancelled payment shows badge

- GIVEN a payment with `cancellation_type` set
- WHEN the order detail page renders
- THEN the payment card SHALL display a color-coded badge per type with secondary reason text

#### Scenario: Matched payment shows match details

- GIVEN a payment with `paymentMatch` data
- WHEN the order detail page renders
- THEN the payment card SHALL display `match_status`, `matched_at`, and parsed fields

#### Scenario: Unmatched payment hides match section

- GIVEN a payment with `paymentMatch` null
- WHEN the order detail page renders
- THEN the payment card SHALL NOT render the payment match section
