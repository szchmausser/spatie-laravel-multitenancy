<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Concerns\ResolvesUserModel;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Auth\User as BaseUser;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Spatie\Permission\Traits\HasRoles;

/**
 * Acción de Fortify para crear nuevos usuarios.
 * Ahora usa resolución dinámica de modelos y cumple exactamente
 * con el tipo de retorno esperado por el contrato de Fortify.
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules, ResolvesUserModel; // ← AGREGADO: ResolvesUserModel

    /**
     * Valida y crea un nuevo usuario registrado.
     *
     * @param  array<string, string>  $input  Datos de entrada del formulario de registro
     * @return User Instancia del usuario creado (Landlord o User según tenancy)
     */
    public function create(array $input): BaseUser
    {
        // La validación ahora usa ProfileValidationRules corregido (Fase 2)
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        // ← RESOLUCIÓN DINÁMICA: Obtiene el modelo correcto según tenancy
        $userModel = $this->resolveUserModel();

        // ← CREACIÓN DINÁMICA: Usa el modelo resuelto para crear el usuario
        $user = $userModel::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        // Assign tenant-admin role to the first user of each tenant.
        // The permission catalog (change-plan permission + tenant-admin role)
        // is seeded by Tenant::seedPermissions() when the tenant is created.
        $this->assignRoleToFirstUserIfTenant($user);

        return $user;
    }

    /**
     * Assign the tenant-admin role to the first user of a tenant.
     *
     * Only runs when the user model uses the HasRoles trait (i.e.,
     * tenant context — not landlord). Checks the user count on the
     * tenant connection: if this is the first user, they receive
     * the tenant-admin role automatically.
     *
     * The tenant-admin role and change-plan permission must already
     * exist in the tenant database (seeded by Tenant::seedPermissions()
     * when the tenant was created).
     */
    private function assignRoleToFirstUserIfTenant(BaseUser $user): void
    {
        if (! in_array(HasRoles::class, class_uses_recursive($user))) {
            return;
        }

        $userCount = $user->on('tenant')->count();

        if ($userCount === 1) {
            $user->assignRole('tenant-admin');
        }
    }
}
