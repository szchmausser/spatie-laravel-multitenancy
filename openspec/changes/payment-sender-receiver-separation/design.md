# Technical Design: Payment Sender-Receiver Separation

> **Change**: `payment-sender-receiver-separation`
> **Source**: `docs/phase-2a-payment-architecture.md`, proposal.md, specs
> **Date**: 2026-06-14

---

## 1. Problem Summary

The payment detail tables have three gaps:

1. **`payments`** has no FK to `payment_method_configs` — no traceability of which receiving account was used.
2. **`pago_movil_details`** is missing `sender_id` (Cédula/RIF of the sender) — the migration that added sender fields (`2026_06_13_000004`) added `sender_bank`, `sender_phone`, `payment_date`, `concept` but skipped `sender_id`.
3. **`bank_transfer_details`** has only receiver snapshot columns — zero sender fields. The architecture doc defines 6 sender fields that should exist.

This change adds the missing columns, model fields, gateway persistence, and controller validation.

---

## 2. Migrations

Single migration file: `2026_06_14_000001_add_sender_receiver_separation_columns.php`

### 2.1 Payments Table — Add Config FK

```php
Schema::table('payments', function (Blueprint $table) {
    $table->foreignId('payment_method_config_id')
        ->nullable()
        ->after('payment_method')
        ->constrained('payment_method_configs')
        ->nullOnDelete();
});
```

- **Nullable**: backward compatibility with existing rows.
- **`nullOnDelete()`**: if a config is deleted, payment retains its record with NULL FK (not CASCADE delete).
- **Placed after `payment_method`**: logical grouping (method → which config).

### 2.2 PagoMovilDetails Table — Add Sender ID

```php
Schema::table('pago_movil_details', function (Blueprint $table) {
    $table->string('sender_id', 20)->after('sender_phone');
});
```

- **NOT NULL by default** in Laravel string columns. Since the architecture doc specifies `sender_id` as `NOT NULL`, and existing rows in this table are expected to have sender data (they were created via PagoMovilGateway which passes sender fields), this is safe. However, if any legacy rows exist without it, we should make it nullable with a default. Per the proposal, "Column is nullable — no data loss" — so let's make it nullable:

```php
$table->string('sender_id', 20)->nullable()->after('sender_phone');
```

### 2.3 BankTransferDetails Table — Add 6 Sender Fields

```php
Schema::table('bank_transfer_details', function (Blueprint $table) {
    $table->string('sender_bank', 100)->after('holder_id');
    $table->string('sender_name', 100)->after('sender_bank');
    $table->string('sender_id', 20)->after('sender_name');
    $table->string('tenant_rif', 20)->nullable()->after('sender_id');
    $table->date('payment_date')->after('tenant_rif');
    $table->string('concept', 255)->nullable()->after('payment_date');
});
```

All columns nullable for backward compatibility. Existing bank_transfer_detail rows will have NULL sender fields — acceptable per the proposal.

### 2.4 Migration Summary

| Table | Column | Type | Nullable | After |
|-------|--------|------|----------|-------|
| `payments` | `payment_method_config_id` | `bigint FK` | YES | `payment_method` |
| `pago_movil_details` | `sender_id` | `varchar(20)` | YES | `sender_phone` |
| `bank_transfer_details` | `sender_bank` | `varchar(100)` | NO* | `holder_id` |
| `bank_transfer_details` | `sender_name` | `varchar(100)` | NO* | `sender_bank` |
| `bank_transfer_details` | `sender_id` | `varchar(20)` | NO* | `sender_name` |
| `bank_transfer_details` | `tenant_rif` | `varchar(20)` | YES | `sender_id` |
| `bank_transfer_details` | `payment_date` | `date` | NO* | `tenant_rif` |
| `bank_transfer_details` | `concept` | `varchar(255)` | YES | `payment_date` |

*Making `sender_bank`, `sender_name`, `sender_id`, `payment_date` nullable in migration for backward compatibility, even though the architecture doc says NOT NULL. The NOT NULL constraint will be enforced at the controller validation layer (all required fields validated before gateway call). This avoids migration failures on existing data.

### 2.5 Down Migration

