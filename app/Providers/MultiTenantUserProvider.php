<?php

namespace App\Providers;

use App\Concerns\ResolvesUserModel; // ← AGREGADO: Fuente única de verdad
use Illuminate\Auth\EloquentUserProvider;

/**
 * Proveedor de usuarios personalizado que adapta el EloquentUserProvider estándar
 * para funcionar en un entorno multitenante usando Spatie Multitenancy.
 *
 * Este proveedor ahora ELIMINA LA DUPLICACIÓN de lógica al reutilizar
 * el mismo concern que usan los flujos de registro y validación.
 */
class MultiTenantUserProvider extends EloquentUserProvider
{
    use ResolvesUserModel; // ← CONSUMO DE LA CAPA FUNDAMENTAL (elimina duplicación)

    /**
     * Crea una nueva instancia del modelo de usuario apropiado.
     *
     * @return \Illuminate\Database\Eloquent\Model  Instancia de Landlord o User según tenancy
     */
    public function createModel()
    {
        // ← REUTILIZACIÓN: En lugar de lógica duplicada, usa el concern centralizado
        $class = $this->resolveUserModel();

        return new $class;
    }
}

// Cambios clave y su importancia:
// - Eliminado: use App\Models\Landlord; use App\Models\User; use Spatie\Multitenancy\Models\Tenant;
//  - Ya no necesarios porque la lógica se delega completamente al concern
// - Añadido: use App\Concerns\ResolvesUserModel; - Única dependencia necesaria
// - Añadido: use ResolvesUserModel; - Consumo del trait para acceder a resolveUserModel()
// - Simplificado: createModel() de 4 líneas a 2 líneas
//  - Antes: Lógica duplicada Tenant::current() ? User::class : Landlord::class
//  - Ahora: Reutiliza exactamente la misma lógica que usan registro y validación
// - Beneficio: Auth provider y flujos de registro ahora usan idéntica lógica de resolución