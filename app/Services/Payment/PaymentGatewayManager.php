<?php

namespace App\Services\Payment;

use App\Services\Payment\Contracts\PaymentGatewayInterface;
use InvalidArgumentException;
use RuntimeException;

class PaymentGatewayManager
{
    /**
     * Resolve a gateway driver by name ('fawry', 'instapay').
     *
     * @throws InvalidArgumentException when the driver name is unknown.
     * @throws RuntimeException when the driver class is not implemented yet.
     */
    public function driver(string $gateway): PaymentGatewayInterface
    {
        $class = match (strtolower(trim($gateway))) {
            'fawry' => FawryPaymentService::class,
            'instapay' => InstaPayService::class,
            default => throw new InvalidArgumentException(
                "Unsupported payment gateway [{$gateway}]. Supported: ".implode(', ', self::available())
            ),
        };

        if (! class_exists($class)) {
            throw new RuntimeException("Payment gateway driver [{$class}] is not implemented yet.");
        }

        $driver = app($class);

        if (! $driver instanceof PaymentGatewayInterface) {
            throw new RuntimeException("Payment gateway driver [{$class}] must implement PaymentGatewayInterface.");
        }

        return $driver;
    }

    /**
     * @return array<int, string>
     */
    public static function available(): array
    {
        return ['fawry', 'instapay'];
    }
}