```php
Schema::table('payments', function (Blueprint $table) {
    $table->dropForeign(['payment_method_config_id']);
    $table->dropColumn('payment_method_config_id');
});

Schema::table('pago_movil_details', function (Blueprint $table) {
    $table->dropColumn('sender_id');
});

Schema::table('bank_transfer_details', function (Blueprint $table) {
    $table->dropColumn([
        'sender_bank', 'sender_name', 'sender_id',
        'tenant_rif', 'payment_date', 'concept',
    ]);
});
```

---

## 3. Model Changes

### 3.1 Payment Model

**File**: `app/Models/Payment.php`

Changes:
1. Add `payment_method_config_id` to `$fillable`
2. Add `paymentMethodConfig()` BelongsTo relationship

```php
// $fillable — add after 'payment_method':
'payment_method_config_id',

// New relationship:
public function paymentMethodConfig(): BelongsTo
{
    return $this->belongsTo(PaymentMethodConfig::class);
}
```

No cast changes needed — `payment_method_config_id` is a plain integer FK.

### 3.2 PagoMovilDetail Model

**File**: `app/Models/PagoMovilDetail.php`

Changes:
1. Add `sender_id` to `$fillable`

```php
// $fillable — add after 'sender_phone':
'sender_id',
```

No cast changes — `payment_date` cast already exists.

### 3.3 BankTransferDetail Model

**File**: `app/Models/BankTransferDetail.php`

Changes:
1. Add all 6 sender fields to `$fillable`
2. Add `payment_date` cast

```php
// $fillable — add after 'holder_id':
'sender_bank',
'sender_name',
'sender_id',
'tenant_rif',
'payment_date',
'concept',

// New casts method:
protected function casts(): array
{
    return [
        'payment_date' => 'date',
    ];
}
```

---

## 4. Gateway Changes

### 4.1 PagoMovilGateway

**File**: `app/Services/Payment/PagoMovilGateway.php`

Current state: `Payment::create()` does NOT include `payment_method_config_id`. `PagoMovilDetail::create()` does NOT include `sender_id`.

Changes to `recordPayment()`:

```php
$payment = Payment::create([
    'tenant_id' => $order->tenant_id,
    'order_id' => $order->id,
    'amount_cents' => $data['amount_cents'],
    'currency' => 'VES',
    'payment_method' => 'pago_movil',
    'payment_method_config_id' => $data['payment_method_config_id'] ?? null,  // ADD
    'status' => PaymentStatus::Pending,
]);

$payment->pagoMovilDetail()->create([
    'phone' => $config['phone'],
    'bank' => $config['bank'],
    'rif' => $config['rif'],
    'sender_bank' => $data['sender_bank'],
    'sender_phone' => $data['sender_phone'],
    'sender_id' => $data['sender_id'] ?? null,  // ADD
    'payment_date' => $data['payment_date'],
    'concept' => $data['concept'] ?? null,
]);
```

Also update the PHPDoc `@param` array shape to include `sender_id`.

### 4.2 BankTransferGateway

**File**: `app/Services/Payment/BankTransferGateway.php`

Current state: `Payment::create()` does NOT include `payment_method_config_id`. `BankTransferDetail::create()` only has receiver snapshot fields (4 columns), zero sender fields.

Changes to `recordPayment()`:

```php
$payment = Payment::create([
    'tenant_id' => $order->tenant_id,
    'order_id' => $order->id,
    'amount_cents' => $data['amount_cents'],
    'currency' => 'VES',
    'payment_method' => 'bank_transfer',
    'payment_method_config_id' => $data['payment_method_config_id'] ?? null,  // ADD
    'status' => PaymentStatus::Pending,
]);

$payment->bankTransferDetail()->create([
    // Snapshot receiver (unchanged)
    'account_number' => $config['account_number'],
    'bank_name' => $config['bank_name'],
    'account_holder' => $config['account_holder'],
    'holder_id' => $config['holder_id'],
    // Sender report (ADD all 6)
    'sender_bank' => $data['sender_bank'],
    'sender_name' => $data['sender_name'],
    'sender_id' => $data['sender_id'],
    'tenant_rif' => $data['tenant_rif'] ?? null,
    'payment_date' => $data['payment_date'],
    'concept' => $data['concept'] ?? null,
]);
```

Also update the PHPDoc `@param` array shape to include all sender fields.

---

## 5. Controller Changes

**File**: `app/Http/Controllers/Tenant/PaymentController.php`

Current state: Only validates `sender_bank`, `sender_phone`, `payment_date`, `concept` — conditionally required for `pago_movil`, nullable otherwise. No validation for `sender_id` or any bank_transfer sender fields.

