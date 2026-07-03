<?php

namespace App\Http\Controllers\Api;

use App\Actions\IngestNotificationAction;
use App\Enums\SourceType;
use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IngestController extends Controller
{
    /**
     * Ingest a payment notification from a source type resolved by the route.
     *
     * The source_type is determined by the URL segment (bank-app, sms, etc.).
     * Auth is handled by the device token middleware.
     */
    public function __invoke(Request $request, string $source): JsonResponse
    {
        $sourceType = SourceType::tryFrom($source);

        if ($sourceType === null) {
            return response()->json([
                'message' => "Invalid source type: {$source}",
            ], 422);
        }

        $validated = $request->validate([
            'bank_code' => ['required', 'string', 'max:20'],
            'raw_body' => ['required', 'string'],
        ]);

        /** @var Device $device */
        $device = $request->get('device');

        try {
            $notification = app(IngestNotificationAction::class)->execute(
                bankCode: $validated['bank_code'],
                rawBody: $validated['raw_body'],
                sourceType: $sourceType,
                deviceId: $device->id,
            );

            return response()->json(['status' => 'created', 'id' => $notification->id], 201);
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                return response()->json(['status' => 'duplicate_ignored'], 200);
            }

            throw $e;
        }
    }
}
