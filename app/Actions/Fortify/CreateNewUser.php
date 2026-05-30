<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Concerns\ResolvesUserModel; // ← AGREGADO: Fuente única de verdad
use Illuminate\Foundation\Auth\User as BaseUser; // ← CORREGIDO: Tipo de contrato Fortify
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

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
     * @return \Illuminate\Foundation\Auth\User  Instancia del usuario creado (Landlord o User según tenancy)
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
        return $userModel::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
    }
}

// Cambios clave y su importancia:
// - Añadido: use App\Concerns\ResolvesUserModel; - Acceso a la lógica centralizada
// - Añadido: use ResolvesUserModel; en la clase - Consumo del trait
// - Cambiado: Tipo de retorno de User a Illuminate\Foundation\Auth\User as BaseUser
//  - Por qué: El contrato Laravel\Fortify\Contracts\CreatesNewUsers::create() espera específicamente retornar \Illuminate\Foundation\Auth\User
//  - Tanto Landlord como User extienden esta clase base, por lo que cumple con el principio de substitutividad
//  - Evita TypeError en PHP 8.5+ cuando se retorna Landlord desde el dominio landlord
// - Modificado: Lógica de creación - Ahora usa $userModel::create([...]) en lugar de User::create([...])