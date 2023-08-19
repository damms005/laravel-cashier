<?php

namespace Damms005\LaravelMultipay\Webhooks\Paystack;

use Illuminate\Http\Request;
use Damms005\LaravelMultipay\Webhooks\Contracts\WebhookHandler;

/**
 * Event name: charge.success
 * This is sent when the customer successfully makes a payment. It contains the transaction, customer, and card details.
 *
 * @see https://paystack.com/docs/terminal/push-payment-requests/#listen-to-notifications
 */
class ChargeSuccess implements WebhookHandler
{
    public function isHandlerFor(Request $webhookRequest)
    {
        return $webhookRequest->input('event') === 'charge.success';
    }
}
