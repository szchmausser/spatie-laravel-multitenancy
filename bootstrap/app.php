<?php

use App\Http\Middleware\DeviceAuth;
use App\Http\Middleware\EnsureTenantHasFeature;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectIfAuthenticated;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Spatie\Multitenancy\Http\Middleware\EnsureValidTenantSession;
use Spatie\Multitenancy\Http\Middleware\NeedsTenant;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Override del alias `guest` para que el redirect post-guest sea
        // role-aware: landlord → /admin, user → /dashboard. Sin esto,
        // un landlord autenticado que visite /login sería redirigido
        // a /dashboard (defaultRedirectUri del default), que no le
        // corresponde. El middleware vive en app/Http/Middleware/
        // RedirectIfAuthenticated.php — extiende el default de Laravel
        // y override del único método que computa el destino.
        $middleware->alias([
            'guest' => RedirectIfAuthenticated::class,
            'feature' => EnsureTenantHasFeature::class,
            'device.auth' => DeviceAuth::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->group('tenant', [
            NeedsTenant::class,
            EnsureValidTenantSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
