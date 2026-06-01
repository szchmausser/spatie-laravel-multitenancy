<?php

namespace App\Http\Middleware;

use App\Models\Landlord;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated as BaseRedirect;
use Illuminate\Http\Request;

class RedirectIfAuthenticated extends BaseRedirect
{
    protected function redirectTo(Request $request): ?string
    {
        return $request->user() instanceof Landlord
            ? '/admin'
            : parent::redirectTo($request);
    }
}
