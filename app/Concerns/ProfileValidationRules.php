<?php

namespace App\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Trait que proporciona reglas de validación comunes para perfiles de usuario.
 * Ahora usa resolución dinámica de modelos para garantizar consistencia
 * entre el contexto de tenancy y la validación de unicidad.
 */
trait ProfileValidationRules
{
    use ResolvesUserModel; // ← CONSUMO DE LA CAPA FUNDAMENTAL

    /**
     * Obtiene las reglas de validación para perfiles de usuario.
     *
     * @param  int|null  $userId  ID del usuario para ignorar en validación de unicidad (null para creación)
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId),
        ];
    }

    /**
     * Reglas para validar nombres de usuario.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Reglas para validar emails de usuario.
     * Ahora usa resolución dinámica de modelos para asegurar que la validación
     * de unicidad ocurra contra la conexión de base de datos correcta.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        // ← RESOLUCIÓN DINÁMICA: Obtiene el modelo correcto según tenancy
        $userModel = $this->resolveUserModel();

        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique($userModel)          // Validación de creación: verificar unicidad absoluta
                : Rule::unique($userModel)->ignore($userId), // Validación de actualización: ignorar el usuario actual
        ];
    }
}

// Cambios clave y su importancia:
// - Añadido: use ResolvesUserModel; - Consumo de la capa fundamental de lógica
// - Modificado: emailRules() - Ahora usa $this->resolveUserModel() en lugar de User::class hardcodeado
// - Impacto: La validación Rule::unique() ahora consulta la conexión de base de datos correcta:
//  - En landlord: verifica unicidad contra la tabla users de la BD landlord (usando modelo Landlord)
//  - En tenant: verifica unicidad contra la tabla users de la BD tenant activa (usando modelo User)
