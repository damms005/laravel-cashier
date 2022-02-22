<?php

namespace Damms005\LaravelCashier\Services\PaymentHandlers;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Damms005\LaravelCashier\Models\Payment;
use Damms005\LaravelCashier\Services\PaymentService;
use Damms005\LaravelCashier\Actions\CreateNewPayment;
use Damms005\LaravelCashier\Contracts\PaymentHandlerInterface;
use Damms005\LaravelCashier\Exceptions\UnknownWebhookException;
use Damms005\LaravelCashier\Events\SuccessfulLaravelCashierPaymentEvent;
use Damms005\LaravelCashier\Exceptions\NonActionableWebhookPaymentException;

class BasePaymentHandler
{
    public const WEBHOOK_OKAY = 'OK';

    /**
     * The Payment Handler processing this transaction
     *
     * @var PaymentHandlerInterface
     */
    protected $paymentHandlerInterface;

    /**
     * The Payment being made
     *
     * @var Payment
     */
    protected $payment;

    public function __construct()
    {
        $defaultPaymentHandler = config('laravel-cashier.default_payment_handler_fqcn');

        if (empty($defaultPaymentHandler)) {
            throw new \Exception("Payment handler not specified");
        }

        $paymentHandlerInterface = new $defaultPaymentHandler();

        //ensure the class is registered, so we are sure we will
        //have a handler when payment gateway server returns response
        if (!collect(self::getNamesOfPaymentHandlers())->contains($paymentHandlerInterface->getUniquePaymentHandlerName())) {
            throw new \Exception("Unregistered payment handler: {$paymentHandlerInterface->getUniquePaymentHandlerName()}", 1);
        }

        $this->paymentHandlerInterface = $paymentHandlerInterface;
    }

    /**
     * @return fqcn string[]
     */
    public static function getNamesOfPaymentHandlers()
    {
        return collect([

            //IMPORTANT: IF YOU NEED CONSTRUCTORS IN ANY OF CLASSES, ENSURE
            //THAT THE CONSTRUCTOR(S) DO NOT ACCEPT ANY PARAMETER
            //this is because to handle payment provider response, we
            //currently new-up instances of these classes without constructors.

            // Flutterwave::class,
            // Paystack::class,
            // Interswitch::class,
            // UnifiedPayments::class,
            Remita::class,
        ])
            ->map(function (string $paymentHandlerFqcn) {
                /** @var PaymentHandlerInterface */
                $paymentHandler = new $paymentHandlerFqcn();

                return $paymentHandler->getUniquePaymentHandlerName();
            });
    }

    /**
     * This is where it all starts. It is like the initialization phase. User gets a chance to see summary of
     * transaction details before the payment handler proceeds to process the transaction.
     * Here is also where user gets the chance to save/note/print the
     * transaction reference/summary before proceed to payment.
     * This method should also create a record for this transaction in the database payments table.
     *
     */
    public function storePaymentAndShowUserBeforeProcessing(int $user_id, $original_amount_displayed_to_user, string $transaction_description, $currency, string $transaction_reference, string $completion_url = null, Request $optionalRequestForEloquentModelLinkage = null, $preferredView = null, $metadata = null)
    {
        $payment = (new CreateNewPayment())->execute(
            $this->paymentHandlerInterface->getUniquePaymentHandlerName(),
            $user_id,
            $completion_url,
            $transaction_reference,
            $currency,
            $transaction_description,
            $original_amount_displayed_to_user,
            $metadata
        );

        if ($this->paymentHandlerInterface->getUniquePaymentHandlerName() == UnifiedPayments::getUniquePaymentHandlerName()) {
            $payment->customer_checkout_ip_address = request()->ip();
            $payment->save();
        }

        $post_payment_confirmation_submit = route('payment.confirmation.submit');

        if ($optionalRequestForEloquentModelLinkage) {
            $this->linkPaymentToEloquentModel($optionalRequestForEloquentModelLinkage, $payment);
        }

        $instructions = "The details of your transaction is given below. Kindly print this page first before proceeding to click on Pay Now (this ensures that you have your transaction reference in case you need to refer to this transaction in the future).";

        $exports = compact('instructions', 'currency', 'payment', 'post_payment_confirmation_submit', 'user_id');

        if (empty($preferredView)) {
            return view('laravel-cashier::generic-confirm_transaction', $exports);
        } else {
            return view($preferredView, $exports);
        }
    }

    public function sendTransactionToPaymentGateway(Payment $payment, $callback_url)
    {
        return $this->paymentHandlerInterface->renderAutoSubmittedPaymentForm($payment, $callback_url);
    }

