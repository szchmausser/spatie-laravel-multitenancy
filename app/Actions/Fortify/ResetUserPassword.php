<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Auth\Authenticatable; // ← CORREGIDO: Tipo de parámetro
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

/**
 * Acción de Fortify para reseteo de contraseñas.
 * Ahora acepta cualquier modelo que implemente Authenticatable
 * para ser compatible tanto con Landlord como con User devueltos
 * por nuestro proveedor de auth tenant-aware.
 */
class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Valida y resetea la contraseña de un usuario olvidada.
     *
     * @param  Authenticatable  $user  Instancia del usuario (Landlord o User)
     * @param  array<string, string>  $input  Datos de entrada conteniendo la nueva contraseña
     */
    public function reset(Authenticatable $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $user->forceFill([
            'password' => $input['password'],
        ])->save();
    }
}

// Cambios clave y su importancia:
// - Cambiado: Tipo de parámetro de User $user a Authenticatable $user
// - Por qué: Nuestro MultiTenantUserProvider puede devolver tanto Landlord como User dependiendo del contexto de tenancy
// - Ambos modelos implementan Illuminate\Contracts\Auth\Authenticatable
// - Este cambio respeta el principio de Liskov Sustitución: el método ahora acepta cualquier cosa que el contrato espera poder hacer con un usuario (autenticación)
// - Elimina el TypeError que ocurría cuando se pasaba una instancia Landlord a un método que esperaba estrictamente User