Changes to `store()` validation:

```php
$validated = $request->validate([
    // ... existing reference, payment_method, payment_method_config_id rules ...

    // Pago Móvil sender fields — required when method is pago_movil
    'sender_bank' => [
        ...($request->input('payment_method') === 'pago_movil'
            ? ['required', 'string', 'max:100']
            : ['nullable', 'string', 'max:100']),
    ],
    'sender_phone' => [
        ...($request->input('payment_method') === 'pago_movil'
            ? ['required', 'string', 'max:20']
            : ['nullable', 'string', 'max:20']),
    ],
    'sender_id' => [
        ...($request->input('payment_method') === 'pago_movil'
            ? ['required', 'string', 'max:20']
            : ['nullable', 'string', 'max:20']),
    ],
    'payment_date' => [
        ...($request->input('payment_method') === 'pago_movil'
            ? ['required', 'date', 'before_or_equal:today']
            : ['nullable', 'date', 'before_or_equal:today']),
    ],
    'concept' => [
        'nullable',
        'string',
        'max:255',
    ],

    // Bank Transfer sender fields — NEW
    'sender_name' => [
        ...($request->input('payment_method') === 'bank_transfer'
            ? ['required', 'string', 'max:100']
            : ['nullable', 'string', 'max:100']),
    ],
    'tenant_rif' => [
        ...($request->input('payment_method') === 'bank_transfer'
            ? ['nullable', 'string', 'max:20']
            : ['nullable', 'string', 'max:20']),
    ],
]);
```

Also update the `$gatewayData` builder to include bank_transfer sender fields:

```php
$gatewayData = [];
if ($validated['payment_method'] === 'pago_movil') {
    $gatewayData = [
        'sender_bank' => $validated['sender_bank'],
        'sender_phone' => $validated['sender_phone'],
        'sender_id' => $validated['sender_id'],
        'payment_date' => $validated['payment_date'],
        'concept' => $validated['concept'] ?? null,
    ];
} elseif ($validated['payment_method'] === 'bank_transfer') {
    $gatewayData = [
        'sender_bank' => $validated['sender_bank'],
        'sender_name' => $validated['sender_name'],
        'sender_id' => $validated['sender_id'],
        'tenant_rif' => $validated['tenant_rif'] ?? null,
        'payment_date' => $validated['payment_date'],
        'concept' => $validated['concept'] ?? null,
    ];
}
```

---

## 6. Sequence Diagram

```
┌──────────┐     ┌──────────────┐     ┌──────────────────┐     ┌──────────┐
│  Tenant  │     │PaymentControl│     │ PaymentService    │     │ Gateway  │
│ (Browser)│     │   (store)    │     │ (recordPayment)   │     │(PM or BT)│
└────┬─────┘     └──────┬───────┘     └────────┬──────────┘     └────┬─────┘
     │                  │                      │                     │
     │ POST /payments   │                      │                     │
     │ {payment_method, │                      │                     │
     │  config_id,      │                      │                     │
     │  sender fields}  │                      │                     │
     │─────────────────>│                      │                     │
     │                  │                      │                     │
     │                  │ 1. Validate:         │                     │
     │                  │  - reference format  │                     │
     │                  │  - unique reference  │                     │
     │                  │  - order is pending  │                     │
     │                  │  - sender fields     │                     │
     │                  │    (conditional by   │                     │
     │                  │     payment_method)  │                     │
     │                  │                      │                     │
     │                  │ 2. Build gatewayData │                     │
     │                  │  (sender fields)     │                     │
     │                  │                      │                     │
     │                  │ 3. recordPayment()   │                     │
     │                  │─────────────────────>│                     │
     │                  │                      │                     │
     │                  │                      │ 4. Resolve gateway  │
     │                  │                      │    by method        │
     │                  │                      │────────────────────>│
     │                  │                      │                     │
     │                  │                      │ 5. DB::transaction  │
     │                  │                      │                     │
     │                  │                      │ 5a. Payment::create │
     │                  │                      │  { payment_method   │
     │                  │                      │    _config_id }     │
     │                  │                      │                     │
     │                  │                      │ 5b. Resolve receiver│
     │                  │                      │     from config     │
     │                  │                      │                     │
     │                  │                      │ 5c. Detail::create  │
     │                  │                      │  { snapshot receiver │
     │                  │                      │    + sender fields }│
     │                  │                      │                     │
     │                  │  Payment             │                     │
     │                  │<─────────────────────│                     │
     │                  │                      │                     │
     │                  │ 6. Store reference   │                     │
     │                  │    on payment        │                     │
     │                  │                      │                     │
     │  Redirect to     │                      │                     │
     │  payment show    │                      │                     │
     │<─────────────────│                      │                     │
     │                  │                      │                     │
```

