<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\DeviceInviteCode;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DeviceInviteCodeController extends Controller
{
    /**
     * List all invite codes with tenant and device info.
     */
    public function index(): Response
    {
        $codes = DeviceInviteCode::query()
            ->with(['tenant:id,name,domain', 'device:id,name', 'createdBy:id,name'])
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('landlord/invite-codes/index', [
            'codes' => $codes,
        ]);
    }

    /**
     * Show the form to create a new invite code.
     */
    public function create(): Response
    {
        return Inertia::render('landlord/invite-codes/create', [
            'tenants' => Tenant::all(['id', 'name', 'domain']),
        ]);
    }

    /**
     * Store a newly created invite code.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:App\Models\Tenant,id'],
            'expires_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);

        $expiresAt = $validated['expires_days'] > 0
            ? now()->addDays((int) $validated['expires_days'])
            : null;

        $code = DeviceInviteCode::create([
            'tenant_id' => $validated['tenant_id'],
            'code' => DeviceInviteCode::generate(),
            'expires_at' => $expiresAt,
            'created_by' => $request->user()?->id,
        ]);

        return redirect()->route('landlord.invite-codes.index')
            ->with('flash', [
                'success' => "Código {$code->code} generado para {$code->tenant->name}.",
            ]);
    }

    /**
     * Show the form to edit an invite code.
     */
    public function edit(DeviceInviteCode $device_invite_code): Response
    {
        $device_invite_code->load(['tenant:id,name,domain']);

        return Inertia::render('landlord/invite-codes/edit', [
            'code' => $device_invite_code,
        ]);
    }

    /**
     * Update the invite code (expires_at, etc.).
     */
    public function update(Request $request, DeviceInviteCode $device_invite_code): RedirectResponse
    {
        $validated = $request->validate([
            'expires_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);

        $expiresAt = $validated['expires_days'] > 0
            ? now()->addDays((int) $validated['expires_days'])
            : null;

        $device_invite_code->update([
            'expires_at' => $expiresAt,
        ]);

        return redirect()->route('landlord.invite-codes.index')
            ->with('flash', [
                'success' => "Código {$device_invite_code->code} actualizado.",
            ]);
    }

    /**
     * Remove the invite code.
     */
    public function destroy(DeviceInviteCode $device_invite_code): RedirectResponse
    {
        $code = $device_invite_code->code;
        $device_invite_code->delete();

        return redirect()->route('landlord.invite-codes.index')
            ->with('flash', [
                'success' => "Código {$code} eliminado.",
            ]);
    }
}
