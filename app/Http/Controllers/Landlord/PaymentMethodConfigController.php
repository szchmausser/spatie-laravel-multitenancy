<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Landlord\StorePaymentMethodConfigRequest;
use App\Http\Requests\Landlord\UpdatePaymentMethodConfigRequest;
use App\Models\PaymentMethodConfig;
use Inertia\Inertia;

/**
 * CRUD controller for PaymentMethodConfig (PagoMóvil / Transferencia).
 *
 * Follows the same RESTful pattern as PlanController and TenantController.
 * Three Inertia pages: index (grouped listing), create, and edit.
 */
class PaymentMethodConfigController extends Controller
{
    /**
     * Display all configs grouped by type.
     */
    public function index()
    {
        $configsByType = PaymentMethodConfig::query()
            ->orderBy('type')
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get()
            ->groupBy('type');

        return Inertia::render('landlord/payment-configs/index', [
            'configsByType' => $configsByType,
        ]);
    }

    /**
     * Show the create form.
     */
    public function create()
    {
        return Inertia::render('landlord/payment-configs/create');
    }

    /**
     * Store a new config.
     */
    public function store(StorePaymentMethodConfigRequest $request)
    {
        PaymentMethodConfig::create($request->validated());

        return redirect()->route('landlord.payment-configs.index')
            ->with('success', 'Cuenta bancaria creada exitosamente.');
    }

    /**
     * Show the edit form.
     */
    public function edit(PaymentMethodConfig $paymentMethodConfig)
    {
        return Inertia::render('landlord/payment-configs/edit', [
            'config' => $paymentMethodConfig,
        ]);
    }

    /**
     * Update an existing config.
     */
    public function update(UpdatePaymentMethodConfigRequest $request, PaymentMethodConfig $paymentMethodConfig)
    {
        $paymentMethodConfig->update($request->validated());

        return redirect()->route('landlord.payment-configs.index')
            ->with('success', 'Cuenta bancaria actualizada exitosamente.');
    }

    /**
     * Delete a config. If no active configs remain for the same type,
     * add a warning flash.
     */
    public function destroy(PaymentMethodConfig $paymentMethodConfig)
    {
        $type = $paymentMethodConfig->type;
        $paymentMethodConfig->delete();

        $remainingActive = PaymentMethodConfig::query()
            ->where('type', $type)
            ->where('is_active', true)
            ->count();

        if ($remainingActive === 0) {
            $typeLabel = $type === 'pago_movil' ? 'PagoMóvil' : 'Transferencia Bancaria';

            return redirect()->route('landlord.payment-configs.index')
                ->with('warning', "No quedan cuentas {$typeLabel} activas. Los tenants no podrán pagar con este método hasta que agregues una nueva.");
        }

        return redirect()->route('landlord.payment-configs.index')
            ->with('success', 'Cuenta bancaria eliminada exitosamente.');
    }
}
