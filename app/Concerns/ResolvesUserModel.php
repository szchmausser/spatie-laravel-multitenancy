<?php

namespace App\Concerns;

use App\Models\Landlord;
use App\Models\Tenant;
use App\Models\User;

/**
 * Concern que proporciona lógica centralizada para resolver el modelo de usuario
 * apropiado según el contexto de tenancy actual.
 *
 * Esta es la FUENTE ÚNICA DE VERDAD para la resolución de modelos tenant-aware.
 * Cualquier componente que necesite saber si usar Landlord o User debe consumir
 * este concern mediante el trait.
 */
trait ResolvesUserModel
{
    /**
     * Resuelve el modelo de usuario apropiado basado en el contexto de tenancy actual.
     *
     * @return string Nombre de clase FQDN del modelo a usar
     *
     * Lógica:
     * - Si Tenant::current() retorna null (dominio landlord) → Usar Landlord
     * - Si Tenant::current() retorna una instancia de Tenant (dominio tenant) → Usar User
     */
    protected function resolveUserModel(): string
    {
        return Tenant::current() ? User::class : Landlord::class;
    }
}

// ¿Por qué este archivo es crítico?
// - Centraliza la lógica Tenant::current() ? User::class : Landlord::class que antes estaba duplicada
// - Elimina la posibilidad de inconsistencia entre componentes
// - Permite reutilización sencilla mediante traits en cualquier clase de Laravel
// - Es la única ubicación que necesita modificarse si cambia la lógica de tenancy