**Key flow points**:
- Step 1: Controller validates sender fields conditionally based on `payment_method`
- Step 5a: Payment is created with `payment_method_config_id` (nullable)
- Step 5b: Gateway resolves receiver snapshot from PaymentMethodConfig
- Step 5c: Detail table stores both snapshot (immutable receiver) AND sender fields (tenant report data)

---

## 7. File Change Summary

| # | File | Change Type | Description |
|---|------|-------------|-------------|
| 1 | `database/migrations/landlord/2026_06_14_000001_add_sender_receiver_separation_columns.php` | **NEW** | Migration: add `payment_method_config_id` to payments, `sender_id` to pago_movil_details, 6 sender fields to bank_transfer_details |
| 2 | `app/Models/Payment.php` | **MODIFY** | Add `payment_method_config_id` to `$fillable`, add `paymentMethodConfig()` BelongsTo relationship |
| 3 | `app/Models/PagoMovilDetail.php` | **MODIFY** | Add `sender_id` to `$fillable` |
| 4 | `app/Models/BankTransferDetail.php` | **MODIFY** | Add 6 sender fields to `$fillable`, add `casts()` method with `payment_date` cast |
| 5 | `app/Services/Payment/PagoMovilGateway.php` | **MODIFY** | Pass `payment_method_config_id` to Payment::create, pass `sender_id` to PagoMovilDetail::create, update PHPDoc |
| 6 | `app/Services/Payment/BankTransferGateway.php` | **MODIFY** | Pass `payment_method_config_id` to Payment::create, pass all 6 sender fields to BankTransferDetail::create, update PHPDoc |
| 7 | `app/Http/Controllers/Tenant/PaymentController.php` | **MODIFY** | Add `sender_id` validation for pago_movil, add `sender_name`/`tenant_rif` validation for bank_transfer, update gatewayData builder for both methods |

---

## 8. Test Plan

### 8.1 Migration Test
- Test migration runs cleanly up and down
- Verify `payment_method_config_id` column exists on `payments` (nullable)
- Verify `sender_id` column exists on `pago_movil_details`
- Verify all 6 sender columns exist on `bank_transfer_details`

### 8.2 Model Tests
- `Payment::create()` accepts `payment_method_config_id`
- `$payment->paymentMethodConfig` returns related config (or null)
- `PagoMovilDetail::create()` accepts `sender_id`
- `BankTransferDetail::create()` accepts all 6 sender fields

### 8.3 Gateway Tests
- `PagoMovilGateway::recordPayment()` creates Payment with `payment_method_config_id`
- `PagoMovilGateway::recordPayment()` creates PagoMovilDetail with `sender_id`
- `BankTransferGateway::recordPayment()` creates Payment with `payment_method_config_id`
- `BankTransferGateway::recordPayment()` creates BankTransferDetail with all 6 sender fields

### 8.4 Controller Validation Tests
- bank_transfer request without `sender_bank` → 422
- bank_transfer request without `sender_name` → 422
- bank_transfer request without `sender_id` → 422
- bank_transfer request without `payment_date` → 422
- bank_transfer request with `tenant_rif` null → 200 (nullable)
- pago_movil request without `sender_id` → 422 (new validation)
- pago_movil request with all sender fields → creates payment with sender_id

---

## 9. Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Existing pago_movil rows lack `sender_id` | Low | Column is nullable — no data loss |
| Existing bank_transfer rows lack sender fields | Low | All new columns are nullable |
| BankTransferGateway breaks if sender fields missing | Medium | Controller validates required fields before gateway call |
| `payment_method_config_id` FK on existing payments | Low | Column is nullable — no FK violation |

---

## 10. Rollback Plan

1. `php artisan migrate:rollback` — drops all added columns and FK
2. Revert model changes (fillable arrays, relationship, casts)
3. Revert gateway changes (remove sender field persistence)
4. Revert controller validation changes

The migration is purely additive (nullable columns), so rollback is safe with no data loss.
