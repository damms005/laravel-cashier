<?php

namespace Damms005\LaravelMultipay;

use Illuminate\Support\ServiceProvider;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Services\PaymentService;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;
use Damms005\LaravelMultipay\Services\PaymentHandlers\BasePaymentHandler;

class LaravelMultipayServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../views', 'laravel-multipay');

        $this->publishes([__DIR__ . '/../config/laravel-multipay.php' => config_path('laravel-multipay.php')], 'laravel-multipay-config');

        $this->bootFlutterwave();

        $this->app->bind(BasePaymentHandler::class, function ($app) {
            $defaultPaymentHandler = config('laravel-multipay.default_payment_handler_fqcn');

            if (!$defaultPaymentHandler) {
                throw new \Exception('Please provide a default payment handler in the laravel-multipay.php config file');
            }

            return $app->make($defaultPaymentHandler);
        });

        $this->app->bind('laravel-multipay', function ($app) {
            return $app->make(BasePaymentHandler::class);
        });

        $this->app->bind(PaymentHandlerInterface::class, function ($app, $args) {
            /** @var Payment */
            $payment = $args[0];

            throw_if(!$payment instanceof Payment, "Laravel Multipay Error: only Payment can be resolved by this binding. Found: " . get_class($payment));

            return $payment->getPaymentProvider();
        });

        $this->app->bind(PaymentService::class, function ($app) {
            return new PaymentService();
        });
    }

    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/laravel-multipay.php',
            'laravel-multipay'
        );
    }

    public function bootFlutterwave()
    {
        config(['flutterwave.publicKey' => env('FLW_PUBLIC_KEY')]);
        config(['flutterwave.secretKey' => env('FLW_SECRET_KEY')]);
        config(['flutterwave.secretHash' => env('FLW_SECRET_HASH')]);
    }
}
