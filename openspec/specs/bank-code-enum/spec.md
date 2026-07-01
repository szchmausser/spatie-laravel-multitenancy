# BankCode Enum Specification

## Purpose

Centralize all bank-specific metadata (code, name, date formats, phone canonicalization flag, Android package) in a single PHP enum. Eliminates scattered string constants and per-bank conditional logic across services.

## Requirements

### Requirement: Enum Cases

The `BankCode` enum MUST define a `string` backed case for each supported bank.

#### Scenario: BDV case

- GIVEN the `BankCode` enum
- THEN `BankCode::Bdv` exists with value `"bdv"`

#### Scenario: BNC case

- GIVEN the `BankCode` enum
- THEN `BankCode::Bnc` exists with value `"bnc"`

### Requirement: Phone Canonicalization Flag

The enum MUST provide a method `appliesCanonicalPhone(): bool` indicating whether phone numbers from this bank require canonicalization (first4+last4 digits).

#### Scenario: BNC applies canonical phone

- GIVEN `BankCode::Bnc`
- WHEN `appliesCanonicalPhone()` is called
- THEN it returns `true`

#### Scenario: BDV does not apply canonical phone

- GIVEN `BankCode::Bdv`
- WHEN `appliesCanonicalPhone()` is called
- THEN it returns `false`

### Requirement: Date Format Strings

The enum MUST provide a method `dateFormats(): array` returning format strings for `parseDateMultiFormat()`, ordered by priority (most specific first).

#### Scenario: BDV date formats

- GIVEN `BankCode::Bdv`
- WHEN `dateFormats()` is called
- THEN it returns `['n/j/Y G:i']`

#### Scenario: BNC date formats

- GIVEN `BankCode::Bnc`
- WHEN `dateFormats()` is called
- THEN it returns `['d/m/y H:i', 'd/m/Y H:i']`

### Requirement: Bank Code Accessor

The enum MUST provide a method `code(): string` returning the canonical lowercase bank code.

#### Scenario: Code returns enum value

- GIVEN `BankCode::Bdv`
- WHEN `code()` is called
- THEN it returns `"bdv"`

### Requirement: All Values Iterable

The enum MUST support `BankCode::cases()` to iterate all supported banks. New banks are added by appending a new case — no other code changes required.

#### Scenario: Cases returns all banks

- GIVEN the `BankCode` enum
- WHEN `cases()` is called
- THEN it returns an array containing both `Bdv` and `Bnc` cases
