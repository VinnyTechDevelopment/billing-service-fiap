<?php

namespace App\Application\Billing\Services;

/**
 * URLs públicas (via API Gateway) que o cliente recebe por e-mail.
 */
class QuoteLinks
{
    public function __construct(private string $publicUrl) {}

    public function approve(string $token): string
    {
        return "{$this->base()}/api/billing/quotes/{$token}/approve";
    }

    public function reject(string $token): string
    {
        return "{$this->base()}/api/billing/quotes/{$token}/reject";
    }

    public function webhook(): string
    {
        return "{$this->base()}/api/billing/webhooks/mercadopago";
    }

    public function simulatePayment(string $paymentId): string
    {
        return "{$this->base()}/api/billing/payments/{$paymentId}/simulate";
    }

    private function base(): string
    {
        return rtrim($this->publicUrl, '/');
    }
}
