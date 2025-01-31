<?php

namespace Damms005\LaravelMultipay\Models;

use Illuminate\Support\Str;
use Illuminate\Foundation\Auth\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;
use Damms005\LaravelMultipay\Services\PaymentHandlers\BasePaymentHandler;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int $id
 * @property integer $user_id
 * @property string $product_id
 * @property integer $original_amount_displayed_to_user
 * @property string $transaction_currency
 * @property string $transaction_description
 * @property string $transaction_reference
 * @property string $payment_processor_name
 * @property integer $pay_item_id
 * @property string $processor_transaction_reference
 * @property string $processor_returned_response_code
 * @property string $processor_returned_card_number
 * @property ?string $processor_returned_response_description
 * @property string $processor_returned_amount
 * @property string $processor_returned_transaction_date
 * @property string $customer_checkout_ip_address
 * @property boolean|null $is_success
 * @property integer $retries_count
 * @property string $completion_url
 * @property ?array $metadata

 * @property ?User $user
 *
 */
class Payment extends Model
{
    use SoftDeletes;
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'metadata' => AsArrayObject::class,
    ];

    protected const TABLE_NAME = 'payments';
    public const KOBO_TO_NAIRA = 100;

    public function getTable(): string
    {
        $userDefinedTablePrefix = config('laravel-multipay.table_prefix');

        if ($userDefinedTablePrefix) {
            return $userDefinedTablePrefix . self::TABLE_NAME;
        }

        return self::TABLE_NAME;
    }

    public function user()
    {
        return $this->belongsTo(config('laravel-multipay.user_model_fqcn'), 'user_id', config('laravel-multipay.user_model_owner_key'));
    }

    public function scopeSuccessful($query)
    {
        $query->where('is_success', 1);
    }

    /**
     * Gets the payment provider/handler for this payment
     */
    public function getPaymentProvider(): BasePaymentHandler | PaymentHandlerInterface
    {
        $handler = Str::of(BasePaymentHandler::class)
            ->beforeLast("\\")
            ->append("\\")
            ->append($this->payment_processor_name)
            ->__toString();

        return new $handler();
    }

    public function getAmountInNaira()
    {
        if ($this->processor_returned_amount > 0) {
            return ((float) $this->processor_returned_amount) / 100;
        }

        return $this->processor_returned_amount;
    }

    public function getPayerName(): string
    {
        if ($this->user) {
            $nameProperty = config('laravel-multipay.user_model_properties.name');
            return $this->user->$nameProperty;
        }

        if (!isset($this->metadata['payer_name'])) {
            throw new \Exception("payer name not found in metadata and no user is associated with this payment");
        }

        return $this->metadata['payer_name'];
    }

    public function getPayerEmail(): string
    {
        if ($this->user) {
            $emailProperty = config('laravel-multipay.user_model_properties.email');
            return $this->user->$emailProperty;
        }

        if (!isset($this->metadata['payer_email'])) {
            throw new \Exception("payer email not found in metadata and no user is associated with this payment");
        }

        return $this->metadata['payer_email'];
    }

    public function getPayerPhone(): string
    {
        if ($this->user) {
            $phoneProperty = config('laravel-multipay.user_model_properties.phone');
            return $this->user->$phoneProperty;
        }

        if (!isset($this->metadata['payer_phone'])) {
            throw new \Exception("payer phone not found in metadata and no user is associated with this payment");
        }

        return $this->metadata['payer_phone'];
    }
}
