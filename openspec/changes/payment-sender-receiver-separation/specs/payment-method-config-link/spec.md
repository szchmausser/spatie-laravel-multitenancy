# Payment Method Config Link Specification

## Purpose

Establish traceability between payments and the specific receiving account configuration used for each payment. This enables historical auditing of which payment method config was active when a payment was made.

## Requirements

### Requirement: Payment Method Config FK

The `payments` table MUST contain a nullable foreign key `payment_method_config_id` referencing `payment_method_configs.id`.

#### Scenario: Payment created with config reference

- GIVEN a tenant creates a payment for an order
- WHEN the payment is recorded with a `payment_method_config_id`
- THEN the payment MUST store the referenced config ID
- AND the payment MUST be queryable via the `paymentMethodConfig()` relationship

#### Scenario: Payment created without config reference (backward compatibility)

- GIVEN a tenant creates a payment for an order
- WHEN the payment is recorded without a `payment_method_config_id`
- THEN the payment MUST have `payment_method_config_id = NULL`
- AND the payment MUST still be created successfully

#### Scenario: Config deletion does not cascade

- GIVEN a payment references a config via `payment_method_config_id`
- WHEN the referenced config is deleted
- THEN the payment's `payment_method_config_id` MUST become NULL (SET NULL on delete)
- AND the payment MUST NOT be deleted

### Requirement: Payment Model Relationship

The Payment model MUST define a `paymentMethodConfig()` BelongsTo relationship to PaymentMethodConfig.

#### Scenario: Eager loading config relationship

- GIVEN a payment with `payment_method_config_id = 5`
- WHEN the payment is loaded with `->load('paymentMethodConfig')`
- THEN `$payment->paymentMethodConfig` MUST return the PaymentMethodConfig with ID 5

#### Scenario: Null config relationship

- GIVEN a payment with `payment_method_config_id = NULL`
- WHEN the payment is loaded
- THEN `$payment->paymentMethodConfig` MUST return NULL

### Requirement: Payment Model Fillable

The Payment model's `$fillable` array MUST include `payment_method_config_id`.

#### Scenario: Mass assignment of config ID

- GIVEN a Payment model instance
- WHEN `Payment::create(['payment_method_config_id' => 5, ...])` is called
- THEN the `payment_method_config_id` field MUST be persisted to the database