    /**
     * Processes the outcome returned by payment gateway and return a view/response with details
     * of the transaction (e.g. successful, fail, etc.)
     *
     * @param Request $paymentGatewayServerResponse
     */
    public static function handleServerResponseForTransactionAndDisplayOutcome(Request $paymentGatewayServerResponse, PaymentService $paymentService)
    {
        /**
         * @var Payment
         */
        $payment = null;

        collect(self::getNamesOfPaymentHandlers())
            ->each(function (string $paymentHandlerName) use ($paymentGatewayServerResponse, $paymentService, &$payment) {
                $paymentHandler = $paymentService->getPaymentHandlerByName($paymentHandlerName);
                $payment = $paymentHandler->confirmResponseCanBeHandledAndUpdateDatabaseWithTransactionOutcome($paymentGatewayServerResponse);

                if ($payment) {
                    if ($payment->is_success == 1) {
                        event(new SuccessfulLaravelCashierPaymentEvent($payment));
                    }

                    return false;
                }
            });

        [$paymentDescription, $isJsonDescription] = self::getPaymentDescription($payment);

        return view('laravel-cashier::transaction-completed', compact('payment', 'isJsonDescription', 'paymentDescription'));
    }

    /**
     * @return array [paymentDescription, isJsonDescription]
     */
    protected static function getPaymentDescription(?Payment $payment): array
    {
        if (is_null($payment)) {
            return ['', false];
        }

        $paymentDescription = json_decode($payment->processor_returned_response_description, true);
        $isJsonDescription = !is_null($paymentDescription);

        if (is_array($paymentDescription)) {
            $paymentDescription = collect($paymentDescription)
                ->mapWithKeys(function ($item, $key) {
                    $humanReadableKey = Str::of($key)
                        ->snake()
                        ->title()
                        ->replace("_", " ")
                        ->__toString();

                    return [$humanReadableKey => $item];
                })
                ->toArray();
        }

        return [$paymentDescription, $isJsonDescription];
    }

    /**
     * Gets the payment provider/handler for the specified payment
     */
    public function getHandlerForPayment(Payment $payment): BasePaymentHandler | PaymentHandlerInterface
    {
        return $payment->getPaymentProvider();
    }

    /**
     * @see Damms005\LaravelCashier\Contracts\PaymentHandlerInterface::reQuery()
     */
    public function reQueryUnsuccessfulPayment(Payment $unsuccessfulPayment): bool
    {
        $handler = $unsuccessfulPayment->getPaymentProvider();

        $payment = $handler->reQuery($unsuccessfulPayment);

        if ($payment == null) {
            return false;
        }

        $isSuccessFulPayment = $payment->is_success == 1;

        if ($isSuccessFulPayment) {
            event(new SuccessfulLaravelCashierPaymentEvent($payment));
        }

        return $isSuccessFulPayment;
    }

    public function paymentCompletionWebhookHandler(Request $request)
    {
        $payment = $this->processWebhook($request);

        return $payment ? self::WEBHOOK_OKAY : null;
    }

    protected function processWebhook(Request $request): ?Payment
    {
        /**
         * @var Payment
         */
        $payment = collect(self::getNamesOfPaymentHandlers())
            ->map(function (string $paymentHandlerName) use ($request) {
                $paymentHandler = PaymentService::getPaymentHandlerByName($paymentHandlerName);

                try {
                    $payment = $paymentHandler->handleExternalWebhookRequest($request);

                    if ($payment->is_success) {
                        event(new SuccessfulLaravelCashierPaymentEvent($payment));
                    }

                    return $payment;
                } catch (UnknownWebhookException $exception) {
                } catch (NonActionableWebhookPaymentException $exception) {
                    return false;
                }
            })
            ->filter()
            ->first();

        return $payment;
    }

    public function getPayment(string $transaction_reference): Payment
    {
        return Payment::where('transaction_reference', $transaction_reference)->firstOrFail();
    }

    public function getTransactedUser(string $transaction_reference)
    {
        return $this->getPayment($transaction_reference)->user;
    }

    private function linkPaymentToEloquentModel(Request $optionalRequestForEloquentModelLinkage, Payment $payment)
    {
        $validationEntries = [
            'update_model_success' => 'required:',
            'update_model_unique_column' => 'required_with:update_model_success',
            'update_model_unique_column_value' => 'required_with:update_model_unique_column',
        ];

        $optionalRequestForEloquentModelLinkage->validate(array_keys($validationEntries));

        $model = (new $optionalRequestForEloquentModelLinkage->update_model_success())
            ->where($optionalRequestForEloquentModelLinkage->update_model_unique_column, $optionalRequestForEloquentModelLinkage->update_model_unique_column_value)
            ->first();

        if ($model) {
            $model->update(['payment_id' => $payment->id]);
        }
    }

    public static function getUniquePaymentHandlerName(): string
    {
        return Str::of(static::class)->afterLast("\\");
    }
}
