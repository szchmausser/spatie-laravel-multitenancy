<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Landlord\StoreDeviceRequest;
use App\Http\Requests\Landlord\UpdateDeviceRequest;
use App\Models\Device;
use Illuminate\Support\Str;
use Inertia\Inertia;

class DeviceController extends Controller
{
    public function index()
    {
        $devices = Device::query()
            ->orderBy('last_heartbeat_at', 'desc')
            ->orderBy('name')
            ->get();

        return Inertia::render('landlord/devices/index', [
            'devices' => $devices,
        ]);
    }

    public function show(Device $device)
    {
        $heartbeats = $device->heartbeats()
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return Inertia::render('landlord/devices/show', [
            'device' => $device,
            'heartbeats' => $heartbeats,
        ]);
    }

    public function create()
    {
        return Inertia::render('landlord/devices/create');
    }

    public function store(StoreDeviceRequest $request)
    {
        Device::create([
            'name' => $request->name,
            'token' => Str::random(64),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->route('landlord.devices.index')
            ->with('success', 'Dispositivo creado exitosamente.');
    }

    public function edit(Device $device)
    {
        return Inertia::render('landlord/devices/edit', [
            'device' => $device,
        ]);
    }

    public function update(UpdateDeviceRequest $request, Device $device)
    {
        $device->update([
            'name' => $request->name,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->route('landlord.devices.index')
            ->with('success', 'Dispositivo actualizado exitosamente.');
    }

    public function destroy(Device $device)
    {
        $device->delete();

        return redirect()->route('landlord.devices.index')
            ->with('success', 'Dispositivo eliminado exitosamente.');
    }
}
