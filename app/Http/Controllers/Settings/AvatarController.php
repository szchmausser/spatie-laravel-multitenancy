<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AvatarController extends Controller
{
    /**
     * Update the user's avatar.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
        ]);

        $user = $request->user();

        $user->addMediaFromRequest('avatar')
            ->sanitizingFileName(fn (string $fileName): string => strtolower((string) str($fileName)->replace(['#', '/', '\\', ' '], '-')))
            ->toMediaCollection('avatar');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Avatar updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Remove the user's avatar.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        $user->clearMediaCollection('avatar');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Avatar removed.')]);

        return to_route('profile.edit');
    }
}
