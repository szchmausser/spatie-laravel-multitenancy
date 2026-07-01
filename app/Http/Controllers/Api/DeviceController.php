<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\IngestPaymentNotification;
use App\Models\Device;
use App\Models\DeviceInviteCode;
use App\Models\Landlord;
use App\Models\PaymentNotification;
use App\Models\SystemConfig;
use App\Notifications\SystemAlert;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class DeviceController extends Controller
{
    /**
     * Register a new device using a single-use invite code.
     *
     * On successful validation:
     * - The code is consumed (marked as used, cannot be reused)
     * - The device is created with is_active = true
     *
     * If an android_device_id is provided and a device with that ID
     * already exists, the existing device is reused (new token issued,
     * reactivated). This handles app reinstalls.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'android_device_id' => ['nullable', 'string', 'max:255'],
            'invite_code' => ['required', 'string', 'max:64'],
        ]);

        $inviteCode = DeviceInviteCode::where('code', $validated['invite_code'])->first();

        if (! $inviteCode) {
            return response()->json(['message' => 'Invalid invite code.'], 401);
        }

        if (! $inviteCode->isValid()) {
            $reason = $inviteCode->isUsed() ? 'already been used' : 'expired';

            return response()->json([
                'message' => "This invite code has {$reason}.",
            ], 401);
        }

        $device = null;

        if (! empty($validated['android_device_id'])) {
            $device = Device::where('android_device_id', $validated['android_device_id'])->first();
        }

        if ($device !== null) {
            // Reuse existing device: regenerate token, update name, reactivate.
            $device->update([
                'name' => $validated['name'],
                'token' => Str::random(64),
                'is_active' => true,
            ]);
        } else {
            $device = Device::create([
                'name' => $validated['name'],
                'token' => Str::random(64),
                'android_device_id' => $validated['android_device_id'] ?? null,
                'is_active' => true,
            ]);
        }

        // Consume the invite code — single use, tied to this device
        $inviteCode->consume($device->id);

        return response()->json([
            'device_id' => $device->id,
            'token' => $device->token,
            'name' => $device->name,
            'is_active' => $device->is_active,
        ], 201);
    }

    /**
     * Store a payment notification sent by a registered device.
     *
     * The dedup_hash is used for idempotency. If the hash already exists,
     * the notification is silently ignored (200 duplicate_ignored).
     * On first occurrence, the notification is persisted and the
     * IngestPaymentNotification job is dispatched for async processing.
     *
     * The Android device MUST send the raw, unparsed notification body.
     * All parsing happens server-side via PaymentNotificationParser;
     * fields pre-parsed by the device are ignored for correctness.
     */
    public function storeNotification(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->get('device');

        $validated = $request->validate([
            'bank_code' => ['required', 'string', 'max:20'],
            'raw_body' => ['required', 'string'],
            'dedup_hash' => ['required', 'string', 'max:64'],
        ]);

        try {
            $notification = PaymentNotification::forceCreate([
                'device_id' => $device->id,
                'bank_code' => $validated['bank_code'],
                'raw_text' => $validated['raw_body'],
                'dedup_hash' => $validated['dedup_hash'],
                'parse_status' => 'pending',
            ]);

            $computedHash = PaymentNotification::computeDedupHash(
                $validated['bank_code'],
                $validated['raw_body'],
            );

            if ($computedHash !== $validated['dedup_hash']) {
                $snippet = mb_substr($validated['raw_body'], 0, 100);

                Log::warning('Dedup hash mismatch', [
                    'bank_code' => $validated['bank_code'],
                    'raw_body_snippet' => $snippet,
                    'device_hash' => $validated['dedup_hash'],
                    'computed_hash' => $computedHash,
                ]);

                $admins = Landlord::all();

                Notification::send($admins, new SystemAlert(
                    type: 'dedup_hash_mismatch',
                    message: "Dedup hash mismatch for bank [{$validated['bank_code']}]. Device hash: {$validated['dedup_hash']}, computed: {$computedHash}. Raw body: {$snippet}",
                    severity: 'warning',
                ));
            }

            IngestPaymentNotification::dispatch($notification);

            return response()->json(['status' => 'created'], 201);
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                return response()->json(['status' => 'duplicate_ignored'], 200);
            }

            throw $e;
        }
    }

    /**
     * Process a heartbeat from a registered device.
     *
     * Updates the device's last heartbeat timestamp and IP.
     * If the Android device ID changed, it is updated as well.
     * Returns the configured heartbeat interval so the device
     * can adjust its polling frequency.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->get('device');

        $validated = $request->validate([
            'android_device_id' => ['nullable', 'string', 'max:255'],
            'battery_level' => ['nullable', 'integer', 'min:0', 'max:100'],
            'pending_count' => ['nullable', 'integer', 'min:0'],
        ]);

        // Registrar heartbeat histórico
        $device->heartbeats()->create([
            'battery_level' => $validated['battery_level'] ?? null,
            'pending_count' => $validated['pending_count'] ?? null,
            'ip' => $request->ip(),
        ]);

        $updates = [
            'last_heartbeat_at' => now(),
            'last_heartbeat_ip' => $request->ip(),
        ];

        if ($request->filled('android_device_id')) {
            $updates['android_device_id'] = $validated['android_device_id'];
        }

        $device->update($updates);

        $interval = (int) SystemConfig::get('device.heartbeat_interval_minutes', 5);

        return response()->json([
            'status' => 'ok',
            'heartbeat_interval_minutes' => $interval,
        ]);
    }
}
