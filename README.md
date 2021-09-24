# An opinionated Laravel package for handling payments in a Laravel package

[![Latest Version on Packagist](https://img.shields.io/packagist/v/damms005/laravel-cashier.svg?style=flat-square)](https://packagist.org/packages/damms005/laravel-cashier)
[![GitHub Tests Action Status](https://img.shields.io/github/workflow/status/damms005/laravel-cashier/run-tests?label=tests)](https://github.com/damms005/laravel-cashier/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/workflow/status/damms005/laravel-cashier/Check%20&%20fix%20styling?label=code%20style)](https://github.com/damms005/laravel-cashier/actions?query=workflow%3A"Check+%26+fix+styling"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/damms005/laravel-cashier.svg?style=flat-square)](https://packagist.org/packages/damms005/laravel-cashier)

Whether you want to quickly bootstrap payment processing for your Laravel applications, or you want a way to test supported payment processors, this package's got you covered.
Being opinionated, it comes with [Tailwindcss-powered](http://tailwindcss.com/) blade views, so that you can simply Plug-and-play™️.

Currently, this package supports the following online payment processors/handler

-   [Paystack](https://paystack.com)
-   [Flutterwave](https://flutterwave.com)
-   [Interswitch](https://www.interswitchgroup.com)
-   [UnifiedPayments](https://unifiedpayments.com)

## Installation

You can install the package via composer:

```bash
composer require damms005/laravel-cashier
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --provider="Damms005\LaravelCashier\LaravelCashierServiceProvider" --tag="laravel-cashier-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --provider="Damms005\LaravelCashier\LaravelCashierServiceProvider" --tag="laravel-cashier-config"
```

## Usage

### Step 1

Send a `POST` request to `route(payment.show_transaction_details_for_user_confirmation)`.
Check [InitiatePaymentRequest](src/Http/Requests/InitiatePaymentRequest.php) to know the values you are to post to this endpoint

### Step 2

Upon user confirmation of transaction, user is redirected to the appropriate payment gateway

The `ASuccessfulPaymentWasMade` event will be fired whenever a successful payment occurs

```php
$laravelCashier = new Damms005\LaravelCashier();
echo $laravelCashier->echoPhrase('Hello, Damms005!');
```

## Testing

```bash
composer test
```

## Credits

This package is made possible by the nice works done by the following awesome projects:

-   [yabacon/paystack-php](https://github.com/yabacon/paystack-php)
-   [kingflamez/laravelrave](https://github.com/kingflamez/laravelrave)
-   [Tailwindcss](https://tailwindcss.com)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
