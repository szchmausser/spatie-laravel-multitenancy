<?php

namespace App\Providers;

use App\Events\PaymentVerified;
use App\Listeners\ActivateSubscription;
use App\Services\Payment\BankTransferGateway;
use App\Services\Payment\PagoMovilGateway;
use App\Services\Payment\PaymentGatewayInterface;
use App\Services\Payment\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentGatewayInterface::class, PagoMovilGateway::class);

        $this->app->bind('payment.gateways', fn () => [
            'pago_movil' => app(PagoMovilGateway::class),
            'bank_transfer' => app(BankTransferGateway::class),
        ]);

        $this->app->bind(PaymentService::class, fn () => new PaymentService(
            gateways: $this->app->make('payment.gateways'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Auth::provider('multi-tenant', function ($app, array $config) {
            return new MultiTenantUserProvider($app['hash'], $config['model']);
        });

        Event::listen(PaymentVerified::class, ActivateSubscription::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
