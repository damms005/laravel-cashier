<?php

use Mockery\Mock;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Event;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\ValueObjects\ReQuery;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Remita;
use Damms005\LaravelMultipay\Services\PaymentHandlers\BasePaymentHandler;
use Damms005\LaravelMultipay\Events\SuccessfulLaravelMultipayPaymentEvent;

beforeEach(function () {
    $payment = createPayment();

    $payment->processor_transaction_reference = 12345;
    $payment->save();

    $this->payment = $payment;
});

// TODO: write these tests:
// it("ensures that submission of the form at /payments/test url does not fail", function () {});
// it("processes payment webhooks", function () {});

it('calls payment handler for payment re-query', function () {
    /**
     * @var Mock<TObject>
     */
    $mock = mock(Remita::class);
    $mock->makePartial();

    $mock->shouldReceive('reQuery')
        ->once()
        ->andReturn(
            new ReQuery(
                payment: new Payment(),
                responseDetails: ['status' => 'Successful'],
            ),
        );

    app()->bind(Remita::class, fn($app) => $mock);

    app()->make(BasePaymentHandler::class)->reQueryUnsuccessfulPayment(
        Payment::factory()->create(['payment_processor_name' => Remita::getUniquePaymentHandlerName()])
    );
});

it('fires success events for re-query of successful payments', function () {
    app()->bind(Remita::class, function () {
        /** @var Mock<TObject> */
        $mock = mock(Remita::class);
        $mock->makePartial();

        $mock->expects('reQuery')->andReturn(
            new ReQuery(
                payment: Payment::factory()->create([
                    'is_success' => true,
                    'transaction_reference' => Str::random(),
                    'payment_processor_name' => Remita::getUniquePaymentHandlerName(),
                ]),
                responseDetails: ['status' => 'Successful'],
            ),
        );

        return $mock;
    });

    Event::fake();

    app()->make(BasePaymentHandler::class)->reQueryUnsuccessfulPayment(
        Payment::factory()->create(['payment_processor_name' => Remita::getUniquePaymentHandlerName()])
    );

    Event::assertDispatched(SuccessfulLaravelMultipayPaymentEvent::class);
});

it('does not fire success events for re-query of unsuccessful payments', function () {
    app()->bind(Remita::class, function ($app) {
        /**
         * @var Mock<TObject>
         */
        $mock = mock(Remita::class);
        $mock->makePartial();

        $mock->shouldReceive('reQuery')
            ->once()
            ->andReturn(
                new ReQuery(
                    payment: new Payment(['is_success' => false]),
                    responseDetails: ['status' => 'Went South!'],
                ),
            );

        return $mock;
    });

    Event::fake();

    app()->make(BasePaymentHandler::class)->reQueryUnsuccessfulPayment(
        Payment::factory()->create(['payment_processor_name' => Remita::getUniquePaymentHandlerName()])
    );

    Event::assertNotDispatched(SuccessfulLaravelMultipayPaymentEvent::class);
});
