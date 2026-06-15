# Payment Methods Specification

## Purpose

Define the contracts for payment method detail tables, including snapshot receiver data and sender report fields. This covers PagoMovilDetail and BankTransferDetail subtypes.

## Requirements

### Requirement: PagoMovilDetail Sender ID

The `pago_movil_details` table MUST contain a `sender_id` column (varchar(20), NOT NULL) storing the Cédula/RIF of the payment sender.

#### Scenario: PagoMovil payment with sender ID

- GIVEN a tenant creates a pago_movil payment
- WHEN the payment is recorded with `sender_id = "V-12345678"`
- THEN the PagoMovilDetail MUST store `sender_id = "V-12345678"`

#### Scenario: PagoMovilDetail model fillable

- GIVEN the PagoMovilDetail model
- WHEN checking the `$fillable` array
- THEN `sender_id` MUST be included in the fillable array

### Requirement: BankTransferDetail Sender Fields

The `bank_transfer_details` table MUST contain the following sender report columns:
- `sender_bank` (varchar(100), NOT NULL)
- `sender_name` (varchar(100), NOT NULL)
- `sender_id` (varchar(20), NOT NULL)
- `tenant_rif` (varchar(20), nullable)
- `payment_date` (date, NOT NULL)
- `concept` (varchar(255), nullable)

#### Scenario: Bank transfer payment with sender fields

- GIVEN a tenant creates a bank_transfer payment
- WHEN the payment is recorded with sender fields
- THEN the BankTransferDetail MUST store all provided sender fields
- AND `tenant_rif` MAY be NULL if a third party pays

#### Scenario: BankTransferDetail model fillable

- GIVEN the BankTransferDetail model
- WHEN checking the `$fillable` array
- THEN all 6 sender fields MUST be included in the fillable array

### Requirement: Gateway Config ID Persistence

Both PagoMovilGateway and BankTransferGateway MUST persist `payment_method_config_id` when creating a Payment.

#### Scenario: PagoMovilGateway saves config ID

- GIVEN a tenant creates a pago_movil payment with `payment_method_config_id = 3`
- WHEN PagoMovilGateway::recordPayment() is called
- THEN the created Payment MUST have `payment_method_config_id = 3`

#### Scenario: BankTransferGateway saves config ID

- GIVEN a tenant creates a bank_transfer payment with `payment_method_config_id = 5`
- WHEN BankTransferGateway::recordPayment() is called
- THEN the created Payment MUST have `payment_method_config_id = 5`

#### Scenario: BankTransferGateway saves sender fields

- GIVEN a tenant creates a bank_transfer payment with sender fields
- WHEN BankTransferGateway::recordPayment() is called
- THEN the created BankTransferDetail MUST have all 6 sender fields persisted

### Requirement: Controller Validation for Bank Transfer

The Tenant\PaymentController MUST validate sender fields for bank_transfer payments in addition to pago_movil payments.

#### Scenario: Bank transfer validation rules

- GIVEN a tenant submits a bank_transfer payment
- WHEN the request contains `sender_bank`, `sender_name`, `sender_id`, `payment_date`
- THEN the controller MUST validate:
  - `sender_bank` is required, string, max:100
  - `sender_name` is required, string, max:100
  - `sender_id` is required, string, max:20
  - `tenant_rif` is nullable, string, max:20
  - `payment_date` is required, date, before_or_equal:today
  - `concept` is nullable, string, max:255

#### Scenario: Missing required sender fields

- GIVEN a tenant submits a bank_transfer payment
- WHEN the request is missing `sender_bank`
- THEN the controller MUST return a validation error
- AND the payment MUST NOT be created
