<?php

namespace Damms005\LaravelMultipay\Services\PaymentHandlers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\User;
use Yabacon\Paystack as PaystackHelper;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Models\PaymentPlan;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;
use Damms005\LaravelMultipay\Exceptions\UnknownWebhookException;

class Paystack extends BasePaymentHandler implements PaymentHandlerInterface
{
    protected $secret_key;

    public function __construct()
    {
        $this->secret_key = config("laravel-multipay.paystack_secret_key");

        if (empty($this->secret_key)) {
            // Paystack is currently the default payment handler (because
            // it is the easiest to setup and get up-and-running for starters/testing). Hence,
            // let the error message be contextualized, so we have a better UX for testers/first-timers
            if ($this->isDefaultPaymentHandler()) {
                throw new \Exception("You set Paystack as your default payment handler, but no Paystack Sk found. Please provide SK for Paystack.");
            }
        }
    }

    public function proceedToPaymentGateway(Payment $payment, $redirect_or_callback_url, $getFormForTesting = true): mixed
    {
        $transaction_reference = $payment->transaction_reference;

        return $this->sendUserToPaymentGateway($redirect_or_callback_url, $this->getPayment($transaction_reference));
    }

    /**
     * This is a get request. (https://developers.paystack.co/docs/paystack-standard#section-4-verify-transaction)
     *
     * @param Request $request
     *
     * @return Payment
     */
    public function confirmResponseCanBeHandledAndUpdateDatabaseWithTransactionOutcome(Request $request): ?Payment
    {
        if (!$request->has('reference')) {
            return null;
        }

        return $this->processValueForTransaction($request->reference);
    }

    /**
     * For Paystack, this is a get request. (https://developers.paystack.co/docs/paystack-standard#section-4-verify-transaction)
     */
    public function processValueForTransaction(string $transactionReferenceIdNumber): ?Payment
    {
        throw_if(empty($transactionReferenceIdNumber));

        $trx = $this->getPaystackTransaction($transactionReferenceIdNumber);

        // status should be true if there was a successful call
        if (!$trx->status) {
            exit($trx->message);
        }

        $payment = Payment::where('processor_transaction_reference', $transactionReferenceIdNumber)->firstOrFail();

        if ('success' == $trx->data->status) {
            if ($payment->payment_processor_name != $this->getUniquePaymentHandlerName()) {
                return null;
            }

            $this->giveValue($transactionReferenceIdNumber, $trx);

            $payment->refresh();
        } else {
            $payment->update([
                'is_success' => 0,
                'processor_returned_response_description' => $trx->data->gateway_response,
            ]);
        }

        return $payment;
    }

    public function reQuery(Payment $existingPayment): ?Payment
    {
        throw new \Exception("No requery implementation for Paystack");
    }

    /**
     * @see \Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface::handleExternalWebhookRequest
     */
    public function handleExternalWebhookRequest(Request $request): Payment
    {
        throw new UnknownWebhookException($this);
    }

    public function getHumanReadableTransactionResponse(Payment $payment): string
    {
        return '';
    }

    public function convertResponseCodeToHumanReadable($responseCode): string
    {
        return "";
    }

    protected function getPaystackTransaction($paystackReference)
    {
        // Confirm that reference has not already gotten value
        // This would have happened most times if you handle the charge.success event.
        $paystack = app()->make(PaystackHelper::class, ['secret_key' => $this->secret_key]);

        // the code below throws an exception if there was a problem completing the request,
        // else returns an object created from the json response
        // (full sample verify response is here: https://developers.paystack.co/docs/verifying-transactions)

        return $paystack->transaction->verify(['reference' => $paystackReference]);
    }

    protected function convertAmountToValueRequiredByPaystack($original_amount_displayed_to_user)
    {
        return $original_amount_displayed_to_user * 100; //paystack only accept amount in kobo/lowest denomination of target currency
    }

    protected function sendUserToPaymentGateway(string $redirect_or_callback_url, Payment $payment)
    {
        $paystack = app()->make(PaystackHelper::class, ['secret_key' => $this->secret_key]);

        // the code below throws an exception if there was a problem completing the request,
        // else returns an object created from the json response
        $trx = $paystack->transaction->initialize(
            [
                'email' => $payment->getPayerEmail(),
                'amount' => $this->convertAmountToValueRequiredByPaystack($payment->original_amount_displayed_to_user),
                'callback_url' => $redirect_or_callback_url,
            ]
        );

        // status should be true if there was a successful call
        if (!$trx->status) {
            exit($trx->message);
        }

        $payment = Payment::where('transaction_reference', $payment->transaction_reference)
            ->firstOrFail();

        $metadata = is_null($payment->metadata) ? [] : (array)$payment->metadata;

        $payment->update([
            'processor_transaction_reference' => $trx->data->reference,
            'metadata' => array_merge($metadata, [
                'paystack_authorization_url' => $trx->data->authorization_url
            ]),
        ]);

        // full sample initialize response is here: https://developers.paystack.co/docs/initialize-a-transaction
        // Get the user to click link to start payment or simply redirect to the url generated
        return redirect()->away($trx->data->authorization_url);
    }

    protected function giveValue($paystackReference, $paystackResponse)
    {
        Payment::where('processor_transaction_reference', $paystackReference)
            ->firstOrFail()
            ->update([
                "is_success" => 1,
                "processor_returned_amount" => $paystackResponse->data->amount,
                "processor_returned_transaction_date" => new Carbon($paystackResponse->data->created_at),
                'processor_returned_response_description' => $paystackResponse->data->gateway_response,
            ]);
    }

    public function paymentIsUnsettled(Payment $payment): bool
    {
        return is_null($payment->is_success);
    }

    public function resumeUnsettledPayment(Payment $payment): mixed
    {
        if (!array_key_exists('paystack_authorization_url', (array)$payment->metadata)) {
            throw new \Exception("Attempt was made to resume a Paystack payment that does not have payment URL. Payment id is {$payment->id}");
        }

        return redirect()->away($payment->metadata['paystack_authorization_url']);
    }

    public function createPaymentPlan(string $name, string $amount, string $interval, string $description, string $currency): string
    {
        $paystack = app()->make(PaystackHelper::class, ['secret_key' => $this->secret_key]);

        $paystack->plan->create([
            'name' => $name,
            'amount' => $amount, // in lowest denomination. e.g. kobo
            'interval' => $interval, // hourly, daily, weekly, monthly, quarterly, biannually (every 6 months) and annually
            'description' => $description,
            'currency' => $currency, // Allowed values are NGN, GHS, ZAR or USD
        ]);
    }

    public function subscribeToPlan(User $user, PaymentPlan $subscriptionPlan)
    {
    }
}
