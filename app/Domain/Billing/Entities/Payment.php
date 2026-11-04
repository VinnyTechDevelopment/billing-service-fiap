<?php

namespace App\Domain\Billing\Entities;

/**
 * Pagamento de um orçamento aprovado. O id interno vai como
 * external_reference para o Mercado Pago, que o devolve no webhook.
 */
class Payment
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_CANCELLED = 'cancelled';

    private const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_REFUNDED,
        self::STATUS_CANCELLED,
    ];

    public function __construct(
        public readonly string $id,
        public readonly string $quoteId,
        public readonly float $amount,
        public readonly string $provider,
        public string $status,
        public ?string $checkoutId = null,
        public ?string $checkoutUrl = null,
        public ?string $providerPaymentId = null,
        public ?string $paidAt = null,
        public ?string $failureReason = null,
    ) {
        if (! in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Status de pagamento invalido: {$status}");
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Valor do pagamento deve ser maior que zero.');
        }
    }

    public static function create(string $id, string $quoteId, float $amount, string $provider): self
    {
        return new self($id, $quoteId, round($amount, 2), $provider, self::STATUS_PENDING);
    }

    public function attachCheckout(string $checkoutId, string $checkoutUrl): void
    {
        $this->checkoutId = $checkoutId;
        $this->checkoutUrl = $checkoutUrl;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function approve(string $providerPaymentId, string $now): void
    {
        $this->assertPending();
        $this->status = self::STATUS_APPROVED;
        $this->providerPaymentId = $providerPaymentId;
        $this->paidAt = $now;
    }

    public function reject(?string $providerPaymentId, string $reason): void
    {
        $this->assertPending();
        $this->status = self::STATUS_REJECTED;
        $this->providerPaymentId = $providerPaymentId;
        $this->failureReason = $reason;
    }

    public function refund(): void
    {
        if ($this->status !== self::STATUS_APPROVED) {
            throw new \DomainException("Pagamento em '{$this->status}' nao pode ser estornado.");
        }

        $this->status = self::STATUS_REFUNDED;
    }

    public function cancel(): void
    {
        if ($this->isPending()) {
            $this->status = self::STATUS_CANCELLED;
        }
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'quote_id' => $this->quoteId,
            'amount' => $this->amount,
            'provider' => $this->provider,
            'status' => $this->status,
            'checkout_url' => $this->checkoutUrl,
            'provider_payment_id' => $this->providerPaymentId,
            'paid_at' => $this->paidAt,
            'failure_reason' => $this->failureReason,
        ];
    }

    private function assertPending(): void
    {
        if (! $this->isPending()) {
            throw new \DomainException("Pagamento ja processado (status: {$this->status}).");
        }
    }
}
