<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\SystemConfig;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Controller for managing dynamic SystemConfig records.
 *
 * Provides a grouped listing of all configs and type-aware
 * editing with cache invalidation via SystemConfig::set().
 *
 * @see SystemConfig
 */
class SystemConfigController extends Controller
{
    /**
     * Display all system configs grouped by their group field.
     */
    public function index()
    {
        $configs = SystemConfig::query()->orderBy('group')->orderBy('key')->get();

        return Inertia::render('landlord/system-configs/index', [
            'groups' => $configs->groupBy('group'),
        ]);
    }

    /**
     * Update a system config value with type coercion and validation.
     *
     * Validates the value according to the config's type:
     * - string: any string
     * - integer: numeric value
     * - boolean: 0, 1, "true", "false"
     * - json: optional valid JSON string
     *
     * Catches InvalidArgumentException from SystemConfig's regex
     * validation (boot saving event) and converts it to validation
     * errors for Inertia error handling.
     */
    public function update(Request $request, SystemConfig $systemConfig)
    {
        $rules = match ($systemConfig->type) {
            'integer' => ['required', 'numeric'],
            'boolean' => ['required', 'in:0,1,true,false'],
            'string' => ['required', 'string'],
            'json' => ['nullable', 'string'],
            default => ['required', 'string'],
        };

        $validated = $request->validate([
            'value' => $rules,
        ]);

        // Coerce value based on type
        $value = match ($systemConfig->type) {
            'boolean' => filter_var($validated['value'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            'integer' => (int) $validated['value'],
            default => $validated['value'],
        };

        try {
            $record = SystemConfig::set($systemConfig->key, $value, $systemConfig->type);
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withErrors([
                'value' => [$e->getMessage()],
            ]);
        }

        return redirect()->back()->with('success', 'Configuración actualizada exitosamente.');
    }
}
