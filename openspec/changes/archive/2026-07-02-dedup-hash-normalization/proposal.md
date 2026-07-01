# Proposal: Dedup Hash Normalization

## Intent

Current `SHA256(bank_code + "|" + raw_text)` dedup fails when the same payment arrives from different Android devices or channels because raw text varies (BNC masks phone as `0416***9503` vs full `04161234567`; dates use 2 vs 4 digit years). Result: duplicate notifications ingest as separate records, triggering duplicate reconciliation and race conditions in auto-verification.

Fix by normalizing the input to the hash so semantically identical payments produce the same dedup_hash regardless of source formatting or device.

## Metáfora rectora

> "Soy una celebridad, los reporteros (dispositivos Android) preguntan en simultáneo.
> Si ya respondí una pregunta (procesé un pago), ignoro las repetidas y atiendo otra."

## Hash Contract (coordinado con Android)

```
normalized = amount + "|" + phone + "|" + date + "|" + reference
dedup_hash = SHA256(bank_code + normalized)
```

Reglas:
- **Separador**: `|` (pipe), siempre entre los 4 campos
- **Orden**: monto | teléfono | fecha | referencia (inmutable)
- **Campo faltante**: `""` (string vacío), nunca null, nunca omitir el separador
- **Teléfono BNC**: `canonicalPhone()` → solo dígitos → first4 + last4
- **Teléfono otros bancos**: dígitos completos, sin normalizar
- **Fecha**: ISO 8601 (`Y-m-d\TH:i:s`), multi-formato al parsear
- **Monto**: string tal cual del regex (BNC sin separador de miles, BDV con punto)

## Scope

### In Scope

1. **BankCode enum** — `app/Enums/BankCode.php` con casos `Bdv`, `Bnc`. Centraliza: code, name, regex, date formats, canonicalPhone flag (solo BNC), Android package name
2. **Normalization layer** — agregar `normalizeForDedup()`, `canonicalPhone()`, `parseDateMultiFormat()` al `PaymentNotificationParser` existente. Reutiliza los mismos regex que `parse()`
3. **computeDedupHash() reescrita** — delega al parser: `hash('sha256', $bankCode.$normalized)`
4. **storeNotification hash verification** — recomputa el hash, si no coincide con el de Android → Log + SystemAlert (no rechazar)
5. **SimulatePaymentNotification** — usar BankCode enum en vez de `VALID_BANKS`

### Out of Scope

- ❌ `hash_version` columna ni migración de schema
- ❌ Backfill de registros viejos (el nuevo hash no colisiona con el viejo)
- ❌ `canonicalPhone()` para bancos que no enmascaran (solo BNC)
- ❌ Cambiar `parse()` ni su firma
- ❌ Normalización para bancos no agregados al enum (default: raw text)

## Approach

### BankCode enum

```php
enum BankCode: string
{
    case Bdv = 'bdv';
    case Bnc = 'bnc';

    public function appliestCanonicalPhone(): bool
    {
        return match ($this) {
            self::Bnc => true,
            default => false,
        };
    }

    public function dateFormats(): array
    {
        return match ($this) {
            self::Bdv => ['d-m-y H:i'],
            self::Bnc => ['d/m/y H:i', 'd/m/Y H:i'],
        };
    }

    // androidPackage(), regex() etc.
}
```

### Normalization flow

```
normalizeForDedup(bankCode, rawBody):
  1. Corre regex del banco sobre rawBody → captura amount, phone, date, time, reference
  2. amount string → tal cual del regex
  3. phone → canonicalPhone() si aplica, dígitos completos si no
  4. date + time → parseDateMultiFormat() → ISO 8601
  5. reference → dígitos tal cual
  6. Retorna "amount|phone|iso_date|reference"
```

### Verification in storeNotification

```php
$expected = PaymentNotification::computeDedupHash($validated['bank_code'], $validated['raw_body']);

if ($expected !== $validated['dedup_hash']) {
    Log::warning("Hash mismatch for {$validated['bank_code']}", [
        'expected' => $expected,
        'received' => $validated['dedup_hash'],
        'raw_body' => $validated['raw_body'],
    ]);
    // SystemAlert a admins
}
// Guardar igual, UNIQUE constraint lo protege
```

## Affected Areas

| Area | Type |
|------|------|
| `app/Enums/BankCode.php` | **New** |
| `app/Services/Payment/PaymentNotificationParser.php` | **Modified** |
| `app/Models/PaymentNotification.php` | **Modified** |
| `app/Http/Controllers/Api/DeviceController.php` | **Modified** |
| `app/Console/Commands/SimulatePaymentNotification.php` | **Modified** |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| normalizeForDedup diverge de parse() | Low | Comparten regex, vive en el mismo parser |
| Hash nuevo vs hash viejo colisionan en UNIQUE | Low | Algoritmos distintos, no pueden producir el mismo output |
| Android cambia su normalización sin avisar | Low | El mismatch alerta; no rechaza |
| canonicalPhone() genera falsos positivos (2 pagos distintos con mismo prefix+suffix) | Low | Ref + monto + fecha diferencian |

## Success Criteria

- [ ] BNC push (`0412***4567`) y BNC SMS (`04121234567`) del mismo pago producen el mismo hash
- [ ] BNC fecha 2 dígitos vs 4 dígitos producen el mismo hash
- [ ] BDV sin enmascarar funciona sin canonicalPhone
- [ ] Hash mismatch loggea SystemAlert sin bloquear la inserción
- [ ] `BankCode` enum centraliza todo bank-specific string
- [ ] Tests de integración verifican que el normalized coincide con lo que produce Android
