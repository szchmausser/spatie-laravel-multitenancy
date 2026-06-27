<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DeviceAuth
{
    /**
     * Authenticate a device via X-Device-Token header.
     *
     * Reads the header, looks up an active device by token,
     * and injects the Device model into the request on success.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Device-Token');

        if ($token === null) {
            return response()->json(['message' => 'Missing X-Device-Token header'], 401);
        }

        $device = Device::where('token', $token)
            ->where('is_active', true)
            ->first();

        if ($device === null) {
            return response()->json(['message' => 'Invalid or inactive device token'], 401);
        }

        $request->merge(['device' => $device]);

        return $next($request);
    }
}
